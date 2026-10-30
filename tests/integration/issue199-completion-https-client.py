#!/usr/bin/env python3
"""Standalone standards HTTPS/OAuth/MCP client: no WordPress import or mocks."""
import base64
import hashlib
import http.client
import json
import os
import ssl
import sys
import time
import urllib.parse

path, ca_file = sys.argv[1:3]
mode = sys.argv[3] if len(sys.argv) > 3 else 'run'
with open(path, encoding='utf-8') as handle:
    state = json.load(handle)
url = urllib.parse.urlsplit(state['endpoint'])
assert url.scheme == 'https' and url.hostname in ('localhost', '127.0.0.1')
context = ssl.create_default_context(cafile=ca_file)
session = state.get('https_session', '')
counter = 0
requests = 0
start = time.monotonic()


def request(method, payload=None, token=True):
    global requests
    assert time.monotonic() - start < 180, 'Finite HTTPS client deadline exceeded'
    headers = {'Accept': 'application/json, text/event-stream', 'Content-Type': 'application/json', 'MCP-Protocol-Version': '2025-11-25'}
    if token:
        headers['Authorization'] = 'Bearer ' + state['token']
    if session:
        headers['Mcp-Session-Id'] = session
    connection = http.client.HTTPSConnection(url.hostname, url.port or 443, context=context, timeout=25)
    try:
        body = None if payload is None else json.dumps(payload, separators=(',', ':')).encode()
        connection.request(method, url.path + ('?' + url.query if url.query else ''), body=body, headers=headers)
        response = connection.getresponse()
        status, response_headers = response.status, {k.lower(): v for k, v in response.getheaders()}
        raw = response.read(131073)
        assert len(raw) <= 131072, 'MCP response exceeded unchanged default transport budget'
        requests += 1
    finally:
        connection.close()
    if raw.startswith((b'event:', b'data:')):
        raw = [line[5:].strip() for line in raw.splitlines() if line.startswith(b'data:')][-1]
    return status, response_headers, json.loads(raw) if raw else {}


def rpc(method, params=None, notify=False):
    global counter
    counter += 1
    payload = {'jsonrpc': '2.0', 'method': method}
    if not notify:
        payload['id'] = counter
    if params is not None:
        payload['params'] = params
    status, headers, data = request('POST', payload)
    assert status == (202 if notify else 200), 'Unexpected canonical MCP HTTP status: ' + str(status)
    return data, headers


def ability(name, parameters, denied=False):
    envelope, _ = rpc('tools/call', {'name': 'mcp-adapter-execute-ability', 'arguments': {'ability_name': 'wp-ai-bridge/' + name, 'parameters': parameters}})
    result = envelope.get('result', {})
    if denied:
        assert result.get('isError') is True, 'Current revoked delegation was accepted: ' + name
        return result
    assert result.get('structuredContent', {}).get('success') is True, 'Canonical HTTPS Ability failed: ' + name
    return result['structuredContent']['data']


if mode == 'token_revoked':
    status, _, _ = request('POST', {'jsonrpc': '2.0', 'id': 1, 'method': 'initialize', 'params': {}})
    assert status == 401, 'Revoked synthetic OAuth credential remained accepted'
    print('PASS: external verified-TLS OAuth token revocation returns401.')
    sys.exit(0)

if mode == 'revoked':
    assert session, 'A real previously established HTTPS MCP session is required'
    for name, params in [('filesystem-read', {'action': 'stat', 'root_id': 'fixtures', 'path': 'binary.bin'}), ('logs-read', {'action': 'tail', 'root_id': 'fixtures', 'path': 'fixture.log', 'max_bytes': 1024, 'lines': 1}), ('scheduler-read', {'action': 'providers'}), ('native-configuration', {'action': 'read', 'target': 'transient', 'name': 'admin13_https_' + state['suffix']}), ('database-schema', {'action': 'describe', 'table': 'wp_options'}), ('full-backup', {'action': 'inspect', 'source_roots': ['installation']})]:
        ability(name, params, denied=True)
    status, _, _ = request('DELETE')
    assert status in (200, 204)
    print(json.dumps({'test': 'completion-external-https-current-revocation', 'status': 'pass', 'requests': requests}))
    sys.exit(0)

assert mode == 'run'
status, _, _ = request('POST', {'jsonrpc': '2.0', 'id': 1, 'method': 'initialize', 'params': {}}, token=False)
assert status == 401, 'Anonymous external HTTPS MCP accepted'
init, headers = rpc('initialize', {'protocolVersion': '2025-11-25', 'capabilities': {}, 'clientInfo': {'name': 'admin13-standalone-https', 'version': '1'}})
assert init['result']['protocolVersion'] == '2025-11-25'
session = headers.get('mcp-session-id', '')
assert session
state['https_session'] = session
with open(path, 'w', encoding='utf-8') as handle:
    json.dump(state, handle)
os.chmod(path, 0o600)
rpc('notifications/initialized', notify=True)
tools, _ = rpc('tools/list', {})
assert {t['name'] for t in tools['result']['tools']} == {'mcp-adapter-discover-abilities', 'mcp-adapter-execute-ability', 'mcp-adapter-get-ability-info'}
stat = ability('filesystem-read', {'action': 'stat', 'root_id': 'fixtures', 'path': 'binary.bin'})
assert stat
log = ability('logs-read', {'action': 'tail', 'root_id': 'fixtures', 'path': 'fixture.log', 'max_bytes': 1024, 'lines': 1})
assert b'synthetic last' in base64.b64decode(log['data_base64'])
limits = ability('filesystem-transfer', {'action': 'limits', 'root_id': 'fixtures'})
export = ability('filesystem-transfer', {'action': 'export_begin', 'root_id': 'fixtures', 'path': 'binary.bin'})
for _ in range(10000):
    if export['state'] == 'verified':
        break
    export = ability('filesystem-transfer', {'action': 'export_step', 'root_id': 'fixtures', 'transfer_id': export['id']})
assert export['state'] == 'verified'
offset, digest = 0, hashlib.sha256()
while True:
    part = ability('filesystem-transfer', {'action': 'read', 'root_id': 'fixtures', 'transfer_id': export['id'], 'offset': offset, 'max_bytes': min(32768, limits['chunk'])})
    piece = base64.b64decode(part['base64'], validate=True)
    assert hashlib.sha256(piece).hexdigest() == part['chunk_sha256'] and part['next_offset'] == offset + len(piece)
    digest.update(piece)
    offset = part['next_offset']
    if part['complete']:
        break
assert offset == state['seed']['large_bytes'] and digest.hexdigest() == state['seed']['large_sha256']
ability('filesystem-transfer', {'action': 'release', 'root_id': 'fixtures', 'transfer_id': export['id'], 'export': True})
# Resumable binary ingress and actual native publication through separate HTTP requests.
blob = (b'HTTPS\x00\xff' + 'سلام'.encode()) * 20000
sha = hashlib.sha256(blob).hexdigest()
opened = ability('filesystem-transfer', {'action': 'begin', 'root_id': 'fixtures', 'total_bytes': len(blob), 'sha256': sha})
for offset in range(0, len(blob), 32768):
    ability('filesystem-transfer', {'action': 'append', 'root_id': 'fixtures', 'transfer_id': opened['id'], 'offset': offset, 'base64': base64.b64encode(blob[offset:offset+32768]).decode()})
ability('filesystem-transfer', {'action': 'verify', 'root_id': 'fixtures', 'transfer_id': opened['id']})
write = {'action': 'write', 'root_id': 'fixtures', 'path': 'https-published.bin', 'transfer_id': opened['id'], 'operation_id': 'https-file-' + state['suffix']}
ability('filesystem-write', write)
assert ability('filesystem-write', write)['historical'] is True
published = ability('filesystem-read', {'action': 'stat', 'root_id': 'fixtures', 'path': 'https-published.bin'})
assert published['size'] == len(blob)
ability('filesystem-transfer', {'action': 'release', 'root_id': 'fixtures', 'transfer_id': opened['id'], 'export': False})
value = {'value_json': json.dumps({'format': 'wpai-native-option-value-v2', 'value': {'type': 'string', 'base64': base64.b64encode(b'HTTPS\x00' + 'سلام'.encode()).decode()}}, separators=(',', ':'))}
ability('native-configuration', {'action': 'set', 'target': 'transient', 'name': 'admin13_https_' + state['suffix'], 'operation_id': 'https-config-' + state['suffix'], 'expiration_seconds': 300, 'value_source': value})
custom = {'value_json': json.dumps({'format': 'wpai-native-option-value-v2', 'value': {'type': 'string', 'base64': base64.b64encode(('HTTPS ' + state['suffix']).encode()).decode()}}, separators=(',', ':'))}
ability('customizer-settings', {'action': 'update', 'expected_stylesheet': state['stylesheet'], 'name': 'wpai199c_customizer_text', 'operation_id': 'https-customizer-' + state['suffix'], 'value_source': custom})
ability('scheduler-read', {'action': 'providers'})
scheduled = ability('scheduler-manage', {'action': 'schedule', 'provider': 'wp_cron', 'hook': 'wpai199c_owned_queue_effect', 'args': ['https-' + state['suffix'], 'normal'], 'schedule_type': 'single', 'timestamp': int(time.time()) + 7200, 'operation_id': 'https-cron-' + state['suffix'], 'confirm': True})
assert scheduled['dispatch_attempted'] is True
rows = ability('scheduler-read', {'action': 'list', 'provider': 'wp_cron', 'hook': 'wpai199c_owned_queue_effect', 'limit': 20})
assert len(rows['items']) == 1
row = rows['items'][0]
ability('scheduler-manage', {'action': 'cancel', 'provider': 'wp_cron', 'target_id': row['target_id'], 'expected_snapshot': row['snapshot'], 'operation_id': 'https-cancel-' + state['suffix'], 'confirm': True})
ability('database-schema', {'action': 'describe', 'table': 'wp_options'})
if state['sql_enabled']:
    expected_cell = b'HTTPS SQL\x00\xff' + 'سلام'.encode()
    operation = 'https-sql-' + state['suffix']
    command = {'action': 'prepare', 'operation_id': operation, 'acknowledge_native_account_scope': True, 'statement_base64': base64.b64encode(b'SELECT ? AS exact_bytes').decode(), 'parameters': [base64.b64encode(expected_cell).decode()], 'max_result_bytes': 1048576}
    prepared = ability('database-native-sql', command)
    command.update(action='execute', preparation_token=prepared['preparation_token'])
    executed = ability('database-native-sql', command)
    private = executed['private_result']
    assert executed['receipt']['result']['result_id'] == private['id']
    result, offset = bytearray(), 0
    while True:
        part = ability('database-native-sql', {'action': 'result_read', 'operation_id': operation, 'acknowledge_native_account_scope': True, 'result_id': private['id'], 'offset': offset, 'length': 257, 'expected_sha256': private['sha256']})
        piece = base64.b64decode(part['base64'], validate=True)
        assert hashlib.sha256(piece).hexdigest() == part['chunk_sha256']
        result.extend(piece)
        offset = part['next_offset']
        if part['complete']:
            break
    cells = b''.join(base64.b64decode(frame['data']) for frame in map(json.loads, result.splitlines()) if str(frame.get('row', '')) == '0' and frame.get('column') == 0)
    assert cells == expected_cell
    ability('database-native-sql', {'action': 'result_release', 'operation_id': operation, 'acknowledge_native_account_scope': True, 'result_id': private['id']})
plan = ability('full-backup', {'action': 'inspect', 'source_roots': ['installation']})
assert plan['complete'] is False and plan['quiescence_assumption'] is True
print(json.dumps({'test': 'completion-external-verified-tls-oauth-mcp', 'status': 'pass', 'requests': requests, 'full_file_bytes': state['seed']['large_bytes'], 'input_bytes': len(blob), 'seconds': round(time.monotonic()-start, 3)}))
