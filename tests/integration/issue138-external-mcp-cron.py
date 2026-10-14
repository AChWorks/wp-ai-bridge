#!/usr/bin/env python3
"""Isolated standards-based HTTPS MCP client; secrets never printed."""
import base64
import hashlib
import http.client
import io
import json
import os
import ssl
import sys
import zipfile

port = int(sys.argv[1])
with open(sys.argv[2], encoding="utf-8") as f:
    bearer = f.read().strip()
assert len(bearer) >= 20
route = "/wp-json/wp-ai-bridge/v1/mcp"
ssl_context = ssl._create_unverified_context()  # only for localhost disposable cert
session = ""
counter = 0


def call(method, params=None, notify=False):
    global counter
    counter += 1
    request = {"jsonrpc": "2.0", "method": method}
    if not notify:
        request["id"] = counter
    if params is not None:
        request["params"] = params
    headers = {
        "Authorization": "Bearer " + bearer,
        "Accept": "application/json, text/event-stream",
        "Content-Type": "application/json",
        "MCP-Protocol-Version": "2025-11-25",
    }
    if session:
        headers["Mcp-Session-Id"] = session
    client = http.client.HTTPSConnection("localhost", port, context=ssl_context, timeout=35)
    try:
        client.request("POST", route, body=json.dumps(request, separators=(",", ":")).encode(), headers=headers)
        response = client.getresponse()
        status = response.status
        body = response.read()
        response_headers = {k.lower(): v for k, v in response.getheaders()}
    finally:
        client.close()
    if notify and status == 202:
        return {}, response_headers, len(body)
    if status != 200:
        raise RuntimeError("MCP HTTP status %d for %s: %s" % (status, method, body[:300].decode("utf-8", "replace")))
    raw = body.decode("utf-8")
    if raw.startswith("event:") or raw.startswith("data:"):
        parts = [line[5:].strip() for line in raw.splitlines() if line.startswith("data:")]
        assert parts, "No SSE data content"
        raw = parts[-1]
    return json.loads(raw), response_headers, len(body)


init, headers, _ = call(
    "initialize",
    {"protocolVersion": "2025-11-25", "capabilities": {}, "clientInfo": {"name": "wpai138-standards-https-client", "version": "1.0"}},
)
assert init.get("result", {}).get("protocolVersion") == "2025-11-25", "MCP protocol initialization rejected"
session = headers.get("mcp-session-id", "")
assert session, "HTTP MCP session identifier missing"
call("notifications/initialized", notify=True)
catalog, _, _ = call("tools/list", {})
tools = {t["name"] for t in catalog.get("result", {}).get("tools", [])}
assert "mcp-adapter-execute-ability" in tools, "Native generic MCP tool missing"
print("PASS: external HTTPS MCP 2025-11-25 initialization, tool discovery and OAuth principal.")


def ability(name, arguments):
    value, _, wire = call(
        "tools/call",
        {"name": "mcp-adapter-execute-ability", "arguments": {"ability_name": name, "parameters": arguments}},
    )
    result = value.get("result", {})
    if result.get("isError") or not result.get("structuredContent", {}).get("success"):
        raise AssertionError("WordPress Ability failed over external HTTPS MCP: %s; %s" % (name, str(result)[:340]))
    return result["structuredContent"]["data"], wire



# The installed disposable MU provider is the only source of Hook/Arguments.
import time
provider = "fixture_external"
hook = "wpai138_external_cron_fixture"

seed, _ = ability("wp-ai-bridge/cron-events-read", {"action": "list", "hook": hook, "limit": 20})
print("CHECK: external Cron event count=%d, callback_registered=%s" % (seed.get("total", -1), [x.get("callback_registered") for x in seed.get("items", [])]))
profiles, _ = ability("wp-ai-bridge/cron-events-manage", {"action": "profiles"})
print("CHECK: external managed Cron profile count=%d IDs=%s" % (len(profiles.get('profiles', [])), [x.get('profile_id') for x in profiles.get('profiles', [])]))
matched = [x for x in profiles.get("profiles", []) if x.get("profile_id") == provider]
print("CHECK: external raw profile fields=%s" % (list(profiles.keys())))
assert len(matched) == 1 and matched[0]["hook"] == hook
assert matched[0]["actions"] == ["schedule", "reschedule", "unschedule"], "Provider opt-in unexpectedly broadened"
print("PASS: external HTTPS MCP Cron provider-only discovery.")

if seed.get("total", 0) == 1:
    dropped, _ = ability("wp-ai-bridge/cron-events-manage",
        {"action": "unschedule", "profile_id": provider, "event_id": seed["items"][0]["event_id"], "expected_snapshot": seed["snapshot"]})
    assert dropped["status"] == "unscheduled"
initial, _ = ability("wp-ai-bridge/cron-events-read", {"action": "list", "hook": hook, "limit": 20})
assert initial["total"] == 0, "Fixture starts with an existing WordPress Cron event"
snapshot = initial["snapshot"]
when = int(time.time()) + 7200
scheduled, _ = ability("wp-ai-bridge/cron-events-manage",
    {"action": "schedule", "profile_id": provider, "timestamp": when, "recurrence": "hourly", "expected_snapshot": snapshot})
assert scheduled["status"] == "scheduled" and len(scheduled["event_id"]) == 64, "No exact scheduled identity"
now, _ = ability("wp-ai-bridge/cron-events-read", {"action": "get", "event_id": scheduled["event_id"]})
assert now["items"][0]["recurrence"] == "hourly" and now["items"][0]["next_run_timestamp"] == when, "Core Cron event not committed"
print("PASS: external HTTPS MCP schedules and verifies one native recurring Cron event.")

def expected_failure(name, arguments):
    value, _, _ = call("tools/call",
        {"name": "mcp-adapter-execute-ability", "arguments": {"ability_name": name, "parameters": arguments}})
    result = value.get("result", {})
    assert result.get("isError"), "Dangerous Cron action was accepted: %s" % arguments.get("action", "")

expected_failure("wp-ai-bridge/cron-events-manage",
    {"action": "schedule", "profile_id": "arbitrary_hook", "timestamp": when + 10800,
    "recurrence": "hourly", "expected_snapshot": scheduled["snapshot"]})
expected_failure("wp-ai-bridge/cron-events-manage",
    {"action": "run_now", "profile_id": provider})
expected_failure("wp-ai-bridge/cron-events-manage",
    {"action": "schedule", "profile_id": provider, "timestamp": when + 10800,
    "recurrence": "hourly", "expected_snapshot": snapshot})
print("PASS: external HTTPS MCP denies arbitrary hook, targeted execution and stale state.")

rescheduled, _ = ability("wp-ai-bridge/cron-events-manage",
    {"action": "reschedule", "profile_id": provider, "event_id": scheduled["event_id"],
    "timestamp": when + 3600, "recurrence": "daily", "expected_snapshot": scheduled["snapshot"]})
assert rescheduled["status"] == "rescheduled" and rescheduled["event_id"] != scheduled["event_id"]
updated, _ = ability("wp-ai-bridge/cron-events-read", {"action": "get", "event_id": rescheduled["event_id"]})
assert updated["items"][0]["recurrence"] == "daily" and updated["items"][0]["next_run_timestamp"] == when + 3600
cancelled, _ = ability("wp-ai-bridge/cron-events-manage",
    {"action": "unschedule", "profile_id": provider, "event_id": rescheduled["event_id"], "expected_snapshot": rescheduled["snapshot"]})
assert cancelled["status"] == "unscheduled"
empty, _ = ability("wp-ai-bridge/cron-events-read", {"action": "list", "hook": hook, "limit": 20})
assert empty["total"] == 0, "Exact cancellation left the provider Cron event behind"
print("PASS: external HTTPS MCP performs exact verified reschedule and unschedule, leaving no fixture event.")


# A separately installed provider explicitly opts in to a targeted runner.
# This calls the real Bridge Ability from a distinct authenticated HTTPS/MCP
# client process, not from the WordPress PHP integration harness.
targeted_profile = "fixture_external_targeted"
targeted_hook = "wpai138_external_targeted_fixture"
registered, _ = ability("wp-ai-bridge/cron-events-manage", {"action": "profiles"})
matches = [p for p in registered["profiles"] if p["profile_id"] == targeted_profile]
assert len(matches) == 1 and "run_now" in matches[0]["actions"]
before, _ = ability("wp-ai-bridge/cron-events-read", {"action": "list", "hook": targeted_hook})
assert before["total"] == 0
targeted_when = int(time.time()) + 7200
targeted_event, _ = ability("wp-ai-bridge/cron-events-manage", {
    "action": "schedule", "profile_id": targeted_profile, "timestamp": targeted_when,
    "recurrence": "hourly", "expected_snapshot": before["snapshot"]})
assert targeted_event["status"] == "scheduled"
run_input = {
    "action": "run_now", "profile_id": targeted_profile,
    "event_id": targeted_event["event_id"], "expected_snapshot": targeted_event["snapshot"],
    "operation_id": "independent-https-mcp-runonce", "confirm": True,
}
expected_failure("wp-ai-bridge/cron-events-manage", dict(run_input, confirm=False))
result, _ = ability("wp-ai-bridge/cron-events-manage", run_input)
assert result["status"] == "provider_verified", "Provider effect was not authoritatively verified"
again, _ = ability("wp-ai-bridge/cron-events-manage", run_input)
assert again["status"] == "already_verified", "Identical retry failed to read back provider receipt"
expected_failure("wp-ai-bridge/cron-events-manage", dict(run_input, operation_id="new-operation-cannot-reexecute"))
still, _ = ability("wp-ai-bridge/cron-events-read", {"action": "get", "event_id": targeted_event["event_id"]})
assert still["items"][0]["next_run_timestamp"] == targeted_when, "Manual execution modified native schedule"
after, _ = ability("wp-ai-bridge/cron-events-manage", {
    "action": "unschedule", "profile_id": targeted_profile,
    "event_id": targeted_event["event_id"], "expected_snapshot": targeted_event["snapshot"]})
assert after["status"] == "unscheduled"
print("PASS: independent HTTPS/OAuth/MCP provider targeted run_now executes one verified effect, replays do not execute, native schedule unchanged.")
