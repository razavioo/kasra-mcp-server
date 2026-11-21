"""Tools added to the upstream MCP server for Teamik and managerial agents."""
from __future__ import annotations

import hmac
from typing import Annotated, Literal

from fastmcp import FastMCP
from fastmcp.exceptions import ToolError
from pydantic import BaseModel, ConfigDict, Field

from runtime import DecisionLedger, current_account

READ = {"readOnlyHint": True, "destructiveHint": False, "idempotentHint": True, "openWorldHint": True}
WRITE = {"readOnlyHint": False, "destructiveHint": True, "idempotentHint": False, "openWorldHint": True}


class Page(BaseModel):
    model_config = ConfigDict(extra="forbid", strict=True)
    offset: int = Field(default=0, ge=0, le=10000, description="Zero-based result offset")
    limit: int = Field(default=25, ge=1, le=100, description="Maximum rows to return")
    search: str = Field(default="", max_length=100, description="Personnel code or name to filter")


class Decision(BaseModel):
    model_config = ConfigDict(extra="forbid", strict=True)
    document_id: str = Field(pattern=r"^[0-9]{1,30}$", description="Kasra document ID from managerial cartable")
    expected_fingerprint: str = Field(pattern=r"^[a-f0-9]{64}$", description="Exact fingerprint shown when the human reviewed this version")
    decision: Literal["approved", "rejected"] = Field(description="Human-selected approve or reject action")
    note: str = Field(default="", max_length=2000, description="Manager explanation sent to Kasra")
    confirmed: bool = Field(description="True only after the human explicitly authorizes this specific decision")


def page_result(items: list[dict], page: Page) -> dict:
    query = page.search.strip().casefold()
    filtered = [item for item in items if not query or query in (item.get("code", "") + " " + item.get("name", "")).casefold()]
    end = page.offset + page.limit
    return {"items": filtered[page.offset:end], "total": len(filtered), "offset": page.offset,
            "has_more": end < len(filtered), "next_offset": end if end < len(filtered) else None}


async def decide(params: Decision, ledger: DecisionLedger | None = None) -> dict:
    account = current_account()
    if not account.can_decide:
        raise ToolError("دسترسی ثبت تصمیم فعال نیست؛ فقط مالک حساب مدیریتی می‌تواند آن را فعال کند.")
    if not params.confirmed:
        raise ToolError("برای این سند و این تصمیم، تأیید صریح انسان لازم است؛ اقدامی ارسال نشد.")
    ledger = ledger or DecisionLedger()
    existing = ledger.status(account.identity, params.expected_fingerprint)
    if existing:
        if ledger.decision(account.identity, params.expected_fingerprint) != params.decision:
            raise ToolError("برای این نسخه تصمیم دیگری ثبت شده است؛ ابتدا وضعیت کسرا را بررسی کنید.")
        # Even a different decision may not resend the same source version.
        return {"status": existing, "duplicate": True, "document_id": params.document_id,
                "message": "برای این نسخه قبلاً اقدام ثبت شده؛ ارسال دوباره انجام نشد."}
    current = next((item for item in await account.client.cartable() if item["id"] == params.document_id), None)
    if not current or not hmac.compare_digest(current["fingerprint"], params.expected_fingerprint):
        raise ToolError("درخواست در کسرا تغییر کرده است؛ ابتدا کارتابل را تازه کنید و دوباره بررسی کنید.")
    try:
        prepared = await account.client.prepare_decision(current, params.decision, params.note)
    except Exception:
        # No mutation has started and no ledger entry has been claimed. A new
        # explicit human action may retry after the form/connection is fixed.
        return {"status": "not_sent", "document_id": params.document_id, "duplicate": False,
                "message": "اطلاعات فرم تأیید آماده نشد؛ هیچ تصمیمی به کسرا ارسال نشد."}
    if not ledger.claim(account.identity, current["fingerprint"], params.decision):
        raise ToolError("برای این نسخه هم‌زمان اقدام دیگری ثبت شده؛ وضعیت سند را بررسی کنید.")
    try:
        await account.client.send_decision(current, params.decision, params.note, prepared=prepared)
        ledger.update(account.identity, current["fingerprint"], "awaiting_confirmation")
        confirmed = next((item for item in await account.client.cartable(params.decision) if item["id"] == params.document_id), None)
        status = "processed" if confirmed else "awaiting_confirmation"
        ledger.update(account.identity, current["fingerprint"], status)
        return {"status": status, "document_id": params.document_id, "duplicate": False}
    except Exception:
        # The portal may have committed before a timeout. Never resend.
        ledger.update(account.identity, current["fingerprint"], "needs_review")
        return {"status": "needs_review", "document_id": params.document_id, "duplicate": False,
                "message": "نتیجه ارسال قطعی نیست؛ وضعیت کسرا را بررسی کنید. ارسال خودکار تکرار نمی‌شود."}


def register_manager_tools(mcp: FastMCP) -> None:
    @mcp.tool(annotations=READ)
    async def kasra_manager_get_profile() -> dict:
        """Get the verified managerial account identity. Returns {code, name}."""
        return await current_account().client.authenticate()

    @mcp.tool(annotations=READ)
    async def kasra_manager_get_monthly_report(page: Page, period: Annotated[str | None, Field(max_length=30)] = None) -> dict:
        """Read all accessible employees' monthly attendance, paginated. Returns period, title, items, total, has_more, next_offset. Source time/day units are preserved; leave used is not leave balance."""
        report = await current_account().client.monthly(period)
        return {"period": report["period"], "title": report["title"], **page_result(report["people"], page)}

    @mcp.tool(annotations=READ)
    async def kasra_manager_get_cartable(page: Page, status: Literal["pending", "approved", "rejected"] = "pending") -> dict:
        """Read managerial requests with document ID and version fingerprint for review. Paginated; returns items, total, has_more, next_offset. No decisions are made."""
        items = await current_account().client.cartable(status)
        # Agents need the stable version, not internal portal workflow fields.
        return page_result([{k: v for k, v in item.items() if k != "source"} for item in items], page)

    @mcp.tool(annotations=READ)
    async def kasra_manager_get_daily_report(personnel_code: Annotated[str, Field(pattern=r"^[0-9]{1,30}$")], jalali_date: Annotated[str, Field(pattern=r"^[0-9۰-۹]{4}/[0-9۰-۹]{2}/[0-9۰-۹]{2}$")]) -> dict:
        """Read one accessible employee's daily report for a Jalali date YYYY/MM/DD. Verifies the portal returned the selected personnel code. Returns {code, rows}."""
        return {"code": personnel_code, "rows": await current_account().client.daily(personnel_code, jalali_date)}

    @mcp.tool(annotations=READ)
    async def kasra_manager_get_sync_snapshot() -> dict:
        """Teamik sync snapshot: verified profile, full current monthly report and all three managerial cartables. All pages must succeed. Returns {profile, monthly, cartables, observed_at}; no partial success. For UI/agent exploration prefer paginated tools."""
        return await current_account().client.snapshot()

    @mcp.tool(annotations=WRITE)
    async def kasra_manager_decide_request(params: Decision) -> dict:
        """Send one explicitly human-approved managerial decision. Requires current document fingerprint and confirmed=true. Stale versions fail; a durable ledger prevents duplicate sends, including after restart/timeout. Returns status processed/awaiting_confirmation/needs_review, document_id, duplicate. Never approve autonomously or retry needs_review."""
        return await decide(params)
