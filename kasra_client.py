"""
Pure HTTP Kasra Lite Client (No Browser, No Playwright, 100% API based)
Lightweight, cross-platform, fast, and publishable.
"""

import base64
import json
import os
import re
import subprocess
import time
from typing import Any, Dict, List, Optional, Tuple
from bs4 import BeautifulSoup
from Crypto.Cipher import AES
from Crypto.Util.Padding import pad
from dotenv import load_dotenv
import httpx

load_dotenv()

DEFAULT_BASE_URL = os.getenv("KASRA_BASE_URL", "https://app.kasralite.com")
DEFAULT_USERNAME = os.getenv("KASRA_USERNAME", "")
DEFAULT_PASSWORD = os.getenv("KASRA_PASSWORD", "")

_PERSIAN_DIGITS = str.maketrans("۰۱۲۳۴۵۶۷۸۹", "0123456789")
_ARABIC_DIGITS = str.maketrans("٠١٢٣٤٥٦٧٨٩", "0123456789")
_JALALI_MONTH_NAMES = {
    "فروردین": 1,
    "اردیبهشت": 2,
    "خرداد": 3,
    "تیر": 4,
    "مرداد": 5,
    "شهریور": 6,
    "مهر": 7,
    "آبان": 8,
    "آذر": 9,
    "دی": 10,
    "بهمن": 11,
    "اسفند": 12,
}


def _normalize_digits(value: str) -> str:
    return value.translate(_PERSIAN_DIGITS).translate(_ARABIC_DIGITS)


def _normalize_jalali_date(value: Optional[str]) -> Optional[str]:
    """Normalize and validate a Kasra Jalali date (YYYY/MM/DD)."""
    if value is None or not str(value).strip():
        return None
    normalized = _normalize_digits(str(value).strip()).replace("-", "/")
    parts = normalized.split("/")
    if len(parts) != 3 or not all(part.isdigit() for part in parts):
        raise ValueError(f"Invalid Jalali date {value!r}; expected YYYY/MM/DD")
    year, month, day = (int(part) for part in parts)
    if month <= 6:
        max_day = 31
    elif month <= 11:
        max_day = 30
    else:
        max_day = int(_jalali_month_bounds(year, month)[1][-2:])
    if year < 1 or not 1 <= month <= 12 or not 1 <= day <= max_day:
        raise ValueError(f"Invalid Jalali date {value!r}; expected YYYY/MM/DD")
    return f"{year:04d}/{month:02d}/{day:02d}"


def _date_key(value: str) -> Tuple[int, int, int]:
    normalized = _normalize_jalali_date(value)
    assert normalized is not None
    year, month, day = normalized.split("/")
    return int(year), int(month), int(day)


def _month_sequence(start: Tuple[int, int], end: Tuple[int, int]) -> List[Tuple[int, int]]:
    """Return every Jalali year/month in an inclusive range."""
    year, month = start
    result: List[Tuple[int, int]] = []
    while (year, month) <= end:
        result.append((year, month))
        month += 1
        if month == 13:
            year += 1
            month = 1
    return result


def _jalali_month_bounds(year: int, month: int) -> Tuple[str, str]:
    """Return valid bounds for one Jalali month."""
    if month <= 6:
        last_day = 31
    elif month <= 11:
        last_day = 30
    else:
        # Birashk's 2820-year cycle is also used by the common Jalali
        # conversion algorithms and avoids sending an invalid Esfand date.
        epbase = year - (474 if year >= 0 else 473)
        epyear = 474 + (epbase % 2820)
        last_day = 30 if ((epyear + 38) * 682) % 2816 < 682 else 29
    return f"{year:04d}/{month:02d}/01", f"{year:04d}/{month:02d}/{last_day:02d}"


def _period_month(title: str) -> Optional[Tuple[int, int]]:
    """Extract (year, month) from a Kasra period title such as «شهريور 1405»."""
    tokens = _normalize_digits(title.strip()).replace("ي", "ی").replace("ك", "ک").split()
    year = next((int(token) for token in tokens if token.isdigit() and len(token) == 4), None)
    if year is None:
        return None
    month = _JALALI_MONTH_NAMES.get(next((token for token in tokens if token in _JALALI_MONTH_NAMES), ""))
    return (year, month) if month is not None else None


def _detect_physical_local_ip() -> Optional[str]:
    """
    Detect physical network interface IP (e.g. en0, eth0, wlan0)
    to automatically bypass VPNs that route foreign IPs when Kasra Lite requires an Iranian IP.
    """
    env_ip = os.getenv("KASRA_LOCAL_IP")
    if env_ip:
        return env_ip
    try:
        out = subprocess.check_output(["ifconfig"], text=True)
        current_iface = None
        for line in out.splitlines():
            if not line.startswith("\t") and ":" in line:
                current_iface = line.split(":")[0]
            if "inet " in line and current_iface:
                if not any(bad in current_iface.lower() for bad in ["lo", "utun", "ipsec", "tun", "wg", "ppp"]):
                    m = re.search(r"inet (\d+\.\d+\.\d+\.\d+)", line)
                    if m:
                        return m.group(1)
    except Exception:
        pass
    return None


class KasraHTTPClient:
    _instance: Optional["KasraHTTPClient"] = None

    def __init__(
        self,
        base_url: str = DEFAULT_BASE_URL,
        username: str = DEFAULT_USERNAME,
        password: str = DEFAULT_PASSWORD,
    ):
        self.base_url = base_url.rstrip("/")
        self.username = username
        self.password = password

        self._client: Optional[httpx.AsyncClient] = None
        self._is_logged_in = False
        self._last_active_time = 0.0

    @classmethod
    def get_instance(cls) -> "KasraHTTPClient":
        if cls._instance is None:
            cls._instance = cls()
        return cls._instance

    def _ensure_client(self):
        if self._client is None or self._client.is_closed:
            local_ip = _detect_physical_local_ip()
            transport = httpx.AsyncHTTPTransport(local_address=local_ip) if local_ip else None
            self._client = httpx.AsyncClient(
                transport=transport,
                timeout=45.0,
                headers={
                    "User-Agent": "Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36",
                    "Origin": self.base_url,
                    "Accept": "*/*",
                    "Accept-Language": "fa,en;q=0.9,en-US;q=0.8",
                }
            )

    @staticmethod
    def _encrypt_password(password: str) -> str:
        """Kasra AES-128-CBC password encryption."""
        key = b'8080808080808080'
        iv = b'8080808080808080'
        cipher = AES.new(key, AES.MODE_CBC, iv)
        padded = pad(password.encode("utf-8"), AES.block_size)
        encrypted = base64.b64encode(cipher.encrypt(padded)).decode("utf-8")
        return "hashed_" + encrypted

    async def login(self, force: bool = False):
        self._ensure_client()
        if not force and self._is_logged_in and (time.time() - self._last_active_time < 900):
            return

        login_page_url = f"{self.base_url}/Lego.Web/Kevlar/Account/Login?ReturnUrl=%2FLego.Web%2F"
        r = await self._client.get(login_page_url)
        if r.status_code != 200:
            raise RuntimeError(f"Failed to load login page: HTTP {r.status_code}")

        soup = BeautifulSoup(r.text, "html.parser")
        form = soup.find("form", class_="login-form")
        if not form:
            raise RuntimeError("Could not find login form on page.")

        enc_pass = self._encrypt_password(self.password)

        inputs: Dict[str, Any] = {}
        for inp in form.find_all("input"):
            name = inp.get("name")
            val = inp.get("value", "")
            if name:
                inputs[name] = val

        inputs["UserName"] = self.username
        inputs["Password"] = enc_pass

        hidden_inputs: Dict[str, Any] = {}
        for inp in form.find_all("input"):
            inp_id = inp.get("id")
            name = inp.get("name")
            val = inp.get("value", "")
            if inp_id in ["UserName", "Password"]:
                hidden_inputs[inp_id] = inputs[inp_id]
            if inp.get("type") == "hidden" and name not in ["chkRequestData", "nonce"]:
                hidden_inputs[name] = val
            if inp_id == "captchaValue":
                hidden_inputs[inp_id] = val

        header_payload = json.dumps(hidden_inputs)

        header_res = await self._client.post(
            f"{self.base_url}/Lego.Web/api/Kevlar/RequestHeaderApi/GetHeader",
            headers={
                "Content-Type": "application/json",
                "X-Requested-With": "XMLHttpRequest",
                "Referer": login_page_url,
            },
            content=json.dumps({"data": header_payload}),
        )

        if header_res.status_code != 200:
            raise RuntimeError(f"GetHeader request failed: HTTP {header_res.status_code}")

        header_data = header_res.json()
        inputs["nonce"] = header_data.get("nonce", "")
        inputs["chkRequestData"] = header_data.get("checksum", "")

        login_res = await self._client.post(
            f"{self.base_url}/Lego.Web/Kevlar/Account/Login",
            data=inputs,
            headers={
                "Referer": login_page_url,
                "Origin": self.base_url,
                "Content-Type": "application/x-www-form-urlencoded",
            },
            follow_redirects=False,
        )

        if login_res.status_code not in [200, 302]:
            raise RuntimeError(f"Login failed: HTTP {login_res.status_code}")

        self._is_logged_in = True
        self._last_active_time = time.time()

    async def ensure_logged_in(self):
        if not self._is_logged_in or (time.time() - self._last_active_time > 900):
            await self.login(force=True)

    async def _get_request_header(self, data_str: str = "") -> Dict[str, str]:
        """Call RequestHeaderApi to generate nonce and checksum for Lego requests."""
        hres = await self._client.post(
            f"{self.base_url}/Lego.Web/api/Kevlar/RequestHeaderApi/GetHeader",
            headers={
                "Content-Type": "application/json",
                "X-Requested-With": "XMLHttpRequest",
            },
            content=json.dumps({"data": data_str}),
        )
        headers = {"X-Requested-With": "XMLHttpRequest"}
        if hres.status_code == 200:
            hd = hres.json()
            headers["nonce"] = hd.get("nonce", "")
            headers["chkRequestData"] = hd.get("checksum", "")
        return headers

    # -------------------------------------------------------------
    # 1. Profile & Dashboard
    # -------------------------------------------------------------
    async def get_user_profile(self) -> Dict[str, Any]:
        """Get current user details and recent login history."""
        await self.ensure_logged_in()
        r = await self._client.get(f"{self.base_url}/Lego.Web/")
        soup = BeautifulSoup(r.text, "html.parser")

        # Extract modelPerson from script
        model_person = []
        match = re.search(r'UserProfileNameSpace\.modelPerson\s*=\s*[\'"](.*?)[\'"];', r.text)
        if match:
            raw_str = match.group(1).replace("&quot;", '"')
            try:
                model_person = json.loads(raw_str)
            except Exception:
                pass

        person = model_person[0] if model_person else {}

        history_items = []
        for el in soup.find_all("div", class_="login-detail-wrapper"):
            title_el = el.find(class_="title")
            desc_el = el.find(class_="desc")
            if title_el and desc_el:
                history_items.append({
                    "ip": title_el.get_text(strip=True),
                    "detail": desc_el.get_text(strip=True),
                })

        return {
            "personId": person.get("Id"),
            "fullName": person.get("Name", ""),
            "personCode": person.get("CodeMask", self.username),
            "departmentId": person.get("DepartmentId"),
            "departmentName": (person.get("DepartmentName") or "").strip(),
            "role": (person.get("RoleTypeName") or "").strip(),
            "loginHistory": history_items,
        }

    async def get_dashboard_summary(self) -> Dict[str, Any]:
        """
        Get dashboard summary:
        - Work metrics (آخرین تردد, مانده کاردکس, کارکرد, کل حضور, اضافه‌کار, استحقاقی)
        - Needed credit items (تردد ناقص, مازاد حضور, کسر حضور)
        - Credit status counts (مجوز‌های در جریان, تأیید‌شده, رد‌شده)
        - Cartable items count
        """
        await self.ensure_logged_in()

        # Fetch widgets via pure HTTP
        works_res = await self._client.get(f"{self.base_url}/Lego.Web/Widget/WorksResultWidget/WorksResultWidgetRender/")
        needed_res = await self._client.get(f"{self.base_url}/Lego.Web/Widget/NeededCreditWidget/NeededCreditWidgetRender/")
        credit_res = await self._client.get(f"{self.base_url}/Lego.Web/Widget/CreditWidget/CreditWidgetRender/")
        cartable_res = await self._client.get(f"{self.base_url}/Lego.Web/Widget/CartableWidget/CartableWidgetRender/")

        # 1. Parse works
        works_list = []
        works_match = re.search(r'WorksResultWidgetNameSpace\.works\s*=\s*(\[.*?\]);', works_res.text, re.DOTALL)
        if works_match:
            try:
                works_list = json.loads(works_match.group(1))
            except Exception:
                pass

        # 2. Parse needed credits
        needed_list = []
        needed_match = re.search(r'NeededCreditWidgetNameSpace\.JsonData\s*=\s*(\[.*?\]);', needed_res.text, re.DOTALL)
        if needed_match:
            try:
                needed_list = json.loads(needed_match.group(1))
            except Exception:
                pass

        # 3. Parse credit status
        credit_soup = BeautifulSoup(credit_res.text, "html.parser")
        credit_status = {}
        curr_period = credit_soup.find("div", class_="CurrentCreditPeriod")
        if curr_period:
            for item in curr_period.find_all("div", class_="code-item"):
                title_el = item.find("div", class_="code-title")
                val_el = item.find("div", class_="code-value")
                if title_el and val_el:
                    credit_status[title_el.get_text(strip=True)] = val_el.get_text(strip=True)

        # 4. Parse cartable
        cartable_match = re.search(r'CartableWidgetNameSpace\.chartJsonData\s*=\s*(\[.*?\]);', cartable_res.text, re.DOTALL)
        cartable_items = []
        if cartable_match:
            try:
                cartable_items = json.loads(cartable_match.group(1))
            except Exception:
                pass

        return {
            "period": works_list[0].get("Workperiodname") if works_list else None,
            "workMetrics": [
                {
                    "name": w.get("CodeName"),
                    "value": w.get("Value"),
                    "unit": w.get("CodeNatureTitle"),
                }
                for w in works_list
            ],
            "neededCredits": [
                {
                    "name": n.get("CodeName"),
                    "count": str(n.get("PreviewValue", "")).strip(),
                }
                for n in needed_list
            ],
            "creditStatus": credit_status,
            "cartablePendingRequestsCount": len(cartable_items),
        }

    # -------------------------------------------------------------
    # 2. Attendance Reports
    # -------------------------------------------------------------
    @staticmethod
    def _input_value(soup: BeautifulSoup, element_id: str, default: str = "") -> str:
        element = soup.find(id=element_id)
        return str(element.get("value", default)).strip() if element else default

    @staticmethod
    def _parse_daily_page(
        html: str,
        start_date: Optional[str] = None,
        end_date: Optional[str] = None,
    ) -> Dict[str, Any]:
        soup = BeautifulSoup(html, "html.parser")
        table = soup.find("table", {"id": "ctl00_ContentPlaceHolder1_GrdDailyReport"})
        if not table:
            return {"count": 0, "records": [], "totals": None}

        rows = table.find_all("tr")
        if len(rows) < 2:
            return {"count": 0, "records": [], "totals": None}

        headers = [th.get_text(strip=True) for th in rows[0].find_all(["th", "td"])]
        records: List[Dict[str, Any]] = []
        totals = None
        start_key = _date_key(start_date) if start_date else None
        end_key = _date_key(end_date) if end_date else None

        for row in rows[1:]:
            cells = [td.get_text(strip=True).replace("\xa0", " ") for td in row.find_all(["td", "th"])]
            if len(cells) <= 1:
                continue

            row_dict = {header: cells[idx] for idx, header in enumerate(headers) if idx < len(cells)}
            row_idx = row_dict.get("رديف", "")
            if row_idx == "جمع" or "جمع" in row_dict.get("تاريخ", "") or "جمع" in str(row_idx):
                totals = row_dict
                continue

            rec_date = row_dict.get("تاريخ", "").strip()
            if not rec_date:
                continue
            try:
                rec_key = _date_key(rec_date)
            except ValueError:
                continue
            if start_key and rec_key < start_key:
                continue
            if end_key and rec_key > end_key:
                continue

            records.append({
                "row": row_idx,
                "date": _normalize_jalali_date(rec_date),
                "day": row_dict.get("روز", ""),
                "punches": row_dict.get("ترددها", ""),
                "totalPresence": row_dict.get("حضور", ""),
                "shiftPresence": row_dict.get("ح.شيفت", ""),
                "surplusPresence": row_dict.get("مازاد حضور", ""),
                "deficitPresence": row_dict.get("كسر حضور", ""),
                "overtime": row_dict.get("اضافه كار عادي", ""),
                "dailyLeave": row_dict.get("استحقاقي روزانه", ""),
                "hourlyLeave": row_dict.get("استحقاقي ساعتي", ""),
                "sickLeave": row_dict.get("استعلاجي", ""),
                "hourlyMission": row_dict.get("ماموريت ساعتي", ""),
                "dailyMission": row_dict.get("ماموريت روزانه", ""),
                "remoteHourly": row_dict.get("دور كاري ساعتي", ""),
                "remoteDaily": row_dict.get("دوركاري روزانه", ""),
                "shift": row_dict.get("شيفت", ""),
                "structure": row_dict.get("ساختار", ""),
            })

        return {"count": len(records), "records": records, "totals": totals}

    async def _get_daily_period_page(
        self,
        period_id: Optional[str] = None,
        start_date: Optional[str] = None,
        end_date: Optional[str] = None,
    ) -> Tuple[Dict[str, Any], Dict[str, str]]:
        """Load daily report through Kasra's period-aware report URL."""
        await self.ensure_logged_in()
        base_url = f"{self.base_url}/TAPresentation/App_Pages/Reports/MainDailyReport"
        initial = await self._client.get(base_url)
        initial_soup = BeautifulSoup(initial.text, "html.parser")
        metadata = {
            "personcode": self._input_value(initial_soup, "ctl00_ContentPlaceHolder1_CmbPerson_txtPCode"),
            "personid": self._input_value(initial_soup, "ctl00_ContentPlaceHolder1_txtpersonid"),
            "sessionid": self._input_value(initial_soup, "ctl00_ContentPlaceHolder1_txtSessionID"),
            "topersonid": self._input_value(initial_soup, "ctl00_ContentPlaceHolder1_txtOnLineUser"),
            "onlineuser": self._input_value(initial_soup, "ctl00_ContentPlaceHolder1_txtOnLineUser"),
            "parentmenuitemid": self._input_value(initial_soup, "ctl00_ContentPlaceHolder1_txtPageID", "1302"),
            "personname": self._input_value(initial_soup, "ctl00_ContentPlaceHolder1_CmbPerson_txtName"),
            "current_period_id": self._input_value(initial_soup, "ctl00_ContentPlaceHolder1_txtWorkPeriodID"),
            "current_start_date": self._input_value(initial_soup, "ctl00_ContentPlaceHolder1_SDate"),
            "current_end_date": self._input_value(initial_soup, "ctl00_ContentPlaceHolder1_EDate"),
        }
        target_period = str(period_id or metadata["current_period_id"])
        target_start = _normalize_jalali_date(start_date) or _normalize_jalali_date(metadata["current_start_date"])
        target_end = _normalize_jalali_date(end_date) or _normalize_jalali_date(metadata["current_end_date"])
        params = {
            "personcode": metadata["personcode"],
            "personid": metadata["personid"],
            "sdate": target_start or "",
            "edate": target_end or "",
            "requsterpageid": "1306",
            "requsteraction": "personcode",
            "sessionid": metadata["sessionid"],
            "topersonid": metadata["topersonid"],
            "onlineuser": metadata["onlineuser"],
            "wpid": target_period,
            "personname": metadata["personname"],
            "ParentMenuItemId": metadata["parentmenuitemid"],
        }
        response = await self._client.get(base_url, params=params)
        if response.status_code != 200:
            raise RuntimeError(f"Failed to load daily report for period {target_period}: HTTP {response.status_code}")
        return self._parse_daily_page(response.text, target_start, target_end), {
            "id": target_period,
            "startDate": target_start or "",
            "endDate": target_end or "",
        }

    async def get_daily_report(self, start_date: Optional[str] = None, end_date: Optional[str] = None) -> Dict[str, Any]:
        """Get daily attendance for the requested date range, including old work periods."""
        normalized_start = _normalize_jalali_date(start_date)
        normalized_end = _normalize_jalali_date(end_date)
        if normalized_start and normalized_end and _date_key(normalized_start) > _date_key(normalized_end):
            raise ValueError("start_date must not be after end_date")

        # Preserve the existing no-argument behaviour: the current work period is the default.
        if not normalized_start and not normalized_end:
            report, period = await self._get_daily_period_page()
            report["periods"] = [period]
            report["requestedRange"] = {"startDate": None, "endDate": None}
            return report

        periods = await self.get_work_periods()
        parsed_periods = [(period, _period_month(period.get("title", ""))) for period in periods]
        known = {month: period for period, month in parsed_periods if month is not None}
        available_months = sorted(known)
        if not available_months:
            return {
                "count": 0,
                "records": [],
                "totals": None,
                "periods": [],
                "missingPeriods": [],
                "requestedRange": {"startDate": normalized_start, "endDate": normalized_end},
            }

        first_month = (available_months[0] if not normalized_start else _date_key(normalized_start)[:2])
        last_month = (available_months[-1] if not normalized_end else _date_key(normalized_end)[:2])
        requested_months = _month_sequence(first_month, last_month)

        records: List[Dict[str, Any]] = []
        totals_by_period: List[Dict[str, Any]] = []
        loaded_periods: List[Dict[str, str]] = []
        missing_periods: List[str] = []
        for year_month in requested_months:
            period = known.get(year_month)
            if period is None:
                missing_periods.append(f"{year_month[0]:04d}/{year_month[1]:02d}")
                continue
            period_start, period_end = _jalali_month_bounds(*year_month)
            if normalized_start and _date_key(normalized_start) > _date_key(period_start):
                period_start = normalized_start
            if normalized_end and _date_key(normalized_end) < _date_key(period_end):
                period_end = normalized_end
            report, period_info = await self._get_daily_period_page(
                period_id=period.get("id"), start_date=period_start, end_date=period_end
            )
            records.extend(report.get("records", []))
            if report.get("totals") is not None:
                totals_by_period.append({"period": period_info, "totals": report["totals"]})
            period_info["title"] = period.get("title", "")
            loaded_periods.append(period_info)

        records.sort(key=lambda record: _date_key(record["date"]))
        totals: Any = None
        if len(totals_by_period) == 1:
            totals = totals_by_period[0]["totals"]
        elif totals_by_period:
            totals = [entry["totals"] for entry in totals_by_period]
        return {
            "count": len(records),
            "records": records,
            "totals": totals,
            "totalsByPeriod": totals_by_period,
            "periods": loaded_periods,
            "missingPeriods": missing_periods,
            "requestedRange": {"startDate": normalized_start, "endDate": normalized_end},
        }

    async def get_punch_gaps(
        self,
        start_date: Optional[str] = None,
        end_date: Optional[str] = None,
        shift_start: str = "08:00",
        shift_end: str = "17:00",
    ) -> Dict[str, Any]:
        """
        Analyze daily punches to find gaps (periods the employee was out during their shift).
        Returns structured gap data suitable for submitting hourly leave requests.

        Punch convention: times alternate entry/exit. Odd-indexed (1st, 3rd, ...) = entry, even-indexed (2nd, 4th, ...) = exit.
        A "gap" is the period between an exit and the next entry, if it falls within the shift window.

        Args:
            start_date: Persian start date YYYY/MM/DD (optional filter).
            end_date: Persian end date YYYY/MM/DD (optional filter).
            shift_start: Shift start time HH:mm (default "08:00") for bounding gaps.
            shift_end: Shift end time HH:mm (default "17:00") for bounding gaps.
        """
        report = await self.get_daily_report(start_date=start_date, end_date=end_date)
        records = report.get("records", [])

        all_gaps = []
        for rec in records:
            punches_raw = rec.get("punches", "").strip()
            if not punches_raw:
                continue

            # Parse punch times: split by common separators (space, dash, comma, -)
            times_str = re.split(r'[\s\-,\u200c]+', punches_raw)
            times = []
            for t in times_str:
                t = t.strip()
                if re.match(r'^\d{1,2}:\d{2}$', t):
                    times.append(t)

            if len(times) < 4:
                # Need at least 2 pairs (entry-exit-entry-exit) to have a gap
                continue

            # Build entry/exit pairs and find gaps between them
            date = rec.get("date", "")
            day = rec.get("day", "")
            deficit = rec.get("deficitPresence", "")

            pairs = []
            for i in range(0, len(times) - 1, 2):
                entry = times[i]
                exit_t = times[i + 1] if i + 1 < len(times) else None
                if exit_t:
                    pairs.append({"entry": entry, "exit": exit_t})

            # Gaps are between consecutive pairs: pair[n].exit -> pair[n+1].entry
            for i in range(len(pairs) - 1):
                gap_start = pairs[i]["exit"]
                gap_end = pairs[i + 1]["entry"]

                # Clamp gap to shift window
                if gap_start < shift_start:
                    gap_start = shift_start
                if gap_end > shift_end:
                    gap_end = shift_end

                # Validate: gap must be positive and within shift
                if gap_start >= gap_end:
                    continue
                if gap_start >= shift_end or gap_end <= shift_start:
                    continue

                # Calculate duration in minutes
                gs_h, gs_m = map(int, gap_start.split(":"))
                ge_h, ge_m = map(int, gap_end.split(":"))
                duration_min = (ge_h * 60 + ge_m) - (gs_h * 60 + gs_m)

                if duration_min <= 0:
                    continue

                all_gaps.append({
                    "date": date,
                    "day": day,
                    "gapStart": gap_start,
                    "gapEnd": gap_end,
                    "durationMinutes": duration_min,
                    "durationFormatted": f"{duration_min // 60}:{duration_min % 60:02d}",
                    "deficitPresence": deficit,
                    "punches": punches_raw,
                })

        total_minutes = sum(g["durationMinutes"] for g in all_gaps)
        return {
            "count": len(all_gaps),
            "totalMinutes": total_minutes,
            "totalFormatted": f"{total_minutes // 60}:{total_minutes % 60:02d}",
            "gaps": all_gaps,
            "shiftWindow": {"start": shift_start, "end": shift_end},
        }

    async def get_work_periods(self) -> List[Dict[str, str]]:
        """Get all available attendance work periods (دوره‌های کارکرد)."""
        await self.ensure_logged_in()
        res = await self._client.get(f"{self.base_url}/TAPresentation/App_Pages/Reports/MainMonthlyReport")
        soup = BeautifulSoup(res.text, "html.parser")
        select = soup.find("select", {"id": "ctl00_ContentPlaceHolder1_CmbPeriod"})
        if not select:
            return []
        periods = []
        for opt in select.find_all("option"):
            title = opt.get_text(strip=True)
            month = _period_month(title)
            periods.append({
                "id": opt.get("value", "").strip(),
                "title": title,
                "year": str(month[0]) if month else "",
                "month": str(month[1]) if month else "",
            })
        return periods

    async def get_monthly_report(self, period_id: Optional[str] = None) -> Dict[str, Any]:
        """
        Get monthly aggregate attendance report (کارکرد ماهانه).
        """
        await self.ensure_logged_in()
        m_page = await self._client.get(f"{self.base_url}/TAPresentation/App_Pages/Reports/MainMonthlyReport")
        soup = BeautifulSoup(m_page.text, "html.parser")

        anti_csrf = self._input_value(soup, "ctl00_antiCsrfToken")
        key_token = self._input_value(soup, "ctl00_keyToken")
        session_id = self._input_value(soup, "ctl00_ContentPlaceHolder1_txtSessionID")
        online_user = self._input_value(soup, "ctl00_ContentPlaceHolder1_txtOnLineUser")
        company_id = self._input_value(soup, "ctl00_ContentPlaceHolder1_txtCompanyID")
        person_code = self._input_value(soup, "ctl00_ContentPlaceHolder1_CmbPerson_txtCode")
        person_name = self._input_value(soup, "ctl00_ContentPlaceHolder1_CmbPerson_txtName")

        select = soup.find("select", {"id": "ctl00_ContentPlaceHolder1_CmbPeriod"})
        selected = select.find("option", selected=True) if select else None
        if selected is None and select:
            selected = select.find("option")
        wpid = str(period_id).strip() if period_id is not None and str(period_id).strip() else (
            selected.get("value", "").strip() if selected else self._input_value(soup, "ctl00_ContentPlaceHolder1_CmbPerson_txtSWPID")
        )
        period_title = selected.get_text(strip=True) if selected else ""
        if period_id and select:
            requested = select.find("option", value=str(period_id).strip())
            if requested:
                period_title = requested.get_text(strip=True)

        ajax_payload = {
            "ReportID": "1",
            "PersonID": online_user,
            "WPID": wpid,
            "DepartmentID": "0",
            "ChkChildren": "0",
            "ChkShowManager": "0",
            "GroupID": "0",
            "ShiftID": "0",
            "PageSize": "50",
            "PageNumber": "1",
            "CompanyID": company_id,
            "SessionID": session_id,
            "Search": person_code,
            "CreditNeed": "0",
            "SearchFlag": "1",
            "IsTotallyPerson": "0",
            "FilterWorkPeriodName": "",
            "FilterShiftName": "",
            "FilterGroupName": "",
            "FilterPersonName": person_name,
        }

        res = await self._client.post(
            f"{self.base_url}/TAPresentation/App_Pages/Reports/MainMonthlyReport.are/GenerateGridXmlData?SubmitMode=Ajax",
            headers={
                "Content-Type": "application/json; charset=utf-8",
                "X-Requested-With": "XMLHttpRequest",
                "Referer": f"{self.base_url}/TAPresentation/App_Pages/Reports/MainMonthlyReport",
                "antiCsrfTokenValue": anti_csrf,
                "keyTokenValue": key_token,
            },
            content=json.dumps(ajax_payload),
        )

        if res.status_code != 200:
            return {"period": wpid, "periodTitle": period_title, "data": None, "totals": None, "error": res.text}

        data_d = res.json().get("d", "")
        # The response format is <Root>...</Root>[ {json} ]
        json_start = data_d.find("[")
        if json_start != -1:
            try:
                rows = json.loads(data_d[json_start:])
                data_row = rows[0] if rows else None
                totals_row = rows[1] if len(rows) > 1 else None
                return {
                    "period": wpid,
                    "periodTitle": period_title,
                    "data": data_row,
                    "totals": totals_row,
                }
            except Exception:
                pass

        return {"period": wpid, "periodTitle": period_title, "raw": data_d}

    # -------------------------------------------------------------
    # 3. Cartable
    # -------------------------------------------------------------
    async def get_cartable_items(self, tab: str = "in_wait") -> Dict[str, Any]:
        """
        Get documents in Cartable (کارتابل).
        tab options: "in_wait" (در انتظار بررسی), "confirmed" (تأیید‌شده), "rejected" (رد‌شده), "refer" (ارجاع‌شده).
        """
        await self.ensure_logged_in()
        tab_urls = {
            "in_wait": f"{self.base_url}/Lego.Web/Frm/Cartable/CartableInWaitRender?personCode={self.username}&periodId=1311104",
            "confirmed": f"{self.base_url}/Lego.Web/Frm/Cartable/CartableConfirmedRender?personCode={self.username}&periodId=1311104",
            "rejected": f"{self.base_url}/Lego.Web/Frm/Cartable/CartableRejectedRender?personCode={self.username}&periodId=1311104",
            "refer": f"{self.base_url}/Lego.Web/Frm/Cartable/CartableReferRender?personCode={self.username}&periodId=1311104",
        }

        render_url = tab_urls.get(tab, tab_urls["in_wait"])
        r = await self._client.get(render_url, headers={"X-Requested-With": "XMLHttpRequest"})
        soup = BeautifulSoup(r.text, "html.parser")
        table = soup.find("table")
        items = []
        if table:
            rows = table.find_all("tr")
            if rows:
                headers = [th.get_text(strip=True) for th in rows[0].find_all(["th", "td"])]
                for r_item in rows[1:]:
                    cells = [td.get_text(strip=True) for td in r_item.find_all(["td", "th"])]
                    if cells:
                        row_dict = {}
                        for idx, h in enumerate(headers):
                            if h and idx < len(cells):
                                row_dict[h] = cells[idx]
                        items.append(row_dict)

        return {
            "tab": tab,
            "count": len(items),
            "items": items,
        }

    # -------------------------------------------------------------
    # 4. Credits & Requests (مرخصی، مأموریت، اضافه‌کار، دورکاری)
    # -------------------------------------------------------------
    async def get_credit_types(self) -> Dict[str, Any]:
        """Get all available credit and leave categories and types."""
        await self.ensure_logged_in()

        h1 = await self._get_request_header("")
        kinds_res = await self._client.get(
            f"{self.base_url}/Lego.Web/TA/EnterCredit/GetCreditKind",
            headers=h1,
        )
        kinds = kinds_res.json()

        categories = []
        for k in kinds:
            if k.get("val") == 0:
                continue
            h2 = await self._get_request_header(f"creditType={k.get('val')}")
            titles_res = await self._client.get(
                f"{self.base_url}/Lego.Web/TA/EnterCredit/GetCreditTitle?creditType={k.get('val')}",
                headers=h2,
            )
            titles = titles_res.json() if titles_res.status_code == 200 else []
            categories.append({
                "groupId": k.get("val"),
                "groupTitle": k.get("Title"),
                "types": [
                    {
                        "id": t.get("val"),
                        "title": t.get("Title"),
                        "policy": t.get("policy"),
                    }
                    for t in titles
                ],
            })

        return {"categories": categories}

    async def get_submitted_requests(
        self,
        start_date: str = "1404/01/01",
        end_date: str = "1405/12/29",
    ) -> List[Dict[str, Any]]:
        """
        Get all submitted requests (leaves, missions, overtimes) and their approval statuses.
        """
        await self.ensure_logged_in()
        doc_page = await self._client.get(f"{self.base_url}/TAPresentation/App_Pages/Reports/DocInfoNew")
        soup = BeautifulSoup(doc_page.text, "html.parser")

        anti_csrf = soup.find("input", {"id": "ctl00_antiCsrfToken"}).get("value", "")
        key_token = soup.find("input", {"id": "ctl00_keyToken"}).get("value", "")
        session_id = soup.find("input", {"id": "ctl00_ContentPlaceHolder1_txtSessionID"}).get("value", "")
        company_id_el = soup.find("input", {"id": "ctl00_ContentPlaceHolder1_txtGetCompanyFinatialPeriodID"})
        company_id = company_id_el.get("value", "99") if company_id_el else "99"
        person_code_el = soup.find("input", {"id": "ctl00_ContentPlaceHolder1_CmbPerson_txtCode"})
        person_code = person_code_el.get("value", self.username) if person_code_el else self.username
        menu_item_id = soup.find("input", {"id": "ctl00_ContentPlaceHolder1_txtMenuItem"}).get("value", "13157")

        payload = {
            "DocMemberCode": person_code,
            "Accepter": "0",
            "DocState": "0",
            "DocType": "0",
            "StartDate": start_date,
            "EndDate": end_date,
            "WPID": "0",
            "WPIDTo": "0",
            "StrFilter": "",
            "CompanyID": company_id,
            "SessionID": session_id,
            "pagesize": "100",
            "pagenumber": "1",
            "MenuItemId": menu_item_id,
        }

        res = await self._client.post(
            f"{self.base_url}/TAPresentation/App_Pages/Reports/DocInfoNew.are/GenerateGridXmlData",
            headers={
                "Content-Type": "application/json; charset=utf-8",
                "X-Requested-With": "XMLHttpRequest",
                "Referer": f"{self.base_url}/TAPresentation/App_Pages/Reports/DocInfoNew",
                "antiCsrfTokenValue": anti_csrf,
                "keyTokenValue": key_token,
            },
            content=json.dumps(payload),
        )

        if res.status_code != 200:
            return []

        d_str = res.json().get("d", "")
        if not d_str:
            return []

        parts = d_str.split("^")
        try:
            return json.loads(parts[0])
        except Exception:
            return []

    async def _get_inductee_members(self, page_id: str) -> List[Dict[str, Any]]:
        """
        Load default InducteeComponent members from the Lego framework.
        Returns the member list with correct field names expected by ModifyEnterCredit.
        """
        try:
            payload = json.dumps({"pageId": int(page_id)})
            req_headers = await self._get_request_header(payload)
            req_headers["Content-Type"] = "application/json; charset=UTF-8"
            res = await self._client.post(
                f"{self.base_url}/Lego.Web/Frm/InducteeComponent/GetInducteeMembers",
                headers=req_headers,
                content=payload,
            )
            if res.status_code == 200:
                data = res.json()
                # Response contains InducteeItemsTb array
                items = data if isinstance(data, list) else data.get("InducteeItemsTb", data.get("d", []))
                if isinstance(items, list) and items:
                    return items
        except Exception:
            pass
        return []

    async def submit_credit_request(
        self,
        credit_type_id: int,
        start_date: str,
        end_date: str,
        start_time: str = "",
        end_time: str = "",
        description: str = "",
        is_daily: Optional[bool] = None,
    ) -> Dict[str, Any]:
        """
        Submit a leave, mission, overtime, or remote work request.

        Args:
            credit_type_id: The ID of the credit type (e.g. 11001 for hourly leave, 11002 for daily leave, 14087 for hourly remote work, 11101 for regular overtime).
            start_date: Persian start date in format YYYY/MM/DD (e.g. "1405/06/30").
            end_date: Persian end date in format YYYY/MM/DD (e.g. "1405/06/30").
            start_time: Start time in format HH:mm (e.g. "09:00"). Required for hourly requests.
            end_time: End time in format HH:mm (e.g. "12:00"). Required for hourly requests.
            description: Optional explanation/note.
            is_daily: True if daily request, False if hourly. Auto-detected if not specified.
        """
        await self.ensure_logged_in()
        ec_page = await self._client.get(f"{self.base_url}/Lego.Web/TA/EnterCredit/EnterCredit")
        soup = BeautifulSoup(ec_page.text, "html.parser")

        # Find antiCsrfTokenValue and keyTokenValue
        anti_csrf_val = ""
        key_token_val = ""
        for inp in soup.find_all("input"):
            iid = inp.get("id", "")
            if iid.startswith("antiCsrfTokenValue_"):
                anti_csrf_val = inp.get("value", "")
            if iid.startswith("keyTokenValue_"):
                key_token_val = inp.get("value", "")

        # Extract PageID dynamically from the page
        page_id = "13158"
        page_id_match = re.search(r'EnterCreditNameSpace\.PageID\s*=\s*[\'"]?(\d+)[\'"]?\s*;', ec_page.text)
        if page_id_match:
            page_id = page_id_match.group(1)
        else:
            # Fallback: look for pageId in hidden inputs or other JS variables
            page_id_input = soup.find("input", {"id": re.compile(r"pageId|PageID", re.IGNORECASE)})
            if page_id_input:
                page_id = page_id_input.get("value", page_id)

        if is_daily is None:
            type_val = 1 if (start_time and end_time) else 2
        else:
            type_val = 2 if is_daily else 1

        json_item = {
            "CreditID": 0,
            "Type": type_val,
            "CreditType": credit_type_id,
            "DocTypeID": 2 if type_val == 2 else 1,
            "MID": 0,
            "MType": "U",
            "Daily": 1 if type_val == 2 else 0,
            "StartDate": start_date,
            "EndDate": end_date,
            "StartTime": start_time or "",
            "EndTime": end_time or "",
            "JPersonelID": 0,
            "Description": description or "",
            "Extended": [],
            "RowIndex": 0,
        }

        # Get person info dynamically from EnterCredit namespace
        person_id_match = re.search(r'EnterCreditNameSpace\.PersonelID\s*=\s*([0-9]+);', ec_page.text)
        person_id = int(person_id_match.group(1)) if person_id_match else 0
        display_name_match = re.search(r'EnterCreditNameSpace\.DisplayName\s*=\s*[\'"](.*?)[\'"];', ec_page.text)
        display_name = display_name_match.group(1) if display_name_match else f"{self.username}-"

        credit_info = json.dumps({"CreditInfo": [json_item]})

        # Load members from InducteeComponent endpoint (correct Kasra JS structure)
        inductee_members = await self._get_inductee_members(page_id)
        if inductee_members:
            members = json.dumps(inductee_members)
        else:
            # Fallback: construct member with correct field names matching JS structure
            members = json.dumps([{
                "ID": 0,
                "InducteeID": 0,
                "MemberTypeID": 8134,
                "MemberTypeTitle": "پرسنل",
                "MemberID": person_id,
                "MemberTitle": display_name,
                "IsNotIncluded": False,
                "OperationType": "Add",
                "Description": "",
            }])

        save_data = {
            "CreditInfo": credit_info,
            "Members": members,
            "PageID": page_id,
            "CorrectedMainDocId": 0,
        }

        # Serialize the body for GetHeader nonce/checksum generation
        body_str = json.dumps(save_data)

        # Get nonce + chkRequestData via GetHeader (required by Kasra's $.ajaxSetup beforeSend)
        req_headers = await self._get_request_header(body_str)

        headers = {
            "Content-Type": "application/json; charset=UTF-8",
            "X-Requested-With": "XMLHttpRequest",
            "Referer": f"{self.base_url}/Lego.Web/TA/EnterCredit/EnterCredit",
            "antiCsrfTokenValue": anti_csrf_val,
            "keyTokenValue": key_token_val,
            "nonce": req_headers.get("nonce", ""),
            "chkRequestData": req_headers.get("chkRequestData", ""),
        }

        res = await self._client.post(
            f"{self.base_url}/Lego.Web/TA/EnterCredit/ModifyEnterCredit/",
            headers=headers,
            content=body_str,
        )

        try:
            return {"success": True, "response": res.json()}
        except Exception:
            return {"success": res.status_code == 200, "response": res.text}

    async def delete_permission_request(
        self,
        doc_id: int,
        doc_type_id: int = 1,
        description: str = "",
    ) -> Dict[str, Any]:
        """
        حذف یا لغو درخواست مجوز ثبت‌شده.
        Delete or cancel a registered permission / leave / mission request.
        """
        await self.ensure_logged_in()
        doc_page = await self._client.get(f"{self.base_url}/TAPresentation/App_Pages/Reports/DocInfoNew")
        soup = BeautifulSoup(doc_page.text, "html.parser")

        anti_csrf = soup.find("input", {"id": "ctl00_antiCsrfToken"}).get("value", "")
        key_token = soup.find("input", {"id": "ctl00_keyToken"}).get("value", "")
        comp_period_id = int(soup.find("input", {"id": "ctl00_ContentPlaceHolder1_txtGetCompanyFinatialPeriodID"}).get("value", "99"))
        session_id = int(soup.find("input", {"id": "ctl00_ContentPlaceHolder1_txtSessionID"}).get("value", "0"))

        str_xml = f"<Root><DocInfo><DocID>{doc_id}</DocID><DocTypeID>{doc_type_id}</DocTypeID></DocInfo></Root>"
        del_payload = {
            "strXml": str_xml,
            "DescDelete": description,
            "GetCompanyFinatialPeriodID": comp_period_id,
            "SessionID": session_id,
        }

        res = await self._client.post(
            f"{self.base_url}/TAPresentation/App_Pages/Reports/DocInfoNew.are/DeleteDocInfo?SubmitMode=Ajax",
            headers={
                "Content-Type": "application/json; charset=utf-8",
                "X-Requested-With": "XMLHttpRequest",
                "Referer": f"{self.base_url}/TAPresentation/App_Pages/Reports/DocInfoNew",
                "antiCsrfTokenValue": anti_csrf,
                "keyTokenValue": key_token,
            },
            content=json.dumps(del_payload),
        )

        try:
            return {"success": res.status_code == 200, "response": res.json()}
        except Exception:
            return {"success": res.status_code == 200, "response": res.text}

    # -------------------------------------------------------------
    # 5. Additional System APIs: Shifts, Groups, Messages, Exports
    # -------------------------------------------------------------
    async def get_messages(self) -> List[Dict[str, Any]]:
        """دریافت پیام‌ها و اعلانات کاربری (User notifications/messages)."""
        await self.ensure_logged_in()
        r = await self._client.get(
            f"{self.base_url}/Lego.Web/Message/GetMessageList",
            headers={"X-Requested-With": "XMLHttpRequest"},
        )
        try:
            return r.json()
        except Exception:
            return []

    async def get_desktop_favorites(self) -> List[Dict[str, Any]]:
        """دریافت لیست میان‌برهای میز کار کاربر (Desktop favorites/bookmarks)."""
        await self.ensure_logged_in()
        r = await self._client.get(f"{self.base_url}/Lego.Web/Widget/DesktopWidget/DesktopWidgetRender/")
        match = re.search(r'DesktopWidgetPartialNameSpace\.favMenuJsonList\s*=\s*(\[.*?\]);', r.text, re.DOTALL)
        if match:
            try:
                return json.loads(match.group(1))
            except Exception:
                pass
        return []

    async def check_menu_bookmark(self, menu_item_id: int) -> bool:
        """بررسی نشان‌شده بودن یک منوی خاص (Check if a menu item is bookmarked)."""
        await self.ensure_logged_in()
        r = await self._client.get(f"{self.base_url}/Lego.Web/Desktop/IsBookMarkMenuItem?MenuItemId={menu_item_id}")
        return r.text.strip().lower() == "true"

    async def get_system_shifts(self) -> List[Dict[str, str]]:
        """دریافت لیست تمام شیفت‌های تعریف‌شده در سامانه (System defined work shifts)."""
        await self.ensure_logged_in()
        res = await self._client.get(f"{self.base_url}/TAPresentation/App_Pages/Reports/MainMonthlyReport")
        soup = BeautifulSoup(res.text, "html.parser")
        shift_select = soup.find("select", {"id": "ctl00_ContentPlaceHolder1_cmbShift"})
        if not shift_select:
            return []
        shifts = []
        for o in shift_select.find_all("option"):
            shifts.append({"id": o.get("value", ""), "title": o.get_text(strip=True)})
        return shifts

    async def get_personnel_groups(self) -> List[Dict[str, str]]:
        """دریافت لیست تمام گروه‌های پرسنلی سیستم (Personnel groups, overtime & remote permissions)."""
        await self.ensure_logged_in()
        res = await self._client.get(f"{self.base_url}/TAPresentation/App_Pages/Reports/MainMonthlyReport")
        soup = BeautifulSoup(res.text, "html.parser")
        grp_select = soup.find("select", {"id": "ctl00_ContentPlaceHolder1_cmbGroup"})
        if not grp_select:
            return []
        groups = []
        for o in grp_select.find_all("option"):
            groups.append({"id": o.get("value", ""), "title": o.get_text(strip=True)})
        return groups

    async def export_daily_report_excel(
        self,
        start_date: Optional[str] = None,
        end_date: Optional[str] = None,
    ) -> Dict[str, Any]:
        """
        ایجاد و دریافت آدرس دانلود فایل اکسل کارکرد روزانه (Generate & download daily attendance Excel).
        """
        await self.ensure_logged_in()
        daily_page = await self._client.get(f"{self.base_url}/TAPresentation/App_Pages/Reports/MainDailyReport")
        soup = BeautifulSoup(daily_page.text, "html.parser")

        anti_csrf = self._input_value(soup, "ctl00_antiCsrfToken")
        key_token = self._input_value(soup, "ctl00_keyToken")
        session_id = self._input_value(soup, "ctl00_ContentPlaceHolder1_txtSessionID")
        company_id = self._input_value(soup, "ctl00_ContentPlaceHolder1_txtCompanyID", "99")
        online_user = self._input_value(soup, "ctl00_ContentPlaceHolder1_txtOnLineUser")
        person_code = self._input_value(soup, "ctl00_ContentPlaceHolder1_CmbPerson_txtPCode", self.username)
        start_date = _normalize_jalali_date(start_date) or _normalize_jalali_date(
            self._input_value(soup, "ctl00_ContentPlaceHolder1_SDate")
        )
        end_date = _normalize_jalali_date(end_date) or _normalize_jalali_date(
            self._input_value(soup, "ctl00_ContentPlaceHolder1_EDate")
        )
        if not start_date or not end_date:
            raise RuntimeError("Kasra did not provide a valid daily report date range")
        if _date_key(start_date) > _date_key(end_date):
            raise ValueError("start_date must not be after end_date")

        excel_payload = {
            "PersonCode": person_code,
            "OnLineUserID": online_user,
            "MenuItemID": "1302",
            "CompanyID": company_id,
            "SessionID": session_id,
            "ReportID": "1",
            "SDate": start_date,
            "EDate": end_date,
            "CreditNeed": "0",
        }

        res = await self._client.post(
            f"{self.base_url}/TAPresentation/App_Pages/Reports/MainDailyReport.are/SendExcel?SubmitMode=Ajax",
            headers={
                "Content-Type": "application/json; charset=utf-8",
                "X-Requested-With": "XMLHttpRequest",
                "Referer": f"{self.base_url}/TAPresentation/App_Pages/Reports/MainDailyReport",
                "antiCsrfTokenValue": anti_csrf,
                "keyTokenValue": key_token,
            },
            content=json.dumps(excel_payload),
        )

        dl_url = f"{self.base_url}/TAPresentation/ExcelReport/RptGetPersonelDailyReport{person_code}.xls"
        return {
            "success": res.status_code == 200 and res.json().get("d") == "1",
            "downloadUrl": dl_url,
            "personCode": person_code,
            "startDate": start_date,
            "endDate": end_date,
        }

    # -------------------------------------------------------------
    # 6. Utilities: Holidays & User Menus
    # -------------------------------------------------------------
    async def get_holidays(self) -> Dict[str, Any]:
        """Get official calendar holidays defined in the system."""
        await self.ensure_logged_in()
        headers = await self._get_request_header("")
        r = await self._client.get(
            f"{self.base_url}/Lego.Web/api/Frm/DefineHolidayApi/GetHolidays",
            headers=headers,
        )
        return r.json()

    async def get_user_menu(self) -> List[Dict[str, Any]]:
        """Get list of user's accessible menus and navigation items."""
        await self.ensure_logged_in()
        headers = await self._get_request_header("")
        r = await self._client.get(
            f"{self.base_url}/Lego.Web/Menu/GetAllUserMenu",
            headers=headers,
        )
        return r.json()

    async def close(self):
        if self._client and not self._client.is_closed:
            await self._client.aclose()
        self._client = None
        self._is_logged_in = False


KasraClient = KasraHTTPClient
