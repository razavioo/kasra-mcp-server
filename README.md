# Kasra Lite Attendance & Personnel MCP Server

[![PyPI Version](https://img.shields.io/pypi/v/kasra-mcp-server.svg)](https://pypi.org/project/kasra-mcp-server/)
[![Python Version](https://img.shields.io/badge/python-3.10%2B-blue.svg)](https://www.python.org/downloads/)
[![MCP](https://img.shields.io/badge/Model%20Context%20Protocol-FastMCP-brightgreen.svg)](https://modelcontextprotocol.io/)
[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](https://opensource.org/licenses/MIT)

A Model Context Protocol (MCP) server for the **Kasra Lite Attendance, Personnel, Cartable, and Leave Management System** ([app.kasralite.com](https://app.kasralite.com)).

Connect your AI agents (Claude Desktop, OpenCode, Cursor, Windsurf, etc.) directly to Kasra Lite to view attendance, punches, leaves, cardex, cartable requests, work periods, and submit leave/mission requests autonomously.

> 🚀 **Published on PyPI & 100% Pure HTTP / Async API — No Headless Browser Required!**
> Runs via standard HTTP/2 APIs and AES-128-CBC encryption without downloading Chromium or running Playwright/Puppeteer. Automatically detects and routes Iranian enterprise traffic even when a foreign VPN (like Surfshark/v2ray) is active. Works on Linux, Docker, macOS, and Windows.

---

## ⚡ Quick Start (Zero-Install with `uvx`)

You don't need to clone this repository or install Python dependencies manually. Just configure your agent:

### Claude Desktop (`claude_desktop_config.json`)
```json
{
  "mcpServers": {
    "kasra": {
      "command": "uvx",
      "args": ["kasra-mcp-server@latest"],
      "env": {
        "KASRA_BASE_URL": "https://app.kasralite.com",
        "KASRA_USERNAME": "your_username",
        "KASRA_PASSWORD": "your_password"
      }
    }
  }
}
```

### OpenCode (`~/.config/opencode/opencode.jsonc` or `opencode.json`)
```json
{
  "mcp": {
    "kasra": {
      "type": "local",
      "command": [
        "uvx",
        "kasra-mcp-server@latest"
      ],
      "environment": {
        "KASRA_BASE_URL": "https://app.kasralite.com",
        "KASRA_USERNAME": "your_username",
        "KASRA_PASSWORD": "your_password"
      },
      "enabled": true
    }
  }
}
```

### Cursor (`.cursor/mcp.json`)
```json
{
  "mcpServers": {
    "kasra": {
      "command": "uvx kasra-mcp-server@latest",
      "env": {
        "KASRA_BASE_URL": "https://app.kasralite.com",
        "KASRA_USERNAME": "your_username",
        "KASRA_PASSWORD": "your_password"
      }
    }
  }
}
```

---

## 📦 Traditional Installation (`pip`)

```bash
pip install kasra-mcp-server
```

Then configure your agent using `kasra-mcp-server`:
```json
{
  "mcpServers": {
    "kasra": {
      "command": "kasra-mcp-server",
      "env": {
        "KASRA_BASE_URL": "https://app.kasralite.com",
        "KASRA_USERNAME": "your_username",
        "KASRA_PASSWORD": "your_password"
      }
    }
  }
}
```

---

### Option C: From Local Source

```bash
git clone https://github.com/razavioo/kasra-mcp-server.git
cd kasra-mcp-server
pip install -r requirements.txt
```

```json
{
  "mcpServers": {
    "kasra": {
      "command": "python3",
      "args": [
        "/path/to/kasra-mcp-server/main.py"
      ],
      "env": {
        "KASRA_BASE_URL": "https://app.kasralite.com",
        "KASRA_USERNAME": "your_username",
        "KASRA_PASSWORD": "your_password"
      }
    }
  }
}
```

---

## 🧪 Testing

Test all tools directly in python:
```bash
python3 test_tools.py
```

---

## 📄 License

This project is licensed under the [MIT License](LICENSE).

## Manager and personal tools

Six managerial tools and four isolated self-service tools extend the same FastMCP server:

| Tool | Behavior |
|---|---|
| `kasra_manager_get_profile` | Verify the authenticated account's identity |
| `kasra_manager_get_monthly_report` | All accessible employees; page/search; preserve source time/day units |
| `kasra_manager_get_cartable` | Pending/approved/rejected requests; document ID and stable version fingerprint |
| `kasra_manager_get_daily_report` | Selected employee and Jalali date; verify returned employee code |
| `kasra_manager_get_sync_snapshot` | Complete current monthly report and all cartables; fail if any page fails |
| `kasra_manager_decide_request` | One explicitly human-authorized decision; fresh version check, durable duplicate protection, readback |

| `kasra_personal_get_profile` | Verify a separately connected personal account before the user confirms linking |
| `kasra_personal_get_workspace` | Own cardex balance, work metrics, periods, reports, requests and permitted credit types |
| `kasra_personal_get_period_report` | Own attendance and requests for a verified Kasra period |
| `kasra_personal_submit_request` | One reviewed personal request; stable UUID, durable claim, business acknowledgement, no automatic resend |

The 18 existing tools keep their schemas and local stdio behavior. Private HTTP blocks the legacy submit and delete tools. The personal submission tool requires explicit confirmation and uses a durable submission ID.

## Local agent connection (stdio)

Python 3.11+ is required. Create an isolated environment and install this directory:

```sh
python3 -m venv .venv
.venv/bin/pip install -r requirements.lock
.venv/bin/pip install --no-deps .
```

Provide `KASRA_USERNAME` and `KASRA_PASSWORD` through the agent's secure environment. Do not put credentials in prompts or a tracked config file. Add an MCP server using the environment's `kasra-mcp-server` command. A host that supports `mcpServers` can use:

```json
{
  "mcpServers": {
    "kasra": {
      "command": "/absolute/path/to/.venv/bin/kasra-mcp-server"
    }
  }
}
```

Read access is the default. Set `KASRA_ENABLE_DECISIONS=true` only for the authorized manager. Each decision still requires `confirmed=true` after the human reviews the specific document, action, and fingerprint. A model-supplied boolean is an expression of the host's authorization, not an independent proof of human consent; the agent host must enforce its approval policy.

Examples:

- Monthly: `{"page":{"limit":25,"offset":0,"search":"00123"}}`.
- Requests: `{"page":{"limit":25},"status":"pending"}`.
- Daily: `{"personnel_code":"00123","jalali_date":"1405/07/01"}`.

## Private server connection (private Streamable HTTP)

Run the private service with `KASRA_MCP_MODE=http`. Set a random
`KASRA_MCP_TOKEN` of at least 32 characters and a base64-encoded 32-byte
`KASRA_MCP_ENVELOPE_KEY`. Clients send the bearer token and a short-lived
AES-256-GCM account envelope in `X-Kasra-Account`.

The Compose service uses the existing Docker network named by
`KASRA_MCP_NETWORK` and keeps the decision ledger in a named volume.
Start it with `docker compose -f compose.server.yml up -d --build`.
The service publishes no host port.

## Verification

```sh
.venv/bin/pip install 'pytest>=8,<10' 'pytest-asyncio>=1,<2'
.venv/bin/python -m pytest -q
```

Tests use HTTP mocks and exercise the MCP protocol through stdio and private HTTP.
