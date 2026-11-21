from pathlib import Path
from unittest.mock import AsyncMock
import xml.etree.ElementTree as ET

from fastmcp import Client
from evaluation_fixture import FixtureClient, fixture_server
import runtime
from runtime import Account


def minutes(text):
    h, m = map(int, text.split(':'))
    return h * 60 + m


def duration(values):
    total = sum(map(minutes, values))
    return f'{total // 60:02}:{total % 60:02}'


async def test_evaluation_answers_verified_through_read_only_mcp_tools(monkeypatch):
    account = Account(FixtureClient(), 'fixture', False)
    monkeypatch.setattr(runtime.accounts, 'get', AsyncMock(return_value=account))
    async with Client(fixture_server()) as client:
        async def all_pages(tool, **arguments):
            offset, items = 0, []
            while True:
                result = (await client.call_tool(tool, {'page': {'limit': 1, 'offset': offset}, **arguments})).data
                items.extend(result['items'])
                if not result['has_more']:
                    return items
                offset = result['next_offset']
        people = {p['code']: p for p in await all_pages('kasra_manager_get_monthly_report')}
        pending = await all_pages('kasra_manager_get_cartable', status='pending')
        approved = await all_pages('kasra_manager_get_cartable', status='approved')
        rejected = await all_pages('kasra_manager_get_cartable', status='rejected')
        pending_codes = {p['code'] for p in pending}
        approved_codes = {p['code'] for p in approved}
        rejected_codes = {p['code'] for p in rejected}
        both = next(p['code'] for p in pending if p['type'] == 'مرخصی ساعتی' and p['code'] in approved_codes)
        deficit_person = max((people[c] for c in pending_codes), key=lambda p: minutes(p['metrics']['کسری']))
        mission_person = next(p['code'] for p in approved if p['type'] == 'مأموریت روزانه')
        rejected_person = next(p['code'] for p in rejected if p['type'] == 'مرخصی ساعتی')
        daily = (await client.call_tool('kasra_manager_get_daily_report', {'personnel_code': rejected_person, 'jalali_date': '1405/07/01'})).data
        highest = max(people.values(), key=lambda p: minutes(p['metrics']['کارکرد']))
        hourly_codes = {p['code'] for p in pending + rejected if p['type'] == 'مرخصی ساعتی'}
        answers = [both, deficit_person['name'], people[mission_person]['metrics']['کارکرد'], daily['rows'][0]['values']['آخرین خروج'],
                   duration(people[c]['metrics']['اضافه‌کار'] for c in approved_codes), str(len(set(people) - pending_codes)),
                   duration(people[c]['metrics']['کارکرد'] for c in pending_codes), highest['metrics']['مرخصی روزانه'],
                   next(p['id'] for p in pending if p['code'] in rejected_codes), duration(people[c]['metrics']['کسری'] for c in hourly_codes)]
    xml = ET.parse(Path(__file__).parents[1] / 'docs/evaluation.xml')
    expected = [pair.findtext('answer') for pair in xml.findall('qa_pair')]
    assert len(expected) == 10
    assert answers == expected
