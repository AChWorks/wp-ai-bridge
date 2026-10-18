#!/usr/bin/env python3
"""Independent HTTPS OAuth/MCP client: genuine Media file ingress and egress.

Runs ONLY against a fresh, disposable, loopback-bound WordPress container.
No Gateway, production site, filesystem shortcut, client-specific adapter,
or native ChatGPT-downloadable attachment is involved.
"""
import base64
import hashlib
import http.client
import io
import json
import secrets
import ssl
import sys
import zipfile

assert len(sys.argv) == 3, "Requires disposable HTTPS port and token file"
port = int(sys.argv[1])
assert 0 < port < 65536
with open(sys.argv[2], encoding="utf-8") as handle:
    token = handle.read().strip()
assert len(token) >= 20

# One-day self-signed certificate on localhost in this disposable test only.
context = ssl._create_unverified_context()
route = "/wp-json/wp-ai-bridge/v1/mcp"
session = ""
seq = 0
max_wire = 0


def rpc(method, params=None, notify=False):
    global seq, session, max_wire
    seq += 1
    request = {"jsonrpc": "2.0", "method": method}
    if not notify:
        request["id"] = seq
    if params is not None:
        request["params"] = params
    headers = {
        "Authorization": "Bearer " + token,
        "Content-Type": "application/json",
        "Accept": "application/json, text/event-stream",
        "MCP-Protocol-Version": "2025-11-25",
    }
    if session:
        headers["Mcp-Session-Id"] = session
    conn = http.client.HTTPSConnection("localhost", port, context=context, timeout=45)
    try:
        conn.request("POST", route, body=json.dumps(request, separators=(",", ":")).encode("utf-8"), headers=headers)
        response = conn.getresponse()
        code, wire = response.status, response.read(1100000)
        reply_headers = {key.lower(): value for key, value in response.getheaders()}
    finally:
        conn.close()
    if notify and code == 202:
        return {}, reply_headers
    assert code == 200, "Authenticated HTTPS MCP route returned unexpected HTTP status %d" % code
    assert 0 < len(wire) < 1100000, "MCP response exceeds test transport safety budget"
    max_wire = max(max_wire, len(wire))
    body = wire.decode("utf-8")
    if body.startswith("event:") or body.startswith("data:"):
        events = [line[5:].strip() for line in body.splitlines() if line.startswith("data:")]
        assert events, "MCP SSE response had no event data"
        body = events[-1]
    return json.loads(body), reply_headers


def ability(name, params, expect_success=True):
    result, _ = rpc("tools/call", {
        "name": "mcp-adapter-execute-ability",
        "arguments": {"ability_name": name, "parameters": params},
    })
    envelope = result.get("result", {})
    structured = envelope.get("structuredContent", {})
    success = not envelope.get("isError") and structured.get("success") is True
    if expect_success:
        if not success:
            # Synthetic localhost-only fixture: show only the bounded native
            # WordPress diagnostic text, never credentials or source bytes.
            import re
            entries = envelope.get("content", [])
            first = entries[0] if isinstance(entries, list) and entries else {}
            summary = first.get("text", "unspecified") if isinstance(first, dict) else "unspecified"
            summary = re.sub(r"https?://\S+", "[URL]", str(summary))
            summary = re.sub(r"(?:/var|/home|/tmp|/srv)/\S+", "[PATH]", summary)
            summary = re.sub(r"Bearer\s+\S+", "Bearer [REDACTED]", summary, flags=re.I)
            summary = summary[:240]
            raise AssertionError("Bridge refused %s: isError=%s; native_result=%s" % (
                name, bool(envelope.get("isError")), summary))
        assert isinstance(structured.get("data"), dict), "Bridge returned an unexpected non-object contract"
        return structured["data"]
    assert not success, "Bridge accepted an explicitly disallowed %s operation" % name
    return {}


def stage(data):
    digest = hashlib.sha256(data).hexdigest()
    begin = ability("wp-ai-bridge/private-transfer", {
        "action": "begin", "purpose": "media", "total_bytes": len(data), "sha256": digest,
    })
    lease = begin["transfer_id"]
    assert isinstance(lease, str) and len(lease) == 64
    assert begin["purpose"] == "media" and begin["state"] == "open"
    assert int(begin["total_bytes"]) == len(data) and begin["sha256"] == digest
    max_chunk = min(24000, int(begin["max_chunk_bytes"]))
    assert max_chunk > 0
    offset = 0
    windows = 0
    while offset < len(data):
        chunk = data[offset:offset + max_chunk]
        reply = ability("wp-ai-bridge/private-transfer", {
            "action": "append", "transfer_id": lease,
            "offset": offset, "content_base64": base64.b64encode(chunk).decode("ascii"),
        })
        offset += len(chunk)
        assert reply["transfer_id"] == lease and int(reply["received_bytes"]) == offset
        windows += 1
        assert windows < 300
    verify = ability("wp-ai-bridge/private-transfer", {"action": "verify", "transfer_id": lease})
    assert verify["transfer_id"] == lease and verify["purpose"] == "media"
    assert verify["state"] == "verified" and verify["complete"] is True
    assert int(verify["total_bytes"]) == len(data) and int(verify["received_bytes"]) == len(data)
    assert verify["sha256"] == digest
    # Inbound private bytes cannot be turned into a read/export channel.
    ability("wp-ai-bridge/private-transfer", {
        "action": "read", "transfer_id": lease, "offset": 0, "max_bytes": 12,
    }, expect_success=False)
    return lease, digest, windows


def snapshot_media(media_id, expected):
    digest = hashlib.sha256(expected).hexdigest()
    export = ability("wp-ai-bridge/transfer-media-export", {"media_id": media_id})
    lease = export["transfer_id"]
    assert len(lease) == 64 and export["media_id"] == media_id
    assert export["state"] == "verified" and int(export["total_bytes"]) == len(expected)
    assert export["sha256"] == digest
    received = bytearray()
    windows = 0
    while len(received) < len(expected):
        item = ability("wp-ai-bridge/private-transfer", {
            "action": "read", "transfer_id": lease,
            "offset": len(received), "max_bytes": 24000,
        })
        raw = base64.b64decode(item["content_base64"], validate=True)
        assert raw and item["transfer_id"] == lease
        assert int(item["read_offset"]) == len(received)
        assert int(item["read_next_offset"]) == len(received) + len(raw)
        assert item["chunk_sha256"] == hashlib.sha256(raw).hexdigest()
        received.extend(raw)
        windows += 1
        assert windows < 300
        assert item["read_complete"] is (len(received) == len(expected))
    assert len(received) == len(expected) and hashlib.sha256(received).hexdigest() == digest
    assert bytes(received) == expected, "Client received different original bytes"
    ability("wp-ai-bridge/private-transfer", {"action": "cancel", "transfer_id": lease})
    return windows


def inline_media_read(media_id, expected):
    digest = hashlib.sha256(expected).hexdigest()
    info = ability("wp-ai-bridge/file-bytes-read", {
        "action": "inspect", "source_type": "media", "source_id": media_id,
    })
    assert int(info["total_bytes"]) == len(expected) and info["content_sha256"] == digest
    snapshot = info["snapshot"]
    assembled = bytearray()
    windows = 0
    while len(assembled) < len(expected):
        item = ability("wp-ai-bridge/file-bytes-read", {
            "action": "chunk", "source_type": "media", "source_id": media_id,
            "offset": len(assembled), "max_bytes": 24000,
            "expected_snapshot": snapshot, "expected_sha256": digest,
        })
        part = base64.b64decode(item["content_base64"], validate=True)
        assert part and item["snapshot"] == snapshot
        assert int(item["offset"]) == len(assembled)
        assert int(item["next_offset"]) == len(assembled) + len(part)
        assembled.extend(part)
        windows += 1
        assert windows < 300
        assert item["complete"] is (len(assembled) == len(expected))
    assert bytes(assembled) == expected and hashlib.sha256(assembled).hexdigest() == digest
    return windows


init, meta = rpc("initialize", {
    "protocolVersion": "2025-11-25", "capabilities": {},
    "clientInfo": {"name": "wpai134-plain-external-media-client", "version": "1.0"},
})
assert init.get("result", {}).get("protocolVersion") == "2025-11-25"
session = meta.get("mcp-session-id", "")
assert session, "Native authenticated MCP session ID missing"
rpc("notifications/initialized", notify=True)
tools, _ = rpc("tools/list", {})
assert "mcp-adapter-execute-ability" in {tool["name"] for tool in tools["result"]["tools"]}

policy = ability("wp-ai-bridge/private-transfer", {"action": "limits"})
assert int(policy["max_file_bytes"]) >= 512 * 1024
assert int(policy["max_chunk_bytes"]) >= 16384

png = base64.b64decode("iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aX1kAAAAASUVORK5CYII=", validate=True)
assert len(png) == 68
zip_buffer = io.BytesIO()
with zipfile.ZipFile(zip_buffer, mode="w", compression=zipfile.ZIP_DEFLATED) as zipped:
    zipped.writestr("binary.bin", secrets.token_bytes(440 * 1024))
    zipped.writestr("utf8.txt", "فارسی — Hello 🌍\n" * 256)
archive = zip_buffer.getvalue()
assert 440 * 1024 < len(archive) < 512 * 1024

for filename, source in (("wpai134-disposable.png", png), ("wpai134-disposable.zip", archive)):
    inbound, digest, sent_windows = stage(source)
    ability("wp-ai-bridge/transfer-media-import", {
        "transfer_id": inbound, "filename": "wpai134-not-executable.php",
    }, expect_success=False)
    publication = ability("wp-ai-bridge/transfer-media-import", {
        "transfer_id": inbound, "filename": filename,
    })
    assert publication["transfer_id"] == inbound and publication["state"] == "committed"
    assert int(publication["media_id"]) > 0
    assert int(publication["bytes"]) == len(source) and publication["sha256"] == digest
    media_id = int(publication["media_id"])
    receipt = ability("wp-ai-bridge/private-transfer", {
        "action": "status", "transfer_id": inbound,
    })
    assert receipt["state"] == "committed" and int(receipt["destination_id"]) == media_id
    assert receipt["sha256"] == digest and receipt["transfer_id"] == inbound
    # Native Media is one-way claimed before publication, not reimportable.
    ability("wp-ai-bridge/transfer-media-import", {
        "transfer_id": inbound, "filename": filename,
    }, expect_success=False)
    windows = snapshot_media(media_id, source)
    inline_windows = inline_media_read(media_id, source)
    ability("wp-ai-bridge/private-transfer", {"action": "release", "transfer_id": inbound})
    print("PASS: external HTTPS Bridge Media %s bytes=%d upload_windows=%d private_export_windows=%d inline_read_windows=%d SHA256=matched native_nonreplay=yes." % (
        filename.rsplit(".", 1)[-1], len(source), sent_windows, windows, inline_windows))

print("PASS: #134 true separate-process HTTPS/OAuth/MCP Bridge Media PNG+ZIP ingress, native publication, exact full-byte egress, no Gateway; max_MCP_response_bytes=%d." % max_wire)
