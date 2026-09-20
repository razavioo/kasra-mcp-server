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
