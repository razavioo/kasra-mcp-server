"""Organizational extension of razavioo/kasra-mcp-server (MIT).

Uses the upstream client, password encoder and personal tools. No browser session
is borrowed. Unknown/truncated portal responses fail instead of erasing a sync.
"""
from __future__ import annotations

import base64
import hashlib
import html
import json
import re
import time
from typing import Any
from urllib.parse import urlsplit

import httpx
from bs4 import BeautifulSoup
from fastmcp.exceptions import ToolError

from kasra_client import KasraHTTPClient, _normalize_jalali_date, _period_month, _jalali_month_bounds

ORIGIN = "https://app.kasralite.com"

# These portal endpoints use different calendars. GetCartableFilter is a UI
# preset (often today only), not the scope of an organizational sync.
CARTABLE_GREGORIAN_RANGE = ("2000-01-01", "2099-12-31")
CARTABLE_JALALI_RANGE = ("1380/01/01", "1499/12/29")


class KasraError(ToolError):
    def __init__(self, message: str, retry_after: int = 60, *, code: str = "portal_response_invalid"):
        super().__init__(message)
        self.retry_after = retry_after
        self.code = code


def compact(value: Any) -> str:
    # Matches PHP JSON_UNESCAPED_UNICODE (including its escaped forward slash).
    return json.dumps(value, ensure_ascii=False, separators=(",", ":")).replace("/", "\\/").replace("\u2028", "\\u2028").replace("\u2029", "\\u2029")


def normalize(value: str) -> str:
    return re.sub(r"[\s_\-\u200c]+", "", value.translate(str.maketrans(
        "يك۰۱۲۳۴۵۶۷۸۹٠١٢٣٤٥٦٧٨٩", "یک01234567890123456789"))).lower()


def value(row: dict, names: list[str], default: str = "") -> str:
    normalized = {normalize(str(k)): v for k, v in row.items()}
    for name in names:
        result = normalized.get(normalize(name))
        if isinstance(result, (str, int, float)) and not isinstance(result, bool):
            return str(result).strip()
    return default


def inputs(markup: str) -> dict[str, str]:
    result = {}
    for node in BeautifulSoup(markup, "html.parser").find_all("input"):
        for key in ("id", "name"):
            if node.get(key):
                result[node[key]] = node.get("value", "")
    return result


def daily_display_values(record: dict) -> dict:
    """The portal repeats a shift name in the punch cell when no punch exists."""
    result = dict(record)
    for key, text in record.items():
        if normalize(str(key)) in {normalize(x) for x in ("ترددها", "تردد", "ورود و خروج")}:
            if normalize(str(text)).startswith(("ساختار", "شیفت")):
                result[key] = ""
    return result


def csrf_headers(markup: str) -> dict[str, str]:
    values = inputs(markup)
    anti = next((v for k, v in values.items() if k.startswith("antiCsrfTokenValue_") and v), "")
    key = next((v for k, v in values.items() if k.startswith("keyTokenValue_") and v), "")
    return {"antiCsrfTokenValue": anti, "keyTokenValue": key} if anti and key else {}


def gregorian_to_jalali(value: str) -> str:
    year, month, day = (int(part) for part in value[:10].split("-"))
    month_days = (0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334)
    gy = year + 1 if month > 2 else year
    days = 355666 + 365 * year + (gy + 3) // 4 - (gy + 99) // 100 + (gy + 399) // 400 + day + month_days[month - 1]
    if month > 2 and (year % 4 == 0 and (year % 100 != 0 or year % 400 == 0)):
        days += 1
    jy = -1595 + 33 * (days // 12053)
    days %= 12053
    jy += 4 * (days // 1461)
    days %= 1461
    if days > 365:
        jy += (days - 1) // 365
        days = (days - 1) % 365
    jm = 1 + days // 31 if days < 186 else 7 + (days - 186) // 30
    jd = 1 + (days % 31 if days < 186 else (days - 186) % 30)
    return f"{jy:04d}/{jm:02d}/{jd:02d}"


def cartable_dates(markup: str) -> tuple[str, str]:
    fields = inputs(markup)
    start, end = fields.get("FromDateCartable", ""), fields.get("ToDateCartable", "")
    defaults = re.findall(r"defaultValue\s*=\s*[\"']([0-9/-]+)[\"']", markup)
    types = re.findall(r"defaultValueType\s*=\s*[\"']([A-Za-z]+)[\"']", markup)
    if not start and defaults:
        start = defaults[0]
    if not end and defaults:
        end = defaults[-1]
    if types and types[0].lower() == "gregorian" and re.fullmatch(r"[0-9]{4}-[0-9]{2}-[0-9]{2}", start):
        start = gregorian_to_jalali(start)
    if types and types[-1].lower() == "gregorian" and re.fullmatch(r"[0-9]{4}-[0-9]{2}-[0-9]{2}", end):
        end = gregorian_to_jalali(end)
    return start, end


def setting(markup: str, name: str) -> str:
    match = re.search(re.escape(name) + r'''\s*=\s*(?:"((?:\\.|[^"\\])*)"|'((?:\\.|[^'\\])*)'|([0-9]+))\s*;''', markup)
    if not match:
        return ""
    return html.unescape(next((v for v in match.groups() if v is not None), "").replace("\\/", "/").replace('\\"', '"').replace("\\'", "'"))


def grid_schema(response: Any) -> dict:
    """Bounded field/tag names only. Never include row values or raw responses."""
    def names(keys):
        return [str(k)[:80] for k in list(keys)[:40] if re.fullmatch(r"[\w \-\u200c]+", str(k)) and not str(k).isdigit()]
    payload = response.get("d", response) if isinstance(response, dict) else response
    result = {"type": type(payload).__name__}
    if isinstance(payload, str):
        text = payload.strip()
        result["length"] = len(text)
        if text.startswith("<"):
            end = text.find("</Root>")
            if end >= 0:
                nodes = BeautifulSoup(text[:end + 7], "html.parser").find_all(True)
                result["xml_tags"] = names(dict.fromkeys(n.name for n in nodes))
                result["xml_attributes"] = names(dict.fromkeys(k for n in nodes for k in n.attrs))
                text = text[end + 7:].strip()
            else:
                result["xml_root_present"] = False
        try:
            payload, _ = json.JSONDecoder().raw_decode(text)
        except ValueError:
            result["json_decodable"] = False
            return result
    if isinstance(payload, dict):
        result["envelope_keys"] = names(payload)
        payload = payload.get("Data", payload.get("data"))
    if isinstance(payload, list):
        result["rows"] = len(payload)
        result["row_keys"] = names(payload[0]) if payload and isinstance(payload[0], dict) else []
    return result


def grid(response: Any) -> tuple[list[dict], int | None, dict[str, str]]:
    payload = response.get("d", response) if isinstance(response, dict) else response
    columns = {}
    if isinstance(payload, str):
        text = payload.strip()
        if text.startswith("<"):
            end = text.find("</Root>")
            if end < 0:
                raise KasraError("ساختار گزارش کسرا شناسایی نشد.")
            for node in BeautifulSoup(text[:end + 7], "html.parser").find_all(True):
                field = value(node.attrs, ["field", "DataField", "ColumnName", "Name"])
                label = value(node.attrs, ["title", "Caption", "HeaderText", "CodeName"])
                if field and label:
                    columns[field] = label
            text = text[end + 7:].strip()
        try:
            payload = json.loads(text.split("^", 1)[0])
        except ValueError:
            raise KasraError("پاسخ گزارش کسرا قابل خواندن نیست.") from None
    if isinstance(payload, list):
        rows, total = payload, None
    elif isinstance(payload, dict):
        rows = payload.get("Data", payload.get("data"))
        total = payload.get("Total", payload.get("total"))
        if payload.get("Errors") or payload.get("errors"):
            raise KasraError("کسرا دریافت فهرست را نپذیرفت.")
        if total is not None:
            if isinstance(total, bool) or not re.fullmatch(r"[0-9]+", str(total)):
                raise KasraError("تعداد ردیف‌های گزارش کسرا معتبر نیست.")
            total = int(total)
    else:
        raise KasraError("قالب گزارش کسرا معتبر نیست.")
    if not isinstance(rows, list) or not all(isinstance(row, dict) for row in rows):
        raise KasraError("ردیف‌های گزارش کسرا معتبر نیستند.")
    return rows, total, columns


METRICS = {
    "حضور": ["کل حضور", "TotalPresence"], "کارکرد": ["کارکرد", "Worked", "WorkTime"],
    "اضافه‌کار": ["اضافه کار پرداختی", "اضافه کار عادی", "Overtime"],
    "کسری": ["سرجمع کسر کار", "کسرحضور", "کسر حضور", "DeficitPresence"],
    "مرخصی روزانه": ["استحقاقی روزانه", "DailyLeave"], "مرخصی ساعتی": ["استحقاقی ساعتی", "HourlyLeave"],
    "مأموریت روزانه": ["ماموریت روزانه", "DailyMission"], "مأموریت ساعتی": ["ماموریت ساعتی", "HourlyMission"],
    "دورکاری روزانه": ["دورکاری روزانه", "RemoteDaily"], "دورکاری ساعتی": ["دور کاری ساعتی", "RemoteHourly"],
}


def monthly_person(row: dict, columns: dict) -> dict | None:
    # MainMonthlyReport appends a totals row with no FullName/person identity.
    if value(row, ["IsSum"]) == "1":
        return None
    row = {**row, **{label: row[field] for field, label in columns.items() if field in row}}
    code = value(row, ["PersonCode", "PCode", "CodeMask", "کد پرسنلی", "پرسنلی"])
    name = value(row, ["PersonName", "FullName", "PersonelName", "Name", "نام"])
    if name in ("جمع", "مجموع", "جمع کل") or value(row, ["RowNumber", "Row", "ردیف"]) == "جمع":
        return None
    if not code or not name:
        raise KasraError("ستون نام یا کد پرسنلی شناسایی نشد؛ قالب گزارش نیاز به تطبیق دارد.")
    return {"code": code, "name": name, "metrics": {label: value(row, aliases, "—") for label, aliases in METRICS.items()}}


def monthly_grid(response: Any, page: int, size: int) -> tuple[list[dict], int | None, dict[str, str]]:
    rows, total, columns = grid(response)
    metadata = ("MaxNumber", "PageNumber", "TotalPage")
    if any(key in row for row in rows for key in metadata):
        observed = set()
        for row in rows:
            fields = [value(row, [key]) for key in metadata]
            if not all(re.fullmatch(r"[0-9]+", field) for field in fields):
                raise KasraError("اطلاعات صفحه‌بندی گزارش کسرا ناقص است.")
            observed.add(tuple(int(field) for field in fields))
        if len(observed) != 1:
            raise KasraError("اطلاعات صفحه‌بندی گزارش کسرا یکسان نیست.")
        count, current, pages = observed.pop()
        if current != page or pages != max(1, (count + size - 1) // size) or (total is not None and total != count):
            raise KasraError("تعداد یا ترتیب صفحات گزارش کسرا معتبر نیست.")
        total = count
    people = []
    for row in rows:
        marker = value(row, ["IsSum"])
        if "IsSum" in row and marker not in ("0", "1"):
            raise KasraError("نوع ردیف گزارش کسرا شناسایی نشد.")
        if marker != "1":
            people.append(row)
    return people, total, columns


def cartable_item(row: dict, status: str) -> dict:
    doc_id = value(row, ["DocID"])
    if not re.fullmatch(r"[0-9]+", doc_id):
        raise KasraError("شناسه معتبر سند در کارتابل وجود ندارد.")
    stable = {key: value(row, [key]) for key in [
        "DocID", "DocTypeID", "Action", "StepNo", "ProcessInstanceId", "DocMemberID", "requesterCode",
        "requester", "doctypename", "SDate", "EDate", "RequestDate", "description", "RequesterDescr",
    ]}
    return {
        "id": doc_id, "code": value(row, ["requesterCode", "DocMemberCode", "PersonCode"]),
        "name": value(row, ["requester", "PersonName", "DocMemberName"]),
        "title": value(row, ["description", "Subject"]), "type": value(row, ["doctypename"]),
        "note": value(row, ["RequesterDescr"]), "requested_at": value(row, ["RequestDate"]),
        "status": status, "fingerprint": hashlib.sha256(compact(stable).encode()).hexdigest(), "source": row,
    }


def day_calculation_metadata(row: Any) -> dict:
    """Read the portal's own calculation bands and directional clock events.

    Unknown formats only disable automation; they never hide valid hour cells.
    Device IDs, personal annotations and the native personnel ID are omitted.
    """
    digits = str.maketrans("۰۱۲۳۴۵۶۷۸۹٠١٢٣٤٥٦٧٨٩", "01234567890123456789")
    pattern = re.compile(r"\{([^{}]+?)_([0-9]{2}:[0-9]{2})__([0-9]{2}:[0-9]{2})\}")

    def minute(text: str) -> int | None:
        if not re.fullmatch(r"[0-9]{2}:[0-9]{2}", text):
            return None
        h, m = map(int, text.split(":"))
        return h * 60 + m if 0 <= m < 60 and (0 <= h < 24 or (h == 24 and m == 0)) else None

    def bands(key: str) -> tuple[list[dict], bool]:
        raw = str(row.get(key, "")).translate(digits)
        result, position = [], 0
        for match in pattern.finditer(raw):
            if raw[position:match.start()].strip():
                return [], False
            label, end, start = match.groups()
            a, b = minute(start), minute(end)
            if a is None or b is None or a >= b:
                return [], False
            result.append({"label": label.strip(), "start": start, "end": end})
            position = match.end()
        return (result, True) if not raw[position:].strip() else ([], False)

    structure, valid_structure = bands("strtooltip")
    deficit, valid_deficit = bands("kasrtooltip")
    raw_events = str(row.get("inoutcomplete", "")).translate(digits)
    events, valid_events, previous = [], True, -1
    for raw in (part.strip() for part in raw_events.split(";") if part.strip()):
        match = re.fullmatch(r"([0-9]+),([^#]+)#([^/;#]{1,120})/([0-9]+)", raw)
        if not match:
            valid_events = False
            break
        kind, clock, reader, direction = match.groups()
        annotated = "@" in clock
        if annotated:
            # The official report uses the third @-segment for its visible clock.
            # Preserve no annotation text and never treat annotated input as a device proof.
            pieces = clock.split("@")
            if len(pieces) != 3:
                valid_events = False
                break
            clock = pieces[2]
        clock = clock.strip()
        timestamp = minute(clock)
        if timestamp is None or timestamp >= 1440 or timestamp < previous or direction not in ("1", "2"):
            valid_events = False
            break
        previous = timestamp
        # The vendor's MainDailyReport.js uses a textual CardReaderNo as well
        # as a numeric one. Kind 1 + direction 1/2 is its device branch; the
        # reader name/number stays private and is never returned in metadata.
        reader = reader.strip()
        if not reader or not any(ch.isalnum() for ch in reader) or not all(ch.isalnum() or ch in " _-." for ch in reader):
            valid_events = False
            break
        reader_label = normalize(reader)
        synthetic = reader in ("0", "-8", "999999") or "918274" in reader
        if re.fullmatch(r"-?[0-9]+", reader) and int(reader) <= 0:
            synthetic = True
        if any(word in reader_label for word in ("مجازی", "اصلاح", "فراموشی", "دورکاری", "نامشخص", "ناشناخته", "virtual", "manual", "correction", "remote", "unknown")):
            synthetic = True
        origin = "device" if not annotated and kind == "1" and not synthetic else "correction"
        events.append({"time": clock, "direction": "in" if direction == "1" else "out", "origin": origin})
    continuation = str(row.get("daycontinuation", "")).lower()
    overnight = False if continuation in ("0", "false") else (True if continuation in ("1", "2", "true") else None)
    return {"format_valid": valid_structure and valid_deficit and valid_events,
            "calculated": bool(structure or deficit), "structure_bands": structure, "deficit_bands": deficit,
            "events": events if valid_events else [], "events_valid": valid_events,
            "overnight": overnight, "holiday": str(row.get("holidayflag", "")).lower() in ("1", "true")}


class OrganizationalKasraClient(KasraHTTPClient):
    """Extends upstream tools with isolated, verified managerial portal access."""

    def __init__(self, username: str, password: str, transport: httpx.AsyncBaseTransport | None = None):
        super().__init__(ORIGIN, username, password)
        self.profile: dict | None = None
        self.person_id = ""
        self.stage = "idle"
        self.diagnostic_schema: dict = {}
        self._daily_reports: dict = {}
        self._own_daily_reports: dict = {}
        self._daily_periods: dict = {}
        self._client = httpx.AsyncClient(
            transport=transport, follow_redirects=False, trust_env=False,
            timeout=httpx.Timeout(25, connect=8), headers={"Origin": ORIGIN, "Accept-Language": "fa,en;q=0.9"},
        )

    @staticmethod
    def safe_url(path: str) -> str:
        if path.startswith("Frm/"):
            path = "/Lego.Web/" + path
        url = path if path.startswith("https://") else ORIGIN + "/" + path.lstrip("/")
        parts = urlsplit(url)
        if (parts.scheme != "https" or parts.netloc != "app.kasralite.com" or ".." in parts.path
                or not re.match(r"^/(Lego\.Web|TAPresentation)/", parts.path)):
            raise KasraError("نشانی خارج از محدوده مجاز کسراست؛ درخواستی ارسال نشد.")
        return url

    async def request(self, method: str, path: str, data: dict | None = None, *, authenticated: bool = True,
                      headers: dict | None = None, form: bool = False, raw: str | None = None) -> httpx.Response:
        url = self.safe_url(path)
        options: dict = {"headers": headers or {}}
        if method == "GET":
            options["params"] = data or {}
        elif raw is not None:
            options["content"] = raw.encode()
            options["headers"] = {"Content-Type": "application/json", **options["headers"]}
        else:
            options["data" if form else "json"] = data or {}
        try:
            response = await self._client.request(method, url, **options)
        except httpx.HTTPError:
            raise KasraError("ارتباط با کسرا کامل نشد؛ وضعیت قبلی حفظ شده است.", code="portal_unreachable") from None
        if response.status_code == 429:
            delay = response.headers.get("Retry-After", "60")
            raise KasraError("کسرا تعداد درخواست‌ها را محدود کرده است.", min(3600, max(60, int(delay) if delay.isdigit() else 60)), code="rate_limited")
        if response.status_code in (401, 403) or (authenticated and (response.is_redirect or 'class="login-form"' in response.text)):
            self._is_logged_in = False
            self.profile = None
            raise KasraError("نشست کسرا منقضی شده یا دسترسی کافی ندارد؛ ورود حساب را بررسی کنید.", 300, code="session_rejected")
        if not response.is_success and not (not authenticated and response.is_redirect):
            raise KasraError(f"کسرا درخواست را نپذیرفت. کد پاسخ: {response.status_code}", code="portal_http_error")
        return response

    async def json_request(self, method: str, path: str, data: dict, **kwargs: Any) -> Any:
        kwargs["headers"] = {"X-Requested-With": "XMLHttpRequest", **kwargs.pop("headers", {})}
        response = await self.request(method, path, data, **kwargs)
        try:
            return response.json()
        except ValueError:
            raise KasraError("پاسخ کسرا قابل خواندن نیست؛ قالب یا ورود حساب را بررسی کنید.") from None

    async def signature(self, raw: str, *, referer: str | None = None) -> dict[str, str]:
        result = await self.json_request("POST", "/Lego.Web/api/Kevlar/RequestHeaderApi/GetHeader", {"data": raw}, authenticated=False,
                                         headers={"Referer": referer} if referer else {})
        if not isinstance(result, dict) or not result.get("nonce") or not isinstance(result.get("checksum"), str) or (raw and not result["checksum"]):
            raise KasraError("کسرا امضای معتبر درخواست را صادر نکرد.", code="signature_rejected")
        return {"nonce": result["nonce"], "chkRequestData": result["checksum"]}

    async def ajax_get_headers(self, params: dict, referer: str) -> dict[str, str]:
        # MainSecurity.js signs the exact encoded query string of AJAX GETs.
        signed = await self.signature(str(httpx.QueryParams(params)), referer=referer)
        return {"X-Requested-With": "XMLHttpRequest", "Referer": referer, **signed}

    async def ajax_json_headers(self, raw: str, referer: str) -> dict[str, str]:
        return {"X-Requested-With": "XMLHttpRequest", "Referer": referer, **await self.signature(raw, referer=referer)}

    async def login(self, force: bool = False) -> None:
        if not force and self._is_logged_in and time.time() - self._last_active_time < 600:
            return
        self._is_logged_in = False
        self.profile = None
        self._daily_reports.clear()
        self._own_daily_reports.clear()
        self._daily_periods.clear()
        if not self.username or not self.password:
            raise KasraError("نام کاربری و رمز کسرا را در فرم امن اتصال وارد کنید.", 300, code="credentials_missing")
        path = "/Lego.Web/Kevlar/Account/Login"
        self.stage = "login_form"
        response = await self.request("GET", path, {"ReturnUrl": "/Lego.Web/"}, authenticated=False)
        forms = BeautifulSoup(response.text, "html.parser").select("form.login-form")
        if len(forms) != 1:
            raise KasraError("فرم ورود کسرا شناسایی نشد.", 300, code="login_form_changed")
        form = {}
        for node in forms[0].find_all("input", attrs={"name": True}):
            name = node["name"]
            form[name] = node.get("value", "")
            if node.get("id") == "captchaValue" and node.get("type") != "hidden" and node.has_attr("required"):
                raise KasraError("کسرا کد تصویری می‌خواهد؛ ورود خودکار در دسترس نیست.", 900, code="captcha_required")
        if form.get("CaptchaEnable", "").lower() == "true":
            raise KasraError("کسرا کد تصویری می‌خواهد؛ ورود خودکار در دسترس نیست.", 900, code="captcha_required")
        form["UserName"] = self.username
        form["Password"] = self._encrypt_password(self.password)
        # Match MainSecurity.js appendHeaderChkData exactly: credentials first,
        # hidden fields in DOM order next, captcha last; JSON.stringify keeps '/'.
        signed = {"UserName": form["UserName"], "Password": form["Password"]}
        for node in forms[0].select('input[type="hidden"][name]'):
            if node["name"] not in ("nonce", "chkRequestData"):
                signed[node["name"]] = form[node["name"]]
        for node in forms[0].select("input#captchaValue"):
            signed["captchaValue"] = node.get("value", "")
        referer = str(response.url)
        self.stage = "login_signature"
        form.update(await self.signature(json.dumps(signed, ensure_ascii=False, separators=(",", ":")), referer=referer))
        self.stage = "login_submit"
        await self.request("POST", path, form, authenticated=False, form=True, headers={"Referer": referer})
        self.stage = "login_profile"
        home = await self.request("GET", "/Lego.Web/", authenticated=False)
        try:
            people = json.loads(setting(home.text, "UserProfileNameSpace.modelPerson"))
            person = people[0]
            if not person["CodeMask"] or not person["Name"]:
                raise ValueError
        except (ValueError, TypeError, KeyError, IndexError):
            raise KasraError("ورود به کسرا تأیید نشد؛ نام کاربری، رمز و وضعیت حساب را بررسی کنید.", 300, code="login_not_confirmed") from None
        self.profile = {"code": str(person["CodeMask"]), "name": str(person["Name"])}
        self.person_id = str(person.get("Id", ""))
        self._is_logged_in = True
        self._last_active_time = time.time()

    async def authenticate(self) -> dict:
        await self.login()
        return self.profile

    async def monthly(self, period: str | None = None, *, own: bool = False) -> dict:
        await self.authenticate()
        self.stage = "monthly_report"
        path = "/TAPresentation/App_Pages/Reports/MainMonthlyReport"
        markup = (await self.request("GET", path)).text
        self.diagnostic_schema = {"select_ids": [s.get("id", "")[:100] for s in BeautifulSoup(markup, "html.parser").find_all("select")][:20]}
        values = inputs(markup)
        options = BeautifulSoup(markup, "html.parser").select("select#ctl00_ContentPlaceHolder1_CmbPeriod option")
        selected = next((o for o in options if o.get("value") == period), None) if period is not None else next((o for o in options if o.has_attr("selected")), options[0] if options else None)
        if selected is None:
            raise KasraError("دوره کارکرد در کسرا پیدا نشد.")
        period_id = selected.get("value", "")
        if not own:
            self._daily_periods = {m: o.get("value", "") for o in options if (m := _period_month(o.get_text(strip=True))) is not None}
        needed = ["ctl00_antiCsrfToken", "ctl00_keyToken", "ctl00_ContentPlaceHolder1_txtSessionID", "ctl00_ContentPlaceHolder1_txtOnLineUser", "ctl00_ContentPlaceHolder1_txtCompanyID"]
        if not all(values.get(key) for key in needed):
            raise KasraError("اطلاعات نشست گزارش کسرا ناقص است.")
        own_search = ""
        if own:
            # The live monthly page has no CmbPerson component. Its online
            # user is the signed-in identity; disable children and verify
            # every returned row before exposing anything to the caller.
            if not self.person_id or values[needed[3]] != self.person_id:
                raise KasraError("هویت گزارش ماهانه با حساب شخصی یکسان نیست.", code="identity_mismatch")
        headers = {"antiCsrfTokenValue": values[needed[0]], "keyTokenValue": values[needed[1]], "Referer": ORIGIN + path}
        people, native_people = {}, {}
        for page in range(1, 201):
            body = {
                "ReportID": "1", "PersonID": values[needed[3]], "WPID": period_id,
                "DepartmentID": "0", "ChkChildren": "0" if own else "1", "ChkShowManager": "0", "GroupID": "0", "ShiftID": "0",
                "PageSize": "50", "PageNumber": str(page), "CompanyID": values[needed[4]], "SessionID": values[needed[2]],
                "Search": own_search, "CreditNeed": "0", "SearchFlag": "1", "IsTotallyPerson": "0",
                "FilterWorkPeriodName": "", "FilterShiftName": "", "FilterGroupName": "", "FilterPersonName": "",
            }
            response = await self.json_request("POST", path + ".are/GenerateGridXmlData?SubmitMode=Ajax", body, headers=headers)
            self.diagnostic_schema = grid_schema(response)
            rows, total, columns = monthly_grid(response, page, 50)
            self.validate_page(rows, total, page, 50)
            for row in rows:
                person = monthly_person(row, columns)
                if person is not None:
                    if own and person["code"] != self.profile["code"]:
                        raise KasraError("گزارش شخصی با هویت حساب یکسان نیست.")
                    if person["code"] in people:
                        raise KasraError("صفحه یا کد پرسنلی تکراری است؛ دریافت کامل تأیید نشد.")
                    people[person["code"]] = person
                    internal_code = value(row, ["PCode"])
                    person_id = value(row, ["personelid", "PersonID"])
                    if re.fullmatch(r"[0-9]{1,30}", internal_code) and re.fullmatch(r"[0-9]{1,30}", person_id):
                        native_people[person["code"]] = {"code": internal_code, "id": person_id, "name": person["name"]}
            if len(rows) < 50 or (total is not None and page * 50 >= total):
                known_fields = {normalize(alias) for aliases in METRICS.values() for alias in aliases}
                has_metric_columns = any(normalize(str(field)) in known_fields for row in rows for field in row)
                if people and all(all(v in ("", "—") for v in p["metrics"].values()) for p in people.values()) and not (own and has_metric_columns):
                    raise KasraError("ستون‌های کارکرد شناسایی نشدند؛ قالب گزارش نیاز به تطبیق دارد.")
                month = _period_month(selected.get_text(strip=True))
                if own and month is not None:
                    identity = native_people.get(self.profile["code"])
                    if not identity or identity["id"] != self.person_id:
                        raise KasraError("شناسهٔ داخلی گزارش شخصی با حساب متصل یکسان نیست.", code="identity_mismatch")
                    self._own_daily_reports[period_id] = {"period": period_id, "month": month, "person": identity,
                        "session": values[needed[2]], "online_user": values[needed[3]],
                        "parent_menu": values.get("ctl00_ContentPlaceHolder1_txtPageID", ""), "loaded_at": time.monotonic()}
                if not own and month is not None:
                    self._daily_reports[month] = {"period": period_id, "people": native_people,
                        "session": values[needed[2]], "online_user": values[needed[3]],
                        "parent_menu": values.get("ctl00_ContentPlaceHolder1_txtPageID", ""), "loaded_at": time.monotonic()}
                return {"period": period_id, "title": selected.get_text(strip=True), "people": list(people.values())}
        raise KasraError("دریافت همه صفحات گزارش تکمیل نشد.")

    @staticmethod
    def validate_page(rows: list, total: int | None, page: int, size: int) -> None:
        if total is not None and len(rows) != min(size, max(0, total - (page - 1) * size)):
            raise KasraError("صفحه گزارش کسرا ناقص دریافت شد؛ اطلاعات قبلی حفظ شده است.")

    async def context(self, status: str) -> tuple[str, str]:
        profile = await self.authenticate()
        self.stage = "cartable"
        prefix = {"pending": "CartableInWait", "approved": "CartableConfirmed", "rejected": "CartableRejected"}.get(status)
        if prefix is None:
            raise KasraError("نوع کارتابل معتبر نیست.")
        # Vendor period-lookup preset; not a work-period year.
        # These are AJAX partial views (the portal loads them with jQuery.load).
        params = {"personCode": profile["code"], "periodId": 1311104}
        markup = (await self.request("GET", f"/Lego.Web/Frm/Cartable/{prefix}Render",
                                    params, headers=await self.ajax_get_headers(params, ORIGIN + "/Lego.Web/"))).text
        if prefix not in markup:
            raise KasraError("صفحه معتبر کارتابل از کسرا دریافت نشد.")
        return markup, prefix

    async def cartable(self, status: str = "pending") -> list[dict]:
        markup, prefix = await self.context(status)
        profile = self.profile or {}
        if status in ("approved", "rejected"):
            tab = "Confirmed" if status == "approved" else "Rejected"
            start_date, end_date = CARTABLE_JALALI_RANGE
            read_path = f"/Lego.Web/Frm/Cartable/{prefix}GridRead"
            read_base = {"startDate": start_date, "endDate": end_date, "ownerCode": profile.get("code", ""),
                         "periodLookup": "1311104", "tabName": tab, "requester": "0", "docType": "0", "filter": "",
                         "page": "1", "pageSize": "15", "take": "15", "skip": "0", "sort": "", "group": "", "aggregate": ""}
            items = {}
            for page in range(1, 201):
                params = {**read_base, "page": page, "skip": (page - 1) * 15}
                response = await self.json_request("GET", read_path, params,
                                                   headers={**await self.ajax_get_headers(params, ORIGIN + f"/Lego.Web/Frm/Cartable/{prefix}Render"),
                                                            **csrf_headers(markup)})
                self.diagnostic_schema = grid_schema(response)
                rows, total, _ = grid(response)
                self.validate_page(rows, total, page, 15)
                for row in rows:
                    item = cartable_item(row, status)
                    if item["id"] in items:
                        raise KasraError("صفحه‌بندی کارتابل تکراری است.")
                    items[item["id"]] = item
                if len(rows) < 15 or (total is not None and page * 15 >= total):
                    return list(items.values())
            raise KasraError("دریافت همه صفحات کارتابل تکمیل نشد.")
        tab = {"pending": "InWait", "approved": "Confirmed", "rejected": "Rejected"}[status]
        ns = prefix + "NameSpace"
        grid_key = {"pending": "InWaitGridUrl", "approved": "ConfirmedGridUrl", "rejected": "RejectedGridUrl"}[status]
        grid_path = setting(markup, f"{ns}.{grid_key}")
        if not grid_path:
            match = re.search(r"(?:Cartable[A-Za-z]+NameSpace)\.[A-Za-z]+GridUrl\s*=", markup)
            grid_path = setting(markup, match.group(0).split(" =", 1)[0]) if match else ""
        if not grid_path:
            raise KasraError("مسیر جدول کارتابل کسرا شناسایی نشد.")
        raw_filter = compact({"personCode": profile.get("code", ""), "periodId": "1311104"})
        filter_response = await self.request("POST", "/Lego.Web/Frm/Cartable/GetCartableFilter", raw=raw_filter,
                                             headers={**await self.ajax_json_headers(raw_filter, ORIGIN + "/Lego.Web/"), **csrf_headers(markup)})
        try:
            filter_data = filter_response.json()
        except ValueError:
            raise KasraError("فیلتر کارتابل کسرا قابل خواندن نیست.") from None
        start_date, end_date = CARTABLE_GREGORIAN_RANGE
        grid_params = {
            "docTypeId": "0", "ownerCode": profile.get("code", ""), "periodLookup": "1311104",
            "tabName": tab, "requester": "", "docType": "0", "depId": "0", "grpId": "0",
            "filterStr": "", "sdate": start_date, "edate": end_date,
        }
        grid_markup = (await self.request("GET", grid_path, grid_params,
                                          headers=await self.ajax_get_headers(grid_params, ORIGIN + "/Lego.Web/"))).text
        read_match = re.search(r"/Lego\.Web/[^\"']+_Read", grid_markup)
        read_path = html.unescape(read_match.group(0)) if read_match else ""
        if not read_path:
            raise KasraError("مسیر دریافت جدول کارتابل کسرا شناسایی نشد.")
        items = {}
        for page in range(1, 201):
            params = {key: value for key, value in grid_params.items() if key != "docTypeId"}
            params.update({"page": page, "pageSize": 15, "take": 15, "skip": (page - 1) * 15,
                           "sort": "", "filter": "", "group": "", "aggregate": ""})
            response = await self.json_request("GET", read_path, params,
                                               headers={**await self.ajax_get_headers(params, ORIGIN + grid_path),
                                                        **csrf_headers(grid_markup)})
            self.diagnostic_schema = grid_schema(response)
            rows, total, _ = grid(response)
            self.validate_page(rows, total, page, 15)
            for row in rows:
                item = cartable_item(row, status)
                if item["id"] in items:
                    raise KasraError("صفحه‌بندی کارتابل تکراری است.")
                items[item["id"]] = item
            if len(rows) < 15 or (total is not None and page * 15 >= total):
                return list(items.values())
        raise KasraError("دریافت همه صفحات کارتابل تکمیل نشد.")

    async def daily(self, code: str, jalali_date: str) -> list[dict]:
        date = _normalize_jalali_date(jalali_date)
        if date is None or not re.fullmatch(r"[0-9]{1,30}", code):
            raise KasraError("کد پرسنلی یا تاریخ معتبر نیست.")
        await self.authenticate()
        month = tuple(int(part) for part in date.split("/")[:2])
        context = self._daily_reports.get(month)
        if context is None or time.monotonic() - context["loaded_at"] > 60:
            if month not in self._daily_periods:
                await self.monthly()
            context = self._daily_reports.get(month)
            if context is None or time.monotonic() - context["loaded_at"] > 60:
                period = self._daily_periods.get(month)
                if not period:
                    raise KasraError("دوره کارکرد در کسرا پیدا نشد.")
                await self.monthly(period)
                context = self._daily_reports.get(month)
        if context is None or code not in context["people"]:
            raise KasraError("شناسهٔ داخلی پرسنل در گزارش مجاز کسرا شناسایی نشد؛ دادهٔ قبلی حفظ شد.")
        person = context["people"][code]
        start, end = _jalali_month_bounds(*month)
        # Match the portal's monthly-to-daily drilldown. PersonCode is the
        # display mask; PCode/personelid are distinct native query identities.
        params = {"personcode": person["code"], "personid": person["id"], "sdate": start, "edate": end,
            "requsterpageid": "1306", "requsteraction": "personcode", "sessionid": context["session"],
            "topersonid": context["online_user"], "onlineuser": context["online_user"],
            "wpid": context["period"], "personname": person["name"], "ParentMenuItemId": context["parent_menu"]}
        report = (await self.request("GET", "/TAPresentation/App_Pages/Reports/maindailyreport", params)).text
        fields = inputs(report)
        if fields.get("ctl00_ContentPlaceHolder1_CmbPerson_txtPCode") != code:
            raise KasraError("پرسنل گزارش روزانه با شخص انتخاب‌شده یکسان نیست.", code="identity_mismatch")
        if fields.get("ctl00_ContentPlaceHolder1_txtWorkPeriodID") != context["period"]:
            raise KasraError("دورهٔ گزارش روزانه با دورهٔ انتخاب‌شده یکسان نیست.")
        table = BeautifulSoup(report, "html.parser").find("table", id="ctl00_ContentPlaceHolder1_GrdDailyReport")
        if not table:
            raise KasraError("جدول گزارش روزانه موجود نیست.")
        headers, result = [], []
        for index, row in enumerate(table.find_all("tr")):
            cells = [n.get_text(" ", strip=True) for n in row.find_all(["th", "td"], recursive=False)]
            if index == 0:
                headers = cells
                continue
            record = dict(zip(headers, cells))
            record_date = value(record, ["تاریخ"])
            if re.fullmatch(r"[0-9۰-۹]{4}/[0-9۰-۹]{2}/[0-9۰-۹]{2}", record_date):
                if row.get("personcode") and row["personcode"] not in (code, person["code"]):
                    raise KasraError("پرسنل ردیف گزارش روزانه با شخص انتخاب‌شده یکسان نیست.", code="identity_mismatch")
                resolved_date = _normalize_jalali_date(record_date)
                if resolved_date[:7] != date[:7]:
                    raise KasraError("بازهٔ گزارش روزانه با دورهٔ انتخاب‌شده یکسان نیست.")
                result.append({"date": resolved_date, "values": daily_display_values(record), "meta": day_calculation_metadata(row)})
        return result

    async def prepare_decision(self, item: dict, decision: str, note: str) -> dict:
        """Validate and sign completely before the durable mutation claim."""
        if decision not in ("approved", "rejected"):
            raise KasraError("تصمیم معتبر نیست.")
        markup, _ = await self.context("pending")
        path = setting(markup, "CartableInWaitNameSpace.ModifyCasingWorkTableUrl")
        # Accept only the two exact vendor routes; never arbitrary Modify URLs.
        allowed_paths = {"/Lego.Web/Frm/Cartable/ModifyCasingWorkTable",
                         "/Lego.Web/api/Frm/CartableApi/ModifyCasingWorkTable"}
        parts = urlsplit(self.safe_url(path))
        if parts.path not in allowed_paths or parts.query or parts.fragment:
            raise KasraError("مسیر تأیید درخواست معتبر نیست؛ اقدامی ارسال نشد.")
        values = {key: setting(markup, "CartableInWaitNameSpace." + key) for key in ["SessionId", "CompanyID", "AccepterCode", "PeriodLookupId"]}
        if not all(values.values()):
            raise KasraError("اطلاعات مرحله تأیید کامل نیست؛ اقدامی ارسال نشد.")
        document = {key: value(item["source"], [alias]) for key, alias in {
            "RowNo": "RowNumber", "DocID": "DocId", "action": "Action", "DocTypeID": "DocTypeId",
            "PageNumber": "PageNumber", "StepNo": "StepNo", "ProcessInstanceId": "ProcessInstanceId",
        }.items()}
        if document["DocID"] != item["id"] or not document["DocTypeID"] or document["action"] in ("", "-4") or not document["PageNumber"]:
            raise KasraError("سند یا مرحله گردش کار برای این حساب معتبر نیست.")
        # Native date widgets have no HTML value until JS initializes them.
        # Select explicit document IDs only, with the same full date scope as
        # the cartable read; never depend on the widget's today-only preset.
        form = inputs(markup)
        if not all(key in form for key in ("FromDateCartable", "ToDateCartable")):
            raise KasraError("بازه کارتابل شناسایی نشد؛ اقدامی ارسال نشد.")
        document["ActorDesc"] = note
        body = {
            "ErjaStatus": 0, "IsSelectAllGrdChk": 0,
            "ActionData": compact({"Tb": [document], "ActionTb": {"Action": "1" if decision == "approved" else "2"}}),
            "CartablOwner": values["AccepterCode"], "periodLookup": values["PeriodLookupId"], "Requestor": "",
            "Department": 0, "Group": 0, "DocType": 0, "StrFilter": "", "SortStr": "", "PageNumber": document["PageNumber"],
            "SessionID": values["SessionId"], "CompanyID": values["CompanyID"], "Sdate": CARTABLE_JALALI_RANGE[0], "Edate": CARTABLE_JALALI_RANGE[1],
        }
        raw = compact(body)
        headers = {**await self.ajax_json_headers(raw, ORIGIN + "/Lego.Web/"), **csrf_headers(markup)}
        return {"path": path, "body": body, "raw": raw, "headers": headers}

    async def send_decision(self, item: dict, decision: str, note: str, *, prepared: dict | None = None) -> None:
        prepared = prepared or await self.prepare_decision(item, decision, note)
        self.stage = "decision_post"
        response = await self.json_request("POST", prepared["path"], prepared["body"], headers=prepared["headers"], raw=prepared["raw"])
        # MVC returns a JSON string; older ASMX wrappers use d/Data.
        result = response.get("d", response.get("Data")) if isinstance(response, dict) else response
        if result != "" and not (isinstance(result, str) and "WorkFlowQueue" in result):
            raise KasraError("نتیجه موفق قابل تأیید نیست؛ سند را دوباره بررسی کنید.")

    async def snapshot(self, period: str | None = None) -> dict:
        profile = await self.authenticate()
        monthly = await self.monthly(period)
        cartables = {status: await self.cartable(status) for status in ("pending", "approved", "rejected")}
        seen = set()
        for items in cartables.values():
            for item in items:
                if item["id"] in seen:
                    raise KasraError("کارتابل هنگام دریافت تغییر کرده؛ دریافت بعدی وضعیت را تطبیق می‌دهد.")
                seen.add(item["id"])
        return {"profile": profile, "monthly": monthly, "cartables": cartables, "observed_at": int(time.time())}
