"""Run upstream-compatible stdio or Teamik's PRIVATE Streamable HTTP service."""
import os

from dotenv import load_dotenv


def main() -> None:
    load_dotenv()
    from server import mcp

    if os.getenv("KASRA_MCP_MODE", "stdio") == "http":
        import uvicorn
        from runtime import PrivateMcpHttp

        app = PrivateMcpHttp(mcp.http_app(path="/mcp", stateless_http=True, json_response=True))
        uvicorn.run(app, host=os.getenv("KASRA_MCP_HOST", "127.0.0.1"), port=int(os.getenv("KASRA_MCP_PORT", "8000")), access_log=False, log_level="warning")
    else:
        mcp.run(transport="stdio", show_banner=False)


if __name__ == "__main__":
    main()
