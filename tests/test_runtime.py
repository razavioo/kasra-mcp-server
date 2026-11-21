import asyncio
import base64
import json
import time
from unittest.mock import AsyncMock

import httpx
import pytest
from Crypto.Cipher import AES
from fastmcp import Client
from fastmcp.exceptions import ToolError

import runtime
from manager_tools import Decision, decide
from organization import KasraError, cartable_item
from runtime import AAD, CURRENT, Account, Accounts, DecisionLedger, PrivateMcpHttp, decode_credentials
from server import mcp


def capsule(data, key=b'x' * 32):
    cipher = AES.new(key, AES.MODE_GCM, nonce=b'n' * 12)
    cipher.update(AAD)
    ciphertext, tag = cipher.encrypt_and_digest(json.dumps(data).encode())
    return base64.b64encode(cipher.nonce + tag + ciphertext).decode()


def account_data(**overrides):
    return {'account': 'tenant:1:generation', 'username': '00123', 'password': 'dummy-secret', 'can_decide': False, 'iat': int(time.time()), **overrides}


def test_envelope_is_authenticated_and_expires(monkeypatch):
    monkeypatch.setenv('KASRA_MCP_ENVELOPE_KEY', base64.b64encode(b'x' * 32).decode())
    assert decode_credentials(capsule(account_data()))['username'] == '00123'
    for text in [capsule(account_data(iat=1)), capsule(account_data(), b'z' * 32), 'invalid', '']:
        with pytest.raises(ToolError):
            decode_credentials(text)


async def test_account_sessions_are_isolated_but_reused_across_timestamps(monkeypatch):
    monkeypatch.setenv('KASRA_MCP_MODE', 'http')
    monkeypatch.setenv('KASRA_MCP_ENVELOPE_KEY', base64.b64encode(b'x' * 32).decode())
    headers = {'x-kasra-account': capsule(account_data())}
    monkeypatch.setattr(runtime, 'get_http_headers', lambda: headers)
    pool = Accounts()
    try:
        first = await pool.get()
        headers['x-kasra-account'] = capsule(account_data(iat=int(time.time()) + 1))
        assert (await pool.get()).client is first.client
        headers['x-kasra-account'] = capsule(account_data(account='other-tenant', username='00999'))
        second = await pool.get()
        assert second is not first
        assert second.client.username == '00999'
        assert first.client.username == '00123'
    finally:
        await pool.close()


def test_durable_ledger_survives_restart_and_blocks_opposite_decision(tmp_path):
    path = str(tmp_path / 'decisions.sqlite')
    ledger = DecisionLedger(path)
    assert ledger.claim('owner', 'fingerprint', 'approved')
    restarted = DecisionLedger(path)
    assert not restarted.claim('owner', 'fingerprint', 'rejected')
    assert restarted.status('owner', 'fingerprint') == 'sending'
    assert restarted.decision('owner', 'fingerprint') == 'approved'


def pending_item():
    return cartable_item({'DocID': '7', 'StepNo': '2', 'requesterCode': '00123'}, 'pending')


def parameters(item=None, **overrides):
    item = item or pending_item()
    return Decision(document_id=item['id'], expected_fingerprint=item['fingerprint'], decision='approved', confirmed=True, **overrides)


@pytest.fixture
def account_context():
    fake = AsyncMock()
    fake.cartable.return_value = [pending_item()]
    account = Account(fake, 'owner', True)
    token = CURRENT.set(account)
    yield account
    CURRENT.reset(token)


async def test_decision_requires_enabled_owner_and_human_confirmation(account_context, tmp_path):
    ledger = DecisionLedger(str(tmp_path / 'decisions.sqlite'))
    account_context.can_decide = False
    with pytest.raises(ToolError):
        await decide(parameters(), ledger)
    account_context.can_decide = True
    with pytest.raises(ToolError):
        await decide(parameters().model_copy(update={'confirmed': False}), ledger)
    account_context.client.send_decision.assert_not_called()


async def test_stale_request_is_not_sent(account_context, tmp_path):
    account_context.client.cartable.return_value = []
    with pytest.raises(ToolError, match='تغییر کرده'):
        await decide(parameters(), DecisionLedger(str(tmp_path / 'ledger.sqlite')))
    account_context.client.send_decision.assert_not_called()


async def test_ambiguous_mutation_cannot_be_retried_after_restart(account_context, tmp_path):
    path = str(tmp_path / 'ledger.sqlite')
    account_context.client.send_decision.side_effect = KasraError('timeout')
    first = await decide(parameters(), DecisionLedger(path))
    assert first['status'] == 'needs_review'
    second = await decide(parameters(), DecisionLedger(path))
    assert second['duplicate']
    assert second['status'] == 'needs_review'
    account_context.client.send_decision.assert_awaited_once()


async def test_conflicting_duplicate_is_not_reported_successful(account_context, tmp_path):
    ledger = DecisionLedger(str(tmp_path / 'ledger.sqlite'))
    await decide(parameters(), ledger)
    with pytest.raises(ToolError, match='تصمیم دیگری'):
        await decide(parameters().model_copy(update={'decision': 'rejected'}), ledger)
    account_context.client.send_decision.assert_awaited_once()


async def test_success_requires_readback(account_context, tmp_path):
    ledger = DecisionLedger(str(tmp_path / 'ledger.sqlite'))
    account_context.client.cartable.side_effect = [[pending_item()], []]
    result = await decide(parameters(), ledger)
    assert result['status'] == 'awaiting_confirmation'


async def test_confirmed_mutation_processed_once(account_context, tmp_path):
    ledger = DecisionLedger(str(tmp_path / 'ledger.sqlite'))
    result = await decide(parameters(), ledger)
    assert result['status'] == 'processed'
    assert (await decide(parameters(), ledger))['duplicate']
    account_context.client.send_decision.assert_awaited_once()


async def test_real_mcp_client_lists_tools_and_calls_paginated_report(monkeypatch):
    fake = AsyncMock()
    fake.monthly.return_value = {'period': '77', 'title': 'مهر', 'people': [{'code': '001', 'name': 'الف'}, {'code': '002', 'name': 'ب'}]}
    account = Account(fake, 'test', False)
    monkeypatch.setattr(runtime.accounts, 'get', AsyncMock(return_value=account))
    async with Client(mcp) as client:
        tools = await client.list_tools()
        names = {t.name for t in tools}
        assert 'kasra_get_dashboard_summary' in names
        assert 'kasra_manager_decide_request' in names
        assert len(names) == 28
        result = await client.call_tool('kasra_manager_get_monthly_report', {'page': {'limit': 1}})
        assert result.data['items'][0]['code'] == '001'
        assert result.data['has_more'] is True
        assert result.data['next_offset'] == 1
        invalid = await client.call_tool('kasra_manager_get_monthly_report', {'page': {'limit': 1000}}, raise_on_error=False)
        assert invalid.is_error


async def test_mcp_error_preserves_backoff_and_safe_message(monkeypatch):
    fake = AsyncMock()
    fake.authenticate.side_effect = KasraError('کسرا درخواست‌ها را محدود کرده.', 600)
    account = Account(fake, 'test', False)
    monkeypatch.setattr(runtime.accounts, 'get', AsyncMock(return_value=account))
    async with Client(mcp) as client:
        result = await client.call_tool('kasra_manager_get_profile', {}, raise_on_error=False)
        assert result.is_error
        assert account.retry_at > time.time() + 590
        again = await client.call_tool('kasra_manager_get_profile', {}, raise_on_error=False)
        assert again.is_error
        fake.authenticate.assert_awaited_once()


async def test_private_http_auth_and_initialize(monkeypatch):
    monkeypatch.setenv('KASRA_MCP_TOKEN', 'test-token-' + 'x' * 40)
    monkeypatch.setenv('KASRA_MCP_ENVELOPE_KEY', base64.b64encode(b'x' * 32).decode())
    inner = mcp.http_app(path='/mcp', stateless_http=True, json_response=True)
    app = PrivateMcpHttp(inner)
    async with inner.router.lifespan_context(inner):
        async with httpx.AsyncClient(transport=httpx.ASGITransport(app=app), base_url='http://localhost') as client:
            body = {'jsonrpc': '2.0', 'id': 1, 'method': 'initialize', 'params': {'protocolVersion': '2025-06-18', 'capabilities': {}, 'clientInfo': {'name': 'test', 'version': '1'}}}
            assert (await client.post('/mcp', json=body)).status_code == 401
            headers = {'Authorization': 'Bearer test-token-' + 'x' * 40, 'Accept': 'application/json, text/event-stream'}
            assert (await client.post('/mcp', json=body, headers={**headers, 'Origin': 'https://evil.invalid'})).status_code == 403
            result = await client.post('/mcp', json=body, headers=headers)
            assert result.status_code == 200
            assert result.json()['result']['serverInfo']['name'] == 'kasra_mcp'


async def test_private_http_tool_receives_only_its_encrypted_account(monkeypatch):
    monkeypatch.setenv('KASRA_MCP_MODE', 'http')
    monkeypatch.setenv('KASRA_MCP_TOKEN', 't' * 40)
    monkeypatch.setenv('KASRA_MCP_ENVELOPE_KEY', base64.b64encode(b'x' * 32).decode())
    fake_accounts = []
    def factory(username, password):
        fake = AsyncMock()
        fake.authenticate.return_value = {'code': username, 'name': 'مدیر آزمایشی'}
        fake_accounts.append((username, password))
        return fake
    monkeypatch.setattr(runtime, 'OrganizationalKasraClient', factory)
    pool = Accounts()
    monkeypatch.setattr(runtime.accounts, 'get', pool.get)
    inner = mcp.http_app(path='/mcp', stateless_http=True, json_response=True)
    app = PrivateMcpHttp(inner)
    async with inner.router.lifespan_context(inner):
        async with httpx.AsyncClient(transport=httpx.ASGITransport(app=app), base_url='http://localhost') as client:
            headers = {'Authorization': 'Bearer ' + 't' * 40, 'Accept': 'application/json, text/event-stream', 'MCP-Protocol-Version': '2025-06-18'}
            body = {'jsonrpc': '2.0', 'id': 2, 'method': 'tools/call', 'params': {'name': 'kasra_manager_get_profile', 'arguments': {}}}
            missing = await client.post('/mcp', json=body, headers=headers)
            assert missing.json()['result']['isError']
            for username in ['00123', '00999']:
                response = await client.post('/mcp', json=body, headers={**headers, 'X-Kasra-Account': capsule(account_data(account='tenant:' + username, username=username))})
                assert response.status_code == 200
                assert response.json()['result']['structuredContent']['code'] == username
            assert fake_accounts == [('00123', 'dummy-secret'), ('00999', 'dummy-secret')]
    await pool.close()
