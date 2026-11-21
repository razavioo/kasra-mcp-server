"""Self-service access bound to the authenticated Kasra identity.

No caller-supplied personnel IDs, impersonation, bulk members or automatic writes.
Unknown report structures fail rather than becoming an empty successful report.
"""
from __future__ import annotations

import json
import re
import time
from typing import Any

from bs4 import BeautifulSoup

from kasra_client import _normalize_jalali_date, _period_month, _jalali_month_bounds
from organization import (ORIGIN, KasraError, OrganizationalKasraClient, compact,
                          csrf_headers, grid, inputs, monthly_grid, setting, value, day_calculation_metadata, daily_display_values)


def script_array(markup: str, key: str) -> list[dict]:
    match = re.search(re.escape(key) + r"\s*=\s*(\[.*?\]);", markup, re.S)
    try:
        data = json.loads(match.group(1)) if match else None
    except ValueError:
        data = None
    if not isinstance(data, list) or not all(isinstance(row, dict) for row in data):
        raise KasraError("ساختار اطلاعات حساب شخصی کسرا شناسایی نشد.")
    return data


def plain(value_: Any, limit: int = 1000) -> str:
    return BeautifulSoup(str(value_ or ""), "html.parser").get_text(" ", strip=True)[:limit]


def submission_result(data: Any) -> dict:
    # AjaxService returns a JSON string containing the actual business result.
    for _ in range(2):
        if isinstance(data, str):
            try:
                data = json.loads(data)
            except ValueError:
                return {"status": "needs_review"}
    if not isinstance(data, dict) or not isinstance(data.get("Success"), bool):
        return {"status": "needs_review"}
    rows = data.get("Data")
    if data["Success"] is False or (isinstance(rows, list) and any(isinstance(r, dict) and r.get("Validate") in (0, False, "0") for r in rows)):
        return {"status": "rejected", "message": plain(data.get("Message") or next((r.get("Message") for r in rows or [] if isinstance(r, dict)), "کسرا درخواست را نپذیرفت."))}
    if not isinstance(rows, list) or len(rows) != 1 or not isinstance(rows[0], dict):
        return {"status": "needs_review"}
    row = rows[0]
    validation = str(row.get("Validate", ""))
    if not validation.isdigit() or int(validation) <= 0:
        return {"status": "needs_review"}
    doc_id = value(row, ["DocID", "CreditID", "DocumentID"])
    return {"status": "queued" if validation in ("20", "21") else "submitted",
            "message": plain(row.get("Message") or data.get("Message")),
            "document_id": doc_id if re.fullmatch(r"[0-9]+", doc_id) else None}


class PersonalKasraClient(OrganizationalKasraClient):
    async def dashboard(self) -> dict:
        await self.authenticate()
        self.stage = "personal_dashboard"
        works = script_array((await self.request("GET", "/Lego.Web/Widget/WorksResultWidget/WorksResultWidgetRender/")).text,
                             "WorksResultWidgetNameSpace.works")
        needed = script_array((await self.request("GET", "/Lego.Web/Widget/NeededCreditWidget/NeededCreditWidgetRender/")).text,
                              "NeededCreditWidgetNameSpace.JsonData")
        credit = BeautifulSoup((await self.request("GET", "/Lego.Web/Widget/CreditWidget/CreditWidgetRender/")).text, "html.parser")
        statuses = []
        for node in credit.select(".CurrentCreditPeriod .code-item"):
            title, amount = node.select_one(".code-title"), node.select_one(".code-value")
            if title and amount:
                statuses.append({"name": title.get_text(strip=True), "value": amount.get_text(strip=True)})
        if not works:
            raise KasraError("شاخص‌های کارکرد این حساب از کسرا دریافت نشد.")
        return {"period": plain(works[0].get("Workperiodname")),
                "metrics": [{"name": plain(w.get("CodeName")), "value": plain(w.get("Value")), "unit": plain(w.get("CodeNatureTitle"))} for w in works],
                "needed": [{"name": plain(n.get("CodeName")), "value": plain(n.get("PreviewValue")), "unit": plain(n.get("NatureTitle"))} for n in needed],
                "credit_status": statuses}

    async def periods(self) -> list[dict]:
        await self.authenticate()
        page = (await self.request("GET", "/TAPresentation/App_Pages/Reports/MainMonthlyReport")).text
        options = BeautifulSoup(page, "html.parser").select("select#ctl00_ContentPlaceHolder1_CmbPeriod option")
        periods = [{"id": o.get("value", ""), "title": o.get_text(strip=True)} for o in options if re.fullmatch(r"[0-9]{1,30}", o.get("value", ""))]
        if not periods:
            raise KasraError("دوره‌های کارکرد حساب از کسرا دریافت نشد.")
        return periods

    async def own_daily(self, period: str | None = None) -> dict:
        profile = await self.authenticate()
        context = self._own_daily_reports.get(period)
        if context is None or time.monotonic() - context["loaded_at"] > 60:
            monthly = await self.monthly(period, own=True)
            period = monthly["period"]
            context = self._own_daily_reports.get(period)
        if not context or context["person"]["id"] != self.person_id:
            raise KasraError("هویت داخلی گزارش شخصی آماده نیست.", code="identity_mismatch")
        person = context["person"]
        start, end = _jalali_month_bounds(*context["month"])
        params = {"personcode": person["code"], "personid": person["id"], "sdate": start, "edate": end,
            "requsterpageid": "1306", "requsteraction": "personcode", "sessionid": context["session"],
            "topersonid": context["online_user"], "onlineuser": context["online_user"], "wpid": context["period"],
            "personname": person["name"], "ParentMenuItemId": context["parent_menu"]}
        path = "/TAPresentation/App_Pages/Reports/maindailyreport"
        page = (await self.request("GET", path, params)).text
        fields = inputs(page)
        # All subsequent parsing is contingent on the server-selected person.
        actual = fields.get("ctl00_ContentPlaceHolder1_CmbPerson_txtPCode")
        if actual != profile["code"]:
            raise KasraError("هویت گزارش روزانه با حساب متصل یکسان نیست.", code="identity_mismatch")
        soup = BeautifulSoup(page, "html.parser")
        table = soup.find("table", id="ctl00_ContentPlaceHolder1_GrdDailyReport")
        if table is None:
            raise KasraError("جدول ترددهای روزانه از کسرا دریافت نشد.")
        headers, result, totals = [], [], None
        for index, row in enumerate(table.find_all("tr")):
            cells = [n.get_text(" ", strip=True) for n in row.find_all(["th", "td"], recursive=False)]
            if index == 0:
                headers = cells
                continue
            record = dict(zip(headers, cells))
            if any(v == "جمع" for v in cells[:2]):
                totals = record
                continue
            date = value(record, ["تاریخ"])
            if re.fullmatch(r"[0-9۰-۹]{4}/[0-9۰-۹]{2}/[0-9۰-۹]{2}", date):
                result.append({"date": _normalize_jalali_date(date), "values": daily_display_values(record), "meta": day_calculation_metadata(row)})
        if not any("تاريخ" in h or "تاریخ" in h for h in headers):
            raise KasraError("ستون تاریخ گزارش روزانه شناسایی نشد.")
        resolved = fields.get("ctl00_ContentPlaceHolder1_txtWorkPeriodID", "")
        if period is not None and resolved != period:
            raise KasraError("دورهٔ گزارش روزانه با دورهٔ انتخاب‌شده یکسان نیست.")
        return {"rows": result, "totals": totals, "period": resolved,
                "start_date": fields.get("ctl00_ContentPlaceHolder1_SDate", ""),
                "end_date": fields.get("ctl00_ContentPlaceHolder1_EDate", "")}

    async def catalog(self) -> list[dict]:
        await self.authenticate()
        if hasattr(self, "_catalog") and time.time() - self._catalog_at < 300:
            return self._catalog
        path = "/Lego.Web/TA/EnterCredit/EnterCredit"
        page = (await self.request("GET", path)).text
        headers = {**await self.ajax_get_headers({}, ORIGIN + path), **csrf_headers(page)}
        groups = await self.json_request("GET", "/Lego.Web/TA/EnterCredit/GetCreditKind", {}, headers=headers)
        if not isinstance(groups, list):
            raise KasraError("فهرست انواع درخواست کسرا شناسایی نشد.")
        catalog = []
        for group in groups:
            group_id = str(group.get("val", ""))
            if group_id == "0":
                continue
            if not group_id.isdigit():
                raise KasraError("گروه درخواست معتبر نیست.")
            params = {"creditType": group_id}
            types = await self.json_request("GET", "/Lego.Web/TA/EnterCredit/GetCreditTitle", params,
                headers={**await self.ajax_get_headers(params, ORIGIN + path), **csrf_headers(page)})
            if not isinstance(types, list):
                raise KasraError("نوع درخواست کسرا شناسایی نشد.")
            for item in types:
                type_id = str(item.get("val", ""))
                if type_id == "0":
                    continue
                if not type_id.isdigit():
                    raise KasraError("شناسهٔ نوع درخواست معتبر نیست.")
                catalog.append({"id": int(type_id), "title": plain(item.get("Title")),
                                "group_id": int(group_id), "group": plain(group.get("Title")), "daily": group_id == "2"})
        self._catalog, self._catalog_at = catalog, time.time()
        return catalog

    async def own_requests(self, start: str, end: str) -> list[dict]:
        profile = await self.authenticate()
        path = "/TAPresentation/App_Pages/Reports/DocInfoNew"
        page = (await self.request("GET", path)).text
        fields = inputs(page)
        if fields.get("ctl00_ContentPlaceHolder1_CmbPerson_txtPCode") != profile["code"]:
            raise KasraError("هویت گزارش درخواست‌ها با حساب یکسان نیست.", code="identity_mismatch")
        needed = ["ctl00_antiCsrfToken", "ctl00_keyToken", "ctl00_ContentPlaceHolder1_txtSessionID", "ctl00_ContentPlaceHolder1_txtGetCompanyFinatialPeriodID"]
        if not all(fields.get(k) for k in needed):
            raise KasraError("اطلاعات نشست گزارش درخواست‌های کسرا ناقص است.")
        headers = {"Referer": ORIGIN + path, "antiCsrfTokenValue": fields[needed[0]], "keyTokenValue": fields[needed[1]]}
        items = {}
        for page_no in range(1, 201):
            member_id = fields.get("ctl00_ContentPlaceHolder1_CmbPerson_txtCode", "")
            if not re.fullmatch(r"[0-9]+", member_id):
                raise KasraError("شناسهٔ داخلی شخص در گزارش درخواست‌ها شناسایی نشد.")
            body = {"DocMemberCode": member_id, "Accepter": "0", "DocState": "0", "DocType": "0",
                    "StartDate": start, "EndDate": end, "WPID": "0", "WPIDTo": "0", "StrFilter": "",
                    "CompanyID": fields[needed[3]], "SessionID": fields[needed[2]],
                    "pagesize": "50", "pagenumber": str(page_no), "MenuItemId": fields.get("ctl00_ContentPlaceHolder1_txtMenuItem", "13157")}
            response = await self.json_request("POST", path + ".are/GenerateGridXmlData", body, headers=headers)
            rows, total, columns = monthly_grid(response, page_no, 50)
            self.validate_page(rows, total, page_no, 50)
            for row in rows:
                observed_code = value(row, ["DocMemberCode", "PersonCode", "requesterCode"])
                if observed_code and observed_code != profile["code"]:
                    raise KasraError("هویت سند با حساب شخصی یکسان نیست.", code="identity_mismatch")
                doc_id = value(row, ["DocID", "ID"])
                if not re.fullmatch(r"[0-9]+", doc_id) or doc_id in items:
                    raise KasraError("شناسه یا صفحه‌بندی درخواست‌های شخصی معتبر نیست.")
                # Field aliases are structural; never expose internal workflow identifiers.
                items[doc_id] = {"id": doc_id, "type": value(row, ["DocTypeName", "CreditTypeName", "TypeName", "DocName"]),
                    "start_date": value(row, ["SDate", "StartDate"]), "end_date": value(row, ["EDate", "EndDate"]),
                    "status": value(row, ["DocStateName", "StateName", "StatusName", "DocStateTitle"]),
                    "description": plain(value(row, ["Description", "Descr", "RequesterDescr"])),
                    "start_time": value(row, ["STime", "StartTime"]), "end_time": value(row, ["ETime", "EndTime"])}
            if len(rows) < 50 or (total is not None and page_no * 50 >= total):
                return list(items.values())
        raise KasraError("دریافت همهٔ درخواست‌های شخصی تکمیل نشد.")

    async def period_report(self, period: str | None = None) -> dict:
        monthly = await self.monthly(period, own=True)
        daily = await self.own_daily(monthly["period"])
        start, end = daily["start_date"], daily["end_date"]
        try:
            if not _normalize_jalali_date(start) or not _normalize_jalali_date(end):
                raise ValueError
        except ValueError:
            raise KasraError("بازهٔ گزارش شخصی کسرا شناسایی نشد.") from None
        return {"monthly": monthly, "daily": daily, "requests": await self.own_requests(start, end)}

    async def workspace(self) -> dict:
        result = {"profile": await self.authenticate(), "sections": {}, "errors": {}}
        for name, read in [("dashboard", self.dashboard), ("periods", self.periods),
                           ("report", self.period_report), ("catalog", self.catalog)]:
            try:
                result["sections"][name] = await read()
            except KasraError as error:
                if error.code in ("session_rejected", "rate_limited", "portal_unreachable"):
                    raise
                result["errors"][name] = error.code
        return result

    async def prepare_submission(self, payload: dict) -> tuple[str, dict, str]:
        """Complete all reads/validation before a durable write claim is made."""
        await self.authenticate()
        selected = next((t for t in await self.catalog() if t["id"] == payload["credit_type_id"]), None)
        if selected is None:
            raise KasraError("این نوع درخواست برای حساب شما در کسرا قابل انتخاب نیست.")
        daily = selected["daily"]
        if payload["is_daily"] != daily:
            raise KasraError("نوع ساعتی یا روزانه با دسته‌بندی کسرا یکسان نیست.")
        if not daily and (not payload["start_time"] or not payload["end_time"]):
            raise KasraError("ساعت شروع و پایان درخواست را وارد کنید.")
        if not daily and payload["start_date"] == payload["end_date"] and payload["start_time"] >= payload["end_time"]:
            raise KasraError("ساعت پایان باید بعد از ساعت شروع باشد.")
        path = "/Lego.Web/TA/EnterCredit/EnterCredit"
        markup = (await self.request("GET", path)).text
        person_id = setting(markup, "EnterCreditNameSpace.PersonelID")
        page_id = setting(markup, "EnterCreditNameSpace.PageID")
        # No inherited multi-person/default-group selection is ever sent.
        if not person_id.isdigit() or person_id != self.person_id or not page_id.isdigit() or not csrf_headers(markup):
            raise KasraError("هویت فرم درخواست کسرا تأیید نشد؛ درخواستی ارسال نشد.", code="identity_mismatch")
        item = {"CreditID": 0, "Type": selected["group_id"], "CreditType": selected["id"],
                "DocTypeID": 2 if daily else 1, "MID": 0, "MType": "U", "Daily": 1 if daily else 0,
                "StartDate": payload["start_date"], "EndDate": payload["end_date"],
                "StartTime": "" if daily else payload["start_time"], "EndTime": "" if daily else payload["end_time"],
                "JPersonelID": 0, "Description": payload["description"], "Extended": [], "RowIndex": 0}
        members = [{"ID": 0, "InducteeID": 0, "MemberTypeID": 8134, "MemberTypeTitle": "پرسنل",
                    "MemberID": int(person_id), "MemberTitle": self.profile["name"], "IsNotIncluded": False,
                    "OperationType": "Add", "Description": ""}]
        body = compact({"CreditInfo": compact({"CreditInfo": [item]}), "Members": compact(members),
                        "PageID": page_id, "CorrectedMainDocId": 0})
        target = setting(markup, "EnterCreditNameSpace.ModifyUrl")
        if not target and re.search(r'EnterCreditNameSpace\.ModifyUrl\s*=\s*/Lego\.Web/\s*\+\s*["\']TA/EnterCredit/ModifyEnterCredit/["\']\s*;', markup):
            target = "/Lego.Web/TA/EnterCredit/ModifyEnterCredit/"
        # This tool only creates credits, never runs arbitrary discovered actions.
        if self.safe_url(target).rstrip('/') != ORIGIN + "/Lego.Web/TA/EnterCredit/ModifyEnterCredit":
            raise KasraError("مسیر ثبت درخواست کسرا شناسایی نشد؛ اقدامی ارسال نشد.")
        headers = {**await self.ajax_json_headers(body, ORIGIN + path), **csrf_headers(markup)}
        return target, headers, body

    async def send_submission(self, prepared: tuple[str, dict, str]) -> dict:
        target, headers, body = prepared
        response = await self.request("POST", target, raw=body, headers=headers)
        try:
            return submission_result(response.json())
        except ValueError:
            return {"status": "needs_review"}
