"""Offline container smoke check: start actual HTTP server, no Kasra access."""
import base64
import os
import subprocess
import sys
import time

import httpx

env = {**os.environ, 'KASRA_MCP_MODE': 'http', 'KASRA_MCP_HOST': '127.0.0.1',
       'KASRA_MCP_TOKEN': 'fixture-token-' + 'x' * 40,
       'KASRA_MCP_ENVELOPE_KEY': base64.b64encode(b'x' * 32).decode()}
process = subprocess.Popen([sys.executable, 'main.py'], env=env, stdout=subprocess.DEVNULL, stderr=subprocess.PIPE)
try:
    with httpx.Client(base_url='http://127.0.0.1:8000', trust_env=False, timeout=5) as client:
        # Cold starts on the production host can exceed five seconds.
        for _ in range(300):
            try:
                if client.post('/mcp').status_code == 401:
                    break
            except httpx.TransportError:
                pass
            if process.poll() is not None:
                raise RuntimeError('MCP server exited before readiness')
            time.sleep(0.1)
        else:
            raise RuntimeError('MCP server did not start')
        headers = {'Authorization': 'Bearer ' + env['KASRA_MCP_TOKEN'], 'Accept': 'application/json, text/event-stream', 'Host': 'kasra-mcp:8000'}
        initialized = client.post('/mcp', headers=headers, json={'jsonrpc': '2.0', 'id': 1, 'method': 'initialize', 'params': {
            'protocolVersion': '2025-06-18', 'capabilities': {}, 'clientInfo': {'name': 'offline-smoke', 'version': '1'}}})
        assert initialized.status_code == 200, initialized.status_code
        headers['MCP-Protocol-Version'] = initialized.json()['result']['protocolVersion']
        listed = client.post('/mcp', headers=headers, json={'jsonrpc': '2.0', 'id': 2, 'method': 'tools/list', 'params': {}})
        assert len(listed.json()['result']['tools']) == 28
        denied = client.post('/mcp', headers=headers, json={'jsonrpc': '2.0', 'id': 3, 'method': 'tools/call', 'params': {'name': 'kasra_manager_get_profile', 'arguments': {}}})
        assert denied.json()['result']['isError']
        print('HTTP initialize/list: 28 tools; service auth and missing-account denial passed; no provider calls.')
finally:
    process.terminate()
    try:
        process.wait(timeout=10)
    except subprocess.TimeoutExpired:
        process.kill()
        process.wait(timeout=5)
