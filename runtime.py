"""Account isolation, private HTTP credential envelopes and durable decisions."""
from __future__ import annotations

import asyncio
import base64
import hashlib
import hmac
import json
import logging
import os
import sqlite3
import time
from contextvars import ContextVar
from dataclasses import dataclass, field, replace
from pathlib import Path

from Crypto.Cipher import AES
from fastmcp.exceptions import ToolError
from fastmcp.server.dependencies import get_http_headers
from fastmcp.server.middleware import Middleware

from organization import KasraError, OrganizationalKasraClient
from personal import PersonalKasraClient

CURRENT: ContextVar["Account | None"] = ContextVar("kasra_account", default=None)
AAD = b"teamik-kasra-mcp:v1"


def service_key() -> bytes:
    try:
        key = base64.b64decode(os.environ["KASRA_MCP_ENVELOPE_KEY"], validate=True)
        if len(key) != 32:
            raise ValueError
        return key
    except (KeyError, ValueError):
        raise RuntimeError("KASRA_MCP_ENVELOPE_KEY must be a base64-encoded random 32-byte key") from None


def decode_credentials(envelope: str) -> dict:
    try:
        if len(envelope) > 8192:
            raise ValueError
        raw = base64.b64decode(envelope, validate=True)
        cipher = AES.new(service_key(), AES.MODE_GCM, nonce=raw[:12])
        cipher.update(AAD)
        data = json.loads(cipher.decrypt_and_verify(raw[28:], raw[12:28]))
        if (not isinstance(data, dict) or not isinstance(data.get("iat"), int)
                or abs(time.time() - data["iat"]) > 75
                or not all(isinstance(data.get(k), str) and 0 < len(data[k]) <= 500 for k in ("account", "username", "password"))
                or not isinstance(data.get("can_decide"), bool)
                or data.get("scope", "manager") not in ("manager", "personal")
                or not isinstance(data.get("can_submit", False), bool)
                or not isinstance(data.get("expected_code", ""), str)):
            raise ValueError
        return data
    except (ValueError, TypeError, KeyError, json.JSONDecodeError):
        raise ToolError("اعتبار اتصال MCP تأیید نشد؛ تنظیمات سرویس و ساعت سرورها را بررسی کنید.") from None


@dataclass
class AccountState:
    last_used: float = field(default_factory=time.time)
    retry_at: float = 0


@dataclass
class Account:
    client: OrganizationalKasraClient
    identity: str
    can_decide: bool
    scope: str = "manager"
    expected_code: str = ""
    can_submit: bool = False
    lock: asyncio.Lock = field(default_factory=asyncio.Lock)
    state: AccountState = field(default_factory=AccountState)

    @property
    def last_used(self): return self.state.last_used

    @last_used.setter
    def last_used(self, value): self.state.last_used = value

    @property
    def retry_at(self): return self.state.retry_at

    @retry_at.setter
    def retry_at(self, value): self.state.retry_at = value


class Accounts:
    def __init__(self) -> None:
        self.accounts: dict[str, Account] = {}

    async def get(self) -> Account:
        headers = get_http_headers()
        if os.getenv("KASRA_MCP_MODE") == "http":
            data = decode_credentials(headers.get("x-kasra-account", ""))
        else:
            data = {"account": "stdio", "username": os.getenv("KASRA_USERNAME", ""),
                    "password": os.getenv("KASRA_PASSWORD", ""), "can_decide": os.getenv("KASRA_ENABLE_DECISIONS") == "true",
                    "scope": os.getenv("KASRA_MCP_SCOPE", "manager"),
                    "expected_code": os.getenv("KASRA_EXPECTED_PERSONNEL_CODE", ""),
                    "can_submit": os.getenv("KASRA_ENABLE_PERSONAL_REQUESTS") == "true"}
        # iat changes per HTTP request; it must not create another login session.
        stable_data = {k: v for k, v in data.items() if k not in ("iat", "can_decide", "can_submit")}
        pool_key = hashlib.sha256(json.dumps(stable_data, sort_keys=True, ensure_ascii=False).encode()).hexdigest()
        for key, account in list(self.accounts.items()):
            if not account.lock.locked() and time.time() - account.last_used > 600:
                await account.client.close()
                del self.accounts[key]
        if pool_key not in self.accounts:
            if len(self.accounts) >= 32:
                idle = [(key, account) for key, account in self.accounts.items() if not account.lock.locked()]
                if not idle:
                    raise ToolError("ظرفیت اتصال MCP پر است؛ کمی بعد دوباره تلاش کنید.")
                oldest, retired = min(idle, key=lambda pair: pair[1].last_used)
                await retired.client.close()
                del self.accounts[oldest]
            # Ledger identity survives password/generation changes and restarts.
            identity = hashlib.sha256(("https://app.kasralite.com:" + data["username"]).encode()).hexdigest()
            scope = data.get("scope", "manager")
            factory = PersonalKasraClient if scope == "personal" else OrganizationalKasraClient
            self.accounts[pool_key] = Account(factory(data["username"], data["password"]), identity,
                data["can_decide"] if scope == "manager" else False, scope,
                data.get("expected_code", ""), data.get("can_submit", False) if scope == "personal" else False)
        account = self.accounts[pool_key]
        account.last_used = time.time()
        # Privileges belong to this capsule, never to a previously cached call.
        # The client, lock and backoff state are shared; grants are immutable copies.
        return replace(account, can_decide=data["can_decide"] if account.scope == "manager" else False,
                       can_submit=data.get("can_submit", False) if account.scope == "personal" else False)

    async def close(self) -> None:
        for account in self.accounts.values():
            await account.client.close()
        self.accounts.clear()


accounts = Accounts()


def current_account() -> Account:
    account = CURRENT.get()
    if account is None:
        raise ToolError("اتصال حساب برای این درخواست آماده نیست.")
    return account


class AccountMiddleware(Middleware):
    async def on_call_tool(self, context, call_next):
        account = await accounts.get()
        if account.scope == "personal" and not context.message.name.startswith("kasra_personal_"):
            raise ToolError("حساب شخصی فقط به ابزارهای اطلاعات و درخواست‌های خود فرد دسترسی دارد.")
        # Personal write tools do not have an explicit managerial review flow.
        if context.message.name in ("kasra_submit_credit_request", "kasra_delete_credit_request"):
            raise ToolError("ثبت یا حذف درخواست شخصی در نسخه تیمیک غیرفعال است؛ از فرم کسرا استفاده کنید.")
        if account.lock.locked():
            raise ToolError("عملیات دیگری برای همین حساب در حال انجام است؛ کمی بعد تلاش کنید.")
        async with account.lock:
            token = CURRENT.set(account)
            try:
                if account.retry_at > time.time():
                    raise ToolError(json.dumps({"code": "retry_later", "message": "کسرا موقتاً در انتظار تلاش مجدد است.", "retry_after": int(account.retry_at - time.time()) + 1}, ensure_ascii=False))
                async with asyncio.timeout(110):
                    return await call_next(context)
            except KasraError as error:
                account.retry_at = time.time() + error.retry_after
                # Only fixed internal labels; never include credentials, headers,
                # raw portal content, tool arguments, identity or employee data.
                logging.getLogger("kasra").warning("provider_error code=%s stage=%s reason=%s schema=%s",
                    error.code, account.client.stage, str(error),
                    json.dumps(account.client.diagnostic_schema, ensure_ascii=False))
                raise ToolError(json.dumps({"code": error.code, "message": str(error), "retry_after": error.retry_after}, ensure_ascii=False)) from None
            except TimeoutError:
                account.retry_at = time.time() + 120
                raise ToolError(json.dumps({"code": "operation_timeout", "retry_after": 120}, ensure_ascii=False)) from None
            finally:
                account.last_used = time.time()
                CURRENT.reset(token)


class DecisionLedger:
    """Insert BEFORE sending, unique per source version, never retry ambiguity."""

    def __init__(self, path: str | None = None):
        self.path = path or os.getenv("KASRA_DECISION_DB", str(Path.home() / ".local/state/kasra-mcp/decisions.sqlite"))
        parent = Path(self.path).parent
        parent.mkdir(mode=0o700, parents=True, exist_ok=True)
        fd = os.open(self.path, os.O_CREAT | os.O_RDWR, 0o600)
        os.close(fd)
        with self.connect() as db:
            db.execute("CREATE TABLE IF NOT EXISTS decisions (account TEXT, fingerprint TEXT, decision TEXT NOT NULL, status TEXT NOT NULL, created_at INTEGER NOT NULL, PRIMARY KEY(account, fingerprint))")

    def connect(self) -> sqlite3.Connection:
        return sqlite3.connect(self.path, timeout=5)

    def claim(self, account: str, fingerprint: str, decision: str) -> bool:
        try:
            with self.connect() as db:
                db.execute("INSERT INTO decisions VALUES (?, ?, ?, 'sending', ?)", (account, fingerprint, decision, int(time.time())))
            return True
        except sqlite3.IntegrityError:
            return False

    def status(self, account: str, fingerprint: str) -> str | None:
        with self.connect() as db:
            row = db.execute("SELECT status FROM decisions WHERE account=? AND fingerprint=?", (account, fingerprint)).fetchone()
            return row[0] if row else None

    def decision(self, account: str, fingerprint: str) -> str | None:
        with self.connect() as db:
            row = db.execute("SELECT decision FROM decisions WHERE account=? AND fingerprint=?", (account, fingerprint)).fetchone()
            return row[0] if row else None

    def update(self, account: str, fingerprint: str, status: str) -> None:
        with self.connect() as db:
            db.execute("UPDATE decisions SET status=? WHERE account=? AND fingerprint=?", (status, account, fingerprint))


class PrivateMcpHttp:
    """Internal service authentication, including initialize/list requests.

    Never expose this service directly to the internet. External agents use
    stdio. Authorization token and encrypted credential capsule are separate.
    """
    def __init__(self, app):
        self.app = app
        self.token = os.environ.get("KASRA_MCP_TOKEN", "")
        if len(self.token) < 32:
            raise RuntimeError("KASRA_MCP_TOKEN must contain at least 32 random characters")
        service_key()

    async def __call__(self, scope, receive, send):
        if scope["type"] == "http":
            headers = {k.decode().lower(): v.decode() for k, v in scope["headers"]}
            authorized = hmac.compare_digest(headers.get("authorization", ""), "Bearer " + self.token)
            if not authorized or headers.get("origin"):
                await send({"type": "http.response.start", "status": 401 if not authorized else 403, "headers": [(b"content-type", b"application/json")]})
                await send({"type": "http.response.body", "body": b'{"error":"Unauthorized"}'})
                return
        await self.app(scope, receive, send)
