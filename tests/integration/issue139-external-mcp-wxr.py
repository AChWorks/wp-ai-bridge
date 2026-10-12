#!/usr/bin/env python3
"""Independent HTTPS OAuth JSON-RPC client for private WXR send/receive.

Disposable localhost-only fixture. Never talks to production or logs secrets.
"""
import base64
import hashlib
import http.client
import json
import ssl
import sys

assert len(sys.argv) == 3
port = int(sys.argv[1])
with open(sys.argv[2], encoding="utf-8") as handle:
    token = handle.read().strip()
assert len(token) >= 20
# Only the test's dynamically-bound loopback endpoint uses a disposable cert.
context = ssl._create_unverified_context()
route = "/wp-json/wp-ai-bridge/v1/mcp"
session = ""
sequence = 0
max_wire_bytes = 0


def rpc(method, params=None, notify=False):
    global sequence, session, max_wire_bytes
    sequence += 1
    data = {"jsonrpc": "2.0", "method": method}
    if not notify:
        data["id"] = sequence
    if params is not None:
        data["params"] = params
    headers = {
        "Authorization": "Bearer " + token,
        "Content-Type": "application/json",
        "Accept": "application/json, text/event-stream",
        "MCP-Protocol-Version": "2025-11-25",
    }
    if session:
        headers["Mcp-Session-Id"] = session
    client = http.client.HTTPSConnection("localhost", port, context=context, timeout=60)
    try:
        client.request("POST", route, body=json.dumps(data, separators=(",", ":")).encode(), headers=headers)
        response = client.getresponse()
        code, wire = response.status, response.read()
        reply_headers = {key.lower(): value for key, value in response.getheaders()}
    finally:
        client.close()
    if notify and code == 202:
        return {}, reply_headers
    assert code == 200, "authenticated HTTPS MCP route failed with HTTP %d" % code
    max_wire_bytes = max(max_wire_bytes, len(wire))
    raw = wire.decode("utf-8")
    if raw.startswith("event:") or raw.startswith("data:"):
        messages = [line[5:].strip() for line in raw.splitlines() if line.startswith("data:")]
        assert messages, "MCP SSE response lacks data"
        raw = messages[-1]
    return json.loads(raw), reply_headers


init, h = rpc("initialize", {
    "protocolVersion": "2025-11-25", "capabilities": {},
    "clientInfo": {"name": "wpai139-external-standards-client", "version": "1.0"},
})
assert init.get("result", {}).get("protocolVersion") == "2025-11-25"
session = h.get("mcp-session-id", "")
assert session, "authenticated MCP session ID missing"
rpc("notifications/initialized", notify=True)
catalog, _ = rpc("tools/list", {})
assert "mcp-adapter-execute-ability" in {tool["name"] for tool in catalog.get("result", {}).get("tools", [])}


def ability(name, params, expected_success=True):
    reply, _ = rpc("tools/call", {
        "name": "mcp-adapter-execute-ability",
        "arguments": {"ability_name": name, "parameters": params},
    })
    content = reply.get("result", {})
    payload = content.get("structuredContent", {})
    ok = not content.get("isError") and payload.get("success") is True
    if expected_success:
        if not ok:
            # Only the Adapter's bounded error envelope, NEVER source bytes,
            # tokens, media, WordPress content or filesystem paths.
            reason = str(content.get("content", [{"text": "unspecified"}])[0].get("text", "unspecified"))[:320]
            raise AssertionError("authenticated WordPress Ability failed: %s: %s" % (name, reason))
        return payload["data"]
    assert not ok, "unauthorized or wrong-purpose WXR Ability succeeded: %s" % name
    return {}


outbound = None
inbound = None
try:
    created = ability("wp-ai-bridge/wxr-export-create", {"content_type": "post"})
    outbound = created["transfer_id"]
    assert len(outbound) == 64 and created["content_type"] == "post" and created["state"] == "verified"
    length, digest = int(created["total_bytes"]), created["sha256"]
    assert length > 128 * 1024 and len(digest) == 64
    received = bytearray()
    windows = 0
    while len(received) < length:
        item = ability("wp-ai-bridge/private-transfer", {
            "action": "read", "transfer_id": outbound,
            "offset": len(received), "max_bytes": 24000,
        })
        raw = base64.b64decode(item["content_base64"], validate=True)
        assert raw and len(raw) + len(received) == int(item["read_next_offset"])
        assert item["chunk_sha256"] == hashlib.sha256(raw).hexdigest()
        received.extend(raw)
        windows += 1
        assert windows < 500, "unexpected unbounded WXR windows"
    assert len(received) == length and hashlib.sha256(received).hexdigest() == digest
    assert b"<rss " in received and b"wp:wxr_version" in received
    assert "سلام وردپرس".encode("utf-8") in received
    print("PASS: external HTTPS OAuth WXR received bytes=%d windows=%d exact_SHA256=yes." % (length, windows))

    ability("wp-ai-bridge/wxr-import-preview", {"transfer_id": outbound}, expected_success=False)
    policy = ability("wp-ai-bridge/private-transfer", {"action": "limits"})
    assert int(policy["max_file_bytes"]) >= length
    staged = ability("wp-ai-bridge/private-transfer", {
        "action": "begin", "purpose": "wxr",
        "total_bytes": length, "sha256": digest,
    })
    inbound = staged["transfer_id"]
    offset, chunks = 0, 0
    window = min(24000, int(staged["max_chunk_bytes"]))
    assert window > 0
    while offset < length:
        part = bytes(received[offset:offset + window])
        ack = ability("wp-ai-bridge/private-transfer", {
            "action": "append", "transfer_id": inbound,
            "offset": offset, "content_base64": base64.b64encode(part).decode("ascii"),
        })
        offset += len(part)
        chunks += 1
        assert int(ack["received_bytes"]) == offset
    verified = ability("wp-ai-bridge/private-transfer", {
        "action": "verify", "transfer_id": inbound,
    })
    assert verified["state"] == "verified"
    preview = ability("wp-ai-bridge/wxr-import-preview", {"transfer_id": inbound})
    assert preview["content_sha256"] == digest
    assert int(preview["content_bytes"]) == length
    assert int(preview["item_count"]) >= 1
    assert preview["mutation_performed"] is False
    assert preview["authoritative_plan"] is False
    print("PASS: external HTTPS OAuth WXR uploaded chunks=%d; native read-only preview count=%d; no WordPress mutation." %
          (chunks, preview["item_count"]))

    # New public effect route: a separate-process external client consents to
    # updating only exact preexisting disposable fixture objects. No new
    # WordPress users or remote file downloads can be authorized here.
    author_choice = {"transfer_id": inbound, "on_conflict": "allow_existing"}
    agreed = ability("wp-ai-bridge/wxr-import-plan", author_choice)
    assert agreed["content_sha256"] == digest
    assert agreed["item_count"] >= 1
    assert agreed["conflict_count"] >= 1
    assert len(agreed["existing_targets"]) == agreed["conflict_count"]
    assert agreed["fetch_attachments"] is False and agreed["create_users"] is False
    assert 0 <= agreed["publish_candidates"] <= agreed["item_count"]
    importer = ability("wp-ai-bridge/wxr-import-execute", {
        **author_choice, "plan_token": agreed["plan_token"], "confirm": True
    })
    assert importer["transfer_id"] == inbound
    assert importer["native_provider_returned"] is True
    assert importer["processed_posts"] == agreed["item_count"]
    assert importer["created_users"] is False
    assert importer["fetched_attachments"] is False
    native_state = ability("wp-ai-bridge/private-transfer", {
        "action": "status", "transfer_id": inbound,
    })
    assert native_state["outcome"] == "provider_returned"
    assert native_state["state"] == "committed"
    assert native_state["import_processed"] == agreed["item_count"]
    ability("wp-ai-bridge/wxr-import-execute", {
        **author_choice, "plan_token": agreed["plan_token"], "confirm": True
    }, expected_success=False)
    print("PASS: separate-process HTTPS/OAuth WXR native Importer accepted explicit exact existing-target consent, durable nonreplay receipt, no external media/users.")
    print("MEASURE: largest_actual_MCP_HTTP_response_bytes=%d." % max_wire_bytes)
finally:
    if inbound:
        # After claim, release() retains claim fence and receipt.
        status = ability("wp-ai-bridge/private-transfer", {
            "action": "status", "transfer_id": inbound,
        })
        action = "release" if status["state"] in ("committing", "committed", "observed", "rejected") else "cancel"
        ability("wp-ai-bridge/private-transfer", {
            "action": action, "transfer_id": inbound,
        })
    if outbound:
        ability("wp-ai-bridge/private-transfer", {
            "action": "cancel", "transfer_id": outbound,
        })
print("PASS: external standard MCP WXR producer/consumer data-plane, integrity and private cleanup.")
