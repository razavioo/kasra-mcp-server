"""Personal MCP tools: verified self only, explicit confirmation and no retries."""
from __future__ import annotations

import hashlib
import hmac
import json
import re
from typing import Annotated
from uuid import UUID

from fastmcp import FastMCP
from fastmcp.exceptions import ToolError
from pydantic import BaseModel, ConfigDict, Field, field_validator, model_validator

from kasra_client import _normalize_jalali_date
from manager_tools import READ, WRITE
from runtime import DecisionLedger, current_account


class Submission(BaseModel):
    model_config = ConfigDict(extra="forbid", strict=True)
    request_key: str = Field(max_length=36, description="Stable UUID generated for this human-reviewed request; reuse on communication failure")
    credit_type_id: int = Field(gt=0)
    start_date: str = Field(max_length=10)
    end_date: str = Field(max_length=10)
    start_time: str = Field(default="", max_length=5)
    end_time: str = Field(default="", max_length=5)
    is_daily: bool
    description: str = Field(default="", max_length=1000)
    confirmed: bool

    @field_validator("request_key")
    @classmethod
    def valid_key(cls, value):
        return str(UUID(value))

    @field_validator("start_date", "end_date")
    @classmethod
    def valid_date(cls, value):
        result = _normalize_jalali_date(value)
        if not result or not re.fullmatch(r"1[0-9]{3}/[0-9]{2}/[0-9]{2}", result):
            raise ValueError("A valid Jalali date YYYY/MM/DD is required")
        return result

    @field_validator("start_time", "end_time")
    @classmethod
    def valid_time(cls, value):
        if value and not re.fullmatch(r"(?:[01][0-9]|2[0-3]):[0-5][0-9]", value):
            raise ValueError("A valid time HH:mm is required")
        return value

    @model_validator(mode="after")
    def valid_range(self):
        if self.start_date > self.end_date:
            raise ValueError("End date precedes start date")
        return self


async def personal_account(*, profile_only: bool = False):
    account = current_account()
    if account.scope != "personal":
        raise ToolError("ابزار شخصی نیازمند اتصال مستقل همان کاربر است.")
    profile = await account.client.authenticate()
    if not profile_only and (not account.expected_code or not hmac.compare_digest(account.expected_code, profile["code"])):
        raise ToolError(json.dumps({"code": "identity_mismatch"}))
    return account


async def submit(params: Submission, ledger: DecisionLedger | None = None) -> dict:
    account = await personal_account()
    if not account.can_submit or not params.confirmed:
        raise ToolError("ابتدا جزئیات همین درخواست را بررسی و ارسال آن را تأیید کنید.")
    payload = params.model_dump(exclude={"confirmed", "request_key"})
    key = hashlib.sha256(("personal-request:" + params.request_key).encode()).hexdigest()
    fingerprint = hashlib.sha256(json.dumps(payload, sort_keys=True, ensure_ascii=False).encode()).hexdigest()
    ledger = ledger or DecisionLedger()
    existing = ledger.status(account.identity, key)
    if existing:
        if ledger.decision(account.identity, key) != fingerprint:
            raise ToolError("جزئیات این درخواست پس از ارسال تغییر کرده؛ دوباره ارسال نشد.")
        return {"status": existing, "duplicate": True}
    prepared = await account.client.prepare_submission(payload)
    if not ledger.claim(account.identity, key, fingerprint):
        return {"status": ledger.status(account.identity, key), "duplicate": True}
    try:
        result = await account.client.send_submission(prepared)
        status = result.get("status", "needs_review")
        if status not in ("submitted", "queued", "rejected", "needs_review"):
            result = {"status": "needs_review"}
        ledger.update(account.identity, key, result["status"])
        return {**result, "duplicate": False}
    except Exception:
        ledger.update(account.identity, key, "needs_review")
        return {"status": "needs_review", "duplicate": False}


def register_personal_tools(mcp: FastMCP):
    @mcp.tool(annotations=READ)
    async def kasra_personal_get_profile() -> dict:
        """Verify the personal account's own identity. Does not fetch another employee."""
        return (await personal_account(profile_only=True)).client.profile

    @mcp.tool(annotations=READ)
    async def kasra_personal_get_workspace() -> dict:
        """Read own live dashboard (including source cardex balance), monthly/daily hours, request status, work periods and permitted request types. No other person's data."""
        return await (await personal_account()).client.workspace()

    @mcp.tool(annotations=READ)
    async def kasra_personal_get_period_report(period: Annotated[str, Field(pattern=r"^[0-9]{1,30}$")]) -> dict:
        """Read own attendance and submitted requests for a real Kasra work-period ID."""
        account = await personal_account()
        if period not in {p["id"] for p in await account.client.periods()}:
            raise ToolError("دورهٔ انتخاب‌شده در حساب کسرا وجود ندارد.")
        return await account.client.period_report(period)

    @mcp.tool(annotations=WRITE)
    async def kasra_personal_submit_request(params: Submission) -> dict:
        """Submit this personal account's human-reviewed leave, mission, overtime or other permitted credit exactly once. No target-person parameter. Never auto-retry ambiguous submissions."""
        return await submit(params)
