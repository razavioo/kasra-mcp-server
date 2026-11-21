"""A real subprocess handshake using the official MCP client."""
import os
import sys
from pathlib import Path

from mcp import ClientSession, StdioServerParameters
from mcp.client.stdio import stdio_client


async def test_installed_source_runs_over_stdio_without_protocol_noise():
    main = Path(__file__).resolve().parents[1] / 'main.py'
    params = StdioServerParameters(command=sys.executable, args=[str(main)], env={
        **os.environ, 'KASRA_MCP_MODE': 'stdio', 'KASRA_USERNAME': '', 'KASRA_PASSWORD': '',
        'PYTHONDONTWRITEBYTECODE': '1',
    })
    async with stdio_client(params) as (reader, writer):
        async with ClientSession(reader, writer) as session:
            initialized = await session.initialize()
            assert initialized.serverInfo.name == 'kasra_mcp'
            tools = (await session.list_tools()).tools
            assert len(tools) == 28
            by_name = {tool.name: tool for tool in tools}
            assert by_name['kasra_manager_get_monthly_report'].annotations.readOnlyHint
            assert by_name['kasra_get_dashboard_summary'].annotations.readOnlyHint
            assert by_name['kasra_manager_decide_request'].annotations.destructiveHint
            invalid = await session.call_tool('kasra_manager_get_profile', {})
            assert invalid.isError
