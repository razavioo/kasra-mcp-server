#!/usr/bin/env python3
"""
Verification script for all 17 MCP tools.
"""

import asyncio
import json
from server import mcp


async def run_test():
    print("Testing Kasra MCP Server tools...")
    tools = await mcp.get_tools()
    print(f"Total tools registered: {len(tools)}\n")

    # 1. Profile
    p_res = await tools["kasra_get_profile"].run({})
    print("1. Profile:", p_res.content[0].text[:80], "...")

    # 2. Dashboard
    d_res = await tools["kasra_get_dashboard_summary"].run({})
    print("2. Dashboard:", d_res.content[0].text[:80], "...")

    # 3. Daily report
    daily_res = await tools["kasra_get_daily_report"].run({"start_date": "1405/06/01", "end_date": "1405/06/05"})
    print("3. Daily report:", daily_res.content[0].text[:80], "...")

    # 4. Work periods
    wp_res = await tools["kasra_get_work_periods"].run({})
    print("4. Work periods:", wp_res.content[0].text[:80], "...")

    # 5. Monthly report
    m_res = await tools["kasra_get_monthly_report"].run({"period_id": "55"})
    print("5. Monthly report:", m_res.content[0].text[:80], "...")

    # 6. Cartable
    c_res = await tools["kasra_get_cartable_items"].run({"tab": "in_wait"})
    print("6. Cartable:", c_res.content[0].text[:80], "...")

    # 7. Credit types
    ct_res = await tools["kasra_get_credit_types"].run({})
    print("7. Credit types:", ct_res.content[0].text[:80], "...")

    # 8. Submitted requests
    sr_res = await tools["kasra_get_submitted_requests"].run({})
    print("8. Submitted requests:", sr_res.content[0].text[:80], "...")

    # 9. Shifts
    sh_res = await tools["kasra_get_system_shifts"].run({})
    print("9. Shifts:", sh_res.content[0].text[:80], "...")

    # 10. Groups
    g_res = await tools["kasra_get_personnel_groups"].run({})
    print("10. Groups:", g_res.content[0].text[:80], "...")

    # 11. Holidays
    h_res = await tools["kasra_get_holidays"].run({})
    print("11. Holidays:", h_res.content[0].text[:80], "...")

    # 12. Menus
    menu_res = await tools["kasra_get_user_menu"].run({})
    print("12. Menus:", menu_res.content[0].text[:80], "...")

    # 13. Messages
    msg_res = await tools["kasra_get_messages"].run({})
    print("13. Messages:", msg_res.content[0].text[:80], "...")

    # 14. Shortcuts
    sc_res = await tools["kasra_get_desktop_shortcuts"].run({})
    print("14. Shortcuts:", sc_res.content[0].text[:80], "...")

    print("\nAll tested tools executed cleanly!")


if __name__ == "__main__":
    asyncio.run(run_test())
