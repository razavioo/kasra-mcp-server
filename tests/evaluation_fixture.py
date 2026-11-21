"""Deterministic, fictitious data for read-only agent evaluations; never live."""
from contextlib import asynccontextmanager
from fastmcp import FastMCP

from manager_tools import register_manager_tools
from runtime import Account, AccountMiddleware
import runtime


class FixtureClient:
    async def authenticate(self):
        return {'code': '00900', 'name': 'مدیر نمونه'}

    async def monthly(self, period=None):
        return {'period': '77', 'title': 'مهر آزمایشی ثابت', 'people': [
            {'code': '00123', 'name': 'آزمایشی الف', 'metrics': {'کارکرد': '120:35', 'اضافه‌کار': '03:10', 'کسری': '04:00', 'مرخصی روزانه': '2,04:30'}},
            {'code': '00456', 'name': 'آزمایشی ب', 'metrics': {'کارکرد': '140:00', 'اضافه‌کار': '05:00', 'کسری': '00:00', 'مرخصی روزانه': '1,00:00'}},
            {'code': '00789', 'name': 'آزمایشی ج', 'metrics': {'کارکرد': '110:15', 'اضافه‌کار': '00:00', 'کسری': '08:00', 'مرخصی روزانه': '0,00:00'}},
        ]}

    async def cartable(self, status='pending'):
        rows = {
            'pending': [('11', '00123', 'آزمایشی الف', 'مرخصی ساعتی'), ('12', '00789', 'آزمایشی ج', 'مرخصی روزانه')],
            'approved': [('21', '00456', 'آزمایشی ب', 'مأموریت روزانه'), ('22', '00123', 'آزمایشی الف', 'مرخصی روزانه')],
            'rejected': [('31', '00789', 'آزمایشی ج', 'مرخصی ساعتی')],
        }
        return [{'id': doc, 'code': code, 'name': name, 'type': kind, 'status': status, 'fingerprint': 'a' * 64} for doc, code, name, kind in rows[status]]

    async def daily(self, code, date):
        return [{'date': date, 'values': {'تاریخ': date, 'آخرین خروج': {'00123': '16:20', '00456': '17:00', '00789': '15:30'}[code]}}]

    async def get_dashboard_summary(self):
        return {'cardex': '32:00', 'scope': 'signed_in_person_only'}


@asynccontextmanager
async def fixture_lifespan(server):
    yield


def fixture_server():
    server = FastMCP('kasra_fixture', lifespan=fixture_lifespan)
    register_manager_tools(server)
    server.add_middleware(AccountMiddleware())
    return server


if __name__ == '__main__':
    account = Account(FixtureClient(), 'fixture', False)
    async def get_account():
        return account
    runtime.accounts.get = get_account
    fixture_server().run(show_banner=False)
