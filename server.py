#!/usr/bin/env python3
"""
Kasra Lite MCP Server
Model Context Protocol server for interacting with the Kasra Lite Attendance & Personnel System (https://app.kasralite.com).
"""

from typing import Any, Dict, List, Optional
from fastmcp import FastMCP
from kasra_client import KasraClient

mcp = FastMCP(
    name="Kasra Lite Attendance Server",
    instructions="MCP Server for Kasra Lite Attendance, Personnel, Cartable, and Leave Management System (سامانه جامع کسرا)",
)


def _get_client() -> KasraClient:
    return KasraClient.get_instance()


@mcp.tool()
async def kasra_get_profile(reason: Optional[str] = None) -> Dict[str, Any]:
    """
    دریافت اطلاعات پروفایل کاربر جاری شامل نام، کد پرسنلی، شناسه شخص، واحد سازمانی، سمت و تاریخچه ورود‌های اخیر.
    Get current user profile, personnel ID, department, role, and recent login history.
    """
    client = _get_client()
    return await client.get_user_profile()


@mcp.tool()
async def kasra_get_dashboard_summary(reason: Optional[str] = None) -> Dict[str, Any]:
    """
    دریافت خلاصه داشبورد و وضعیت کلی تردد شامل:
    - آخرین تردد ثبت‌شده (ورود/خروج امروز و ساعت آن)
    - مانده کاردکس مرخصی (به ساعت)
    - کل کارکرد دوره (ساعت)
    - کل حضور دوره (ساعت)
    - اضافه‌کار و استحقاقی
    - موارد نیازمند مجوز (تردد ناقص، مازاد حضور، کسر حضور)
    - خلاصه وضعیت مجوز‌ها (در جریان، تأیید‌شده، رد‌شده)
    - تعداد درخواست‌های در انتظار کارتابل

    Get dashboard summary including last punch, cardex balance, total presence, worked hours, needed permissions, and requests status.
    """
    client = _get_client()
    return await client.get_dashboard_summary()


@mcp.tool()
async def kasra_get_daily_report(
    start_date: Optional[str] = None,
    end_date: Optional[str] = None,
    reason: Optional[str] = None,
) -> Dict[str, Any]:
    """
    دریافت گزارش تفصیلی کارکرد روزانه تردد (حضور، غیبت، تردد‌ها و مرخصی‌ها).
    برای هر روز شامل:
    - تاریخ و روز هفته
    - کلیه تردد‌های ثبت‌شده (ثبت ساعت‌های ورود و خروج)
    - میزان حضور، حضور در شیفت، مازاد حضور و کسر حضور
    - مرخصی ساعتی و روزانه (استحقاقی، استعلاجی)
    - مأموریت ساعتی و روزانه
    - دورکاری ساعتی و روزانه
    - نام شیفت و ساختار
    همچنین سطر جمع کل دوره در خروجی قرار دارد.

    Args:
        start_date: تاریخ شروع شمسی اختیاری به فرمت YYYY/MM/DD (مثال: '1405/06/01')
        end_date: تاریخ پایان شمسی اختیاری به فرمت YYYY/MM/DD (مثال: '1405/06/31')
        reason: دلیل اختیاری
    """
    client = _get_client()
    return await client.get_daily_report(start_date=start_date, end_date=end_date)


@mcp.tool()
async def kasra_get_punch_gaps(
    start_date: Optional[str] = None,
    end_date: Optional[str] = None,
    shift_start: str = "08:00",
    shift_end: str = "17:00",
    reason: Optional[str] = None,
) -> Dict[str, Any]:
    """
    تحلیل ترددها و شناسایی بازههایی که کارمند در ساعات کاری بیرون بوده (خلأ بین خروج و ورود بعدی در شیفت).
    مناسب برای شناسایی ساعاتی که نیاز به ثبت مرخصی ساعتی دارند.

    Analyze daily punches to find gaps where the employee was out during their shift.
    Returns structured gap data (date, gapStart, gapEnd, duration) suitable for submitting hourly leave.

    Args:
        start_date: تاریخ شروع شمسی اختیاری (مثال: '1405/04/01')
        end_date: تاریخ پایان شمسی اختیاری (مثال: '1405/04/31')
        shift_start: ساعت شروع شیفت (پیشفرض: '08:00')
        shift_end: ساعت پایان شیفت (پیشفرض: '17:00')
        reason: دلیل اختیاری
    """
    client = _get_client()
    return await client.get_punch_gaps(
        start_date=start_date,
        end_date=end_date,
        shift_start=shift_start,
        shift_end=shift_end,
    )


@mcp.tool()
async def kasra_get_work_periods(reason: Optional[str] = None) -> List[Dict[str, str]]:
    """
    دریافت لیست تمام دوره‌های کاری مالی و تردد موجود در سامانه (مانند شهریور 1405، مرداد 1405، ...).
    Get all available attendance and financial work periods with their IDs and titles.
    """
    client = _get_client()
    return await client.get_work_periods()


@mcp.tool()
async def kasra_get_monthly_report(
    period_id: Optional[str] = None,
    reason: Optional[str] = None,
) -> Dict[str, Any]:
    """
    دریافت گزارش سرجمع کارکرد ماهانه پرسنل.
    شامل کل حضور، کارکرد خالص، مازاد حضور، کسرحضور، اضافه‌کار، مرخصی‌ها و مأموریت‌های کل ماه.

    Args:
        period_id: شناسه دوره اختیاری (مثلاً '55' برای شهریور 1405). در صورت خالی بودن، دوره جاری لود می‌شود.
        reason: دلیل اختیاری
    """
    client = _get_client()
    return await client.get_monthly_report(period_id=period_id)


@mcp.tool()
async def kasra_get_cartable_items(
    tab: str = "in_wait",
    reason: Optional[str] = None,
) -> Dict[str, Any]:
    """
    مشاهده اسناد و درخواست‌های کارتابل گردش کار (اسناد دریافتی برای بررسی، تأیید یا ارجاع).

    Args:
        tab: تب مورد نظر:
             - "in_wait": کارتابل در انتظار (درخواست‌های منتظر اقدام شما)
             - "confirmed": تأیید‌شده‌ها
             - "rejected": رد‌شده‌ها
             - "refer": ارجاع‌شده‌ها
        reason: دلیل اختیاری
    """
    client = _get_client()
    return await client.get_cartable_items(tab=tab)


@mcp.tool()
async def kasra_get_credit_types(reason: Optional[str] = None) -> Dict[str, Any]:
    """
    دریافت لیست کامل انواع مجوز‌ها، مرخصی‌ها، مأموریت‌ها و اضافه‌کارها با کد‌های سیستمی آنها.
    دسته‌ها شامل:
    1. کسر حضور ساعتی (استحقاقی ساعتی: 11001، استعلاجی ساعتی: 11011، مأموریت ساعتی: 11021، دورکاری ساعتی: 14087، تاخیر سرویس: 11067، ...)
    2. کسر حضور روزانه (استحقاقی روزانه: 11002، استعلاجی روزانه: 11012، مأموریت روزانه: 11022، مأموریت برونشهری: 11032، دورکاری روزانه: 14084، مرخصی بدون حقوق: 11042، ...)
    3. مازاد حضور (اضافه‌کار عادی: 11101، اضافه‌کار دورکاری: 14088، اضافه‌کار جمعه: 60023، اضافه‌کار تعطیل: 60025)
    """
    client = _get_client()
    return await client.get_credit_types()


@mcp.tool()
async def kasra_get_submitted_requests(
    start_date: str = "1404/01/01",
    end_date: str = "1405/12/29",
    reason: Optional[str] = None,
) -> Dict[str, Any]:
    """
    دریافت لیست درخواست‌های ثبت‌شده (مجوز‌ها، مرخصی‌ها، مأموریت‌ها) و وضعیت تأیید/رد آنها در بازه زمانی.

    Args:
        start_date: تاریخ شروع شمسی (پیش‌فرض: 1404/01/01)
        end_date: تاریخ پایان شمسی (پیش‌فرض: 1405/12/29)
        reason: دلیل اختیاری
    """
    client = _get_client()
    items = await client.get_submitted_requests(start_date=start_date, end_date=end_date)
    return {"count": len(items), "requests": items}


@mcp.tool()
async def kasra_submit_credit_request(
    credit_type_id: int,
    start_date: str,
    end_date: str,
    start_time: str = "",
    end_time: str = "",
    description: str = "",
    is_daily: Optional[bool] = None,
    reason: Optional[str] = None,
) -> Dict[str, Any]:
    """
    ثبت درخواست مجوز جدید (مرخصی ساعتی/روزانه، مأموریت ساعتی/روزانه، اضافه‌کار، دورکاری و ...).

    Args:
        credit_type_id: کد نوع مجوز (از خروجی ابزار kasra_get_credit_types).
                        مثالها:
                        - 11001: استحقاقی ساعتی
                        - 11002: استحقاقی روزانه
                        - 11011: استعلاجی ساعتی
                        - 11012: استعلاجی روزانه
                        - 11021: مأموریت ساعتی
                        - 11022: مأموریت روزانه
                        - 14087: دورکاری ساعتی
                        - 14084: دورکاری روزانه
                        - 11101: اضافه‌کار عادی
        start_date: تاریخ شروع شمسی به فرمت YYYY/MM/DD (مثال: '1405/06/30')
        end_date: تاریخ پایان شمسی به فرمت YYYY/MM/DD (مثال: '1405/06/30')
        start_time: ساعت شروع به فرمت HH:mm (مثال: '09:00') - الزامی برای مرخصی‌ها و مأموریت‌های ساعتی
        end_time: ساعت پایان به فرمت HH:mm (مثال: '12:00') - الزامی برای مرخصی‌ها و مأموریت‌های ساعتی
        description: توضیحات یا دلیل درخواست
        is_daily: True اگر روزانه باشد، False اگر ساعتی باشد. در صورت تعیین‌نشدن، بر اساس پر بودن ساعت‌ها تشخیص داده می‌شود.
        reason: دلیل اختیاری
    """
    client = _get_client()
    return await client.submit_credit_request(
        credit_type_id=credit_type_id,
        start_date=start_date,
        end_date=end_date,
        start_time=start_time,
        end_time=end_time,
        description=description,
        is_daily=is_daily,
    )


@mcp.tool()
async def kasra_delete_credit_request(
    doc_id: int,
    doc_type_id: int = 1,
    description: str = "",
    reason: Optional[str] = None,
) -> Dict[str, Any]:
    """
    حذف یا لغو درخواست مجوز یا مرخصی ثبت‌شده در سیستم.
    Cancel or delete a submitted permission, leave, mission, or remote work request.

    Args:
        doc_id: شناسه سند درخواست (از خروجی kasra_get_submitted_requests)
        doc_type_id: شناسه نوع سند (پیش‌فرض 1 برای ساعتی، 2 برای روزانه)
        description: توضیحات یا دلیل لغو
        reason: دلیل اختیاری
    """
    client = _get_client()
    return await client.delete_permission_request(
        doc_id=doc_id,
        doc_type_id=doc_type_id,
        description=description,
    )


@mcp.tool()
async def kasra_get_messages(reason: Optional[str] = None) -> Dict[str, Any]:
    """
    دریافت پیام‌ها، اعلانات و اطلاعیه‌های ارسالی سیستم برای کاربر جاری.
    Get user notifications, broadcast messages, and announcements.
    """
    client = _get_client()
    messages = await client.get_messages()
    return {"count": len(messages), "messages": messages}


@mcp.tool()
async def kasra_get_desktop_shortcuts(reason: Optional[str] = None) -> Dict[str, Any]:
    """
    دریافت میان‌برهای میز کار و صفحات برگزیده کاربر.
    Get user desktop shortcuts and bookmarked pages.
    """
    client = _get_client()
    favs = await client.get_desktop_favorites()
    return {"count": len(favs), "shortcuts": favs}


@mcp.tool()
async def kasra_get_system_shifts(reason: Optional[str] = None) -> Dict[str, Any]:
    """
    دریافت لیست کلیه شیفت‌های کاری تعریف‌شده در سامانه کسرا (شیفت اداری، شیفت بدون پنجشنبه، شیفت دورکاری و ...).
    Get all work shifts defined in Kasra system.
    """
    client = _get_client()
    shifts = await client.get_system_shifts()
    return {"count": len(shifts), "shifts": shifts}


@mcp.tool()
async def kasra_get_personnel_groups(reason: Optional[str] = None) -> Dict[str, Any]:
    """
    دریافت لیست کلیه گروه‌های پرسنلی تردد (مجوز‌های اضافه‌کار، تقلیل، دورکاری، شیفت‌ها و ...).
    Get all personnel groups and attendance policy groups.
    """
    client = _get_client()
    groups = await client.get_personnel_groups()
    return {"count": len(groups), "groups": groups}


@mcp.tool()
async def kasra_export_daily_report_excel(
    start_date: str = "1405/06/01",
    end_date: str = "1405/06/31",
    reason: Optional[str] = None,
) -> Dict[str, Any]:
    """
    صدور گزارش تفصیلی کارکرد روزانه تردد به صورت فایل اکسل (Excel / .xls) و دریافت لینک مستقیم دانلود آن.
    Export daily attendance report to an Excel spreadsheet file (.xls) with direct download link.

    Args:
        start_date: تاریخ شروع شمسی (مثال: '1405/06/01')
        end_date: تاریخ پایان شمسی (مثال: '1405/06/31')
        reason: دلیل اختیاری
    """
    client = _get_client()
    return await client.export_daily_report_excel(start_date=start_date, end_date=end_date)


@mcp.tool()
async def kasra_get_holidays(reason: Optional[str] = None) -> Dict[str, Any]:
    """
    دریافت تقویم تعطیلات رسمی ثبت‌شده در سیستم برای سال جاری.
    Get official calendar holidays defined in Kasra for the year.
    """
    client = _get_client()
    return await client.get_holidays()


@mcp.tool()
async def kasra_get_user_menu(reason: Optional[str] = None) -> List[Dict[str, Any]]:
    """
    دریافت ساختار کامل منوهای دسترسی کاربر در سامانه کسرا.
    Get the complete menu structure and subsystems available to the user.
    """
    client = _get_client()
    return await client.get_user_menu()


if __name__ == "__main__":
    mcp.run()
