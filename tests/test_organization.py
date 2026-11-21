import json
import time
from unittest.mock import AsyncMock

import httpx
import pytest

from organization import KasraError, OrganizationalKasraClient, cartable_item, grid, monthly_person, setting


def monthly_markup():
    result = '<select id="ctl00_ContentPlaceHolder1_CmbPeriod"><option value="77" selected>مهر</option></select>'
    for name in ['ctl00_antiCsrfToken', 'ctl00_keyToken', 'ctl00_ContentPlaceHolder1_txtSessionID', 'ctl00_ContentPlaceHolder1_txtOnLineUser', 'ctl00_ContentPlaceHolder1_txtCompanyID']:
        result += f'<input id="{name}" value="dummy">'
    return result


def authenticated(handler):
    client = OrganizationalKasraClient('dummy', 'dummy', httpx.MockTransport(handler))
    client.profile = {'code': '001', 'name': 'مدیر آزمایشی'}
    client._is_logged_in = True
    client._last_active_time = time.time()
    return client


def test_preserves_units_and_leading_zero_codes():
    rows, _, columns = grid({'d': '<Root><Column field="c1" title="کل حضور" /></Root>' + json.dumps([
        {'PersonCode': '00123', 'Name': 'همکار', 'c1': '120:35', 'استحقاقي روزانه': '2,04:30'}])})
    result = monthly_person(rows[0], columns)
    assert result['code'] == '00123'
    assert result['metrics']['حضور'] == '120:35'
    assert result['metrics']['مرخصی روزانه'] == '2,04:30'
    assert monthly_person({'Name': 'جمع'}, {}) is None


@pytest.mark.parametrize('response', [{'Unexpected': []}, {'Data': [], 'Total': 'oops'}, {'Data': [], 'Total': 0, 'Errors': 'oops'}, {'d': '<Root>broken'}])
def test_unknown_grid_fails(response):
    with pytest.raises(KasraError):
        grid(response)


def test_literal_config_does_not_execute_javascript():
    assert setting('X.url = makeUrl();', 'X.url') == ''
    assert setting('X.url = "Frm/Cartable/Modify";', 'X.url') == 'Frm/Cartable/Modify'


def test_fingerprint_changes_on_workflow_stage_not_pagination():
    row = {'DocID': '7', 'StepNo': '2', 'PageNumber': '1', 'SDate': '1405/07/01'}
    original = cartable_item(row, 'pending')['fingerprint']
    assert cartable_item({**row, 'PageNumber': '2'}, 'pending')['fingerprint'] == original
    assert cartable_item({**row, 'StepNo': '3'}, 'pending')['fingerprint'] != original


@pytest.mark.parametrize('url', ['https://attacker.invalid/Lego.Web/x', 'https://app.kasralite.com:444/Lego.Web/x', '/Lego.Web/../steal'])
def test_action_url_stays_on_kasra(url):
    with pytest.raises(KasraError):
        OrganizationalKasraClient.safe_url(url)


async def test_monthly_fetches_all_pages_with_subordinates():
    requests = []
    def handler(request):
        if request.method == 'GET':
            return httpx.Response(200, text=monthly_markup())
        body = json.loads(request.content)
        requests.append(body)
        start = (int(body['PageNumber']) - 1) * 50
        return httpx.Response(200, json={'Data': [{'PersonCode': f'{n:05}', 'Name': f'همکار {n}', 'کارکرد': '08:00'} for n in range(start, min(start + 50, 51))], 'Total': 51})
    client = authenticated(handler)
    try:
        result = await client.monthly()
        assert len(result['people']) == 51
        assert result['people'][-1]['code'] == '00050'
        assert len(requests) == 2
        assert requests[0]['ChkChildren'] == '1'
        assert requests[0]['Search'] == ''
    finally:
        await client.close()


async def test_truncated_page_fails():
    client = authenticated(lambda request: httpx.Response(200, text=monthly_markup()) if request.method == 'GET' else httpx.Response(200, json={'Data': [], 'Total': 51}))
    try:
        with pytest.raises(KasraError, match='ناقص'):
            await client.monthly()
    finally:
        await client.close()


async def test_login_200_is_not_authenticated():
    def handler(request):
        if 'GetHeader' in str(request.url):
            return httpx.Response(200, json={'nonce': 'dummy', 'checksum': 'dummy'})
        return httpx.Response(200, text='<form class="login-form"><input name="UserName"><input name="Password"></form>')
    client = OrganizationalKasraClient('dummy', 'dummy', httpx.MockTransport(handler))
    try:
        with pytest.raises(KasraError, match='تأیید نشد'):
            await client.authenticate()
        assert client._is_logged_in is False
    finally:
        await client.close()


async def test_signed_mutation_transmits_exact_signed_bytes():
    markup = '<input id="FromDateCartable" value="1405/07/01"><input id="ToDateCartable" value="1405/07/30">'
    for key, val in {'ModifyCasingWorkTableUrl': 'Frm/Cartable/ModifyCasingWorkTable', 'SessionId': '12', 'CompanyID': '99', 'AccepterCode': '001', 'PeriodLookupId': '1311104'}.items():
        markup += f'CartableInWaitNameSpace.{key} = "{val}";'
    signed, mutations = [], []
    def handler(request):
        if 'CartableInWaitRender' in str(request.url):
            return httpx.Response(200, text=markup)
        if 'GetHeader' in str(request.url):
            signed.append(json.loads(request.content)['data'])
            return httpx.Response(200, json={'nonce': 'dummy', 'checksum': 'dummy'})
        mutations.append(request)
        assert request.content.decode() == signed[-1]
        assert 'توضیح' in request.content.decode()
        return httpx.Response(200, json='')
    client = authenticated(handler)
    try:
        item = cartable_item({'DocID': '7', 'DocTypeID': '11001', 'Action': '0', 'PageNumber': '1', 'StepNo': '2'}, 'pending')
        await client.send_decision(item, 'approved', 'توضیح')
        assert len(mutations) == 1
    finally:
        await client.close()


async def test_daily_rejects_wrong_person():
    def handler(request):
        if 'CartableInWaitRender' in str(request.url):
            return httpx.Response(200, text='CartableInWaitNameSpace.MainDailyReportUrl = "/TAPresentation/Daily";')
        return httpx.Response(200, text='<input id="ctl00_ContentPlaceHolder1_CmbPerson_txtPCode" value="00999">')
    client = authenticated(handler)
    client._daily_reports[(1405, 7)] = {
        'loaded_at': time.monotonic(), 'period': '77',
        'session': 'fixture', 'online_user': '1', 'parent_menu': '2',
        'people': {'00123': {'code': '00123', 'id': '7', 'name': 'Fixture'}},
    }
    try:
        with pytest.raises(KasraError, match='یکسان نیست'):
            await client.daily('00123', '1405/07/01')
    finally:
        await client.close()


async def test_snapshot_refuses_document_changing_status_during_fetch():
    client = authenticated(lambda r: httpx.Response(500))
    client.monthly = AsyncMock(return_value={'period': '77', 'people': []})
    client.cartable = AsyncMock(side_effect=[[{'id': '7'}], [{'id': '7'}], []])
    try:
        with pytest.raises(KasraError, match='تغییر کرده'):
            await client.snapshot()
    finally:
        await client.close()
