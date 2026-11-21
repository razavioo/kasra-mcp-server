# بستهٔ اتصال به کسرا (Kasra Lite) — برای استفاده در سایت‌های دیگر

این بسته **مستقیم از کانتینرهای زندهٔ سرور تیمیک** برداشته شده است (۱۹ آبان ۱۴۰۵):
- کد Laravel از ایمیج `teamic-app:local-4187dc2f3e9d-bba0670e619e`
- سایدکار از ایمیج `teamic-kasra-mcp:reader-display-20261107-r1`

نسخهٔ روی مک قدیمی‌تر بود و این فایل‌ها را نداشت: `KasraDailyReport`، `KasraStatusCheck`، `KasraAccountBusy`، `KasraDecisionNotSent`، جاب‌های `SyncKasraPending`، `SyncKasraDailyRange`، `SendQueuedKasraDecision` و `CheckKasraRequestStatus`، و migration مربوط به اتصال جداگانهٔ هر مدیر.

## ساختار

```text
سایت شما (Laravel)  ──HTTP + MCP JSON-RPC──▶  kasra-mcp-sidecar (Python/FastMCP)  ──HTTPS──▶  app.kasralite.com
   KasraMcpClient                              فقط داخل شبکهٔ داکر، پورت 8000، بدون پورت عمومی
```

- **رفت (کسرا ← سایت):** پروفایل، گزارش ماهانهٔ همهٔ پرسنل، کارتابل (pending/approved/rejected)، گزارش روزانهٔ هر نفر. همگام‌سازی کامل هر ۱۵ ثانیه انجام می‌شود و درخواست‌های در انتظار هر ۵ ثانیه. گزارش روزانهٔ ساعت‌ها در صف کم‌اولویت جداگانه خوانده می‌شود.
- **برگشت (سایت → کسرا):** تأیید یا رد درخواست توسط مدیر (`kasra_manager_decide_request`)، و ثبت درخواست شخصی (`kasra_personal_submit_request`).
- رمز کسرا در دیتابیس سایت رمزنگاری می‌شود و برای هر فراخوانی داخل هدر `X-Kasra-Account` با AES-256-GCM فرستاده می‌شود. سایدکار رمز را ذخیره نمی‌کند.
- برای جلوگیری از ارسال تکراری، تصمیم‌ها با fingerprint نسخهٔ سند در یک دفتر SQLite در `/data/decisions.sqlite` ثبت می‌شوند. اگر نتیجه قطعی نباشد، وضعیت `needs_review` ثبت می‌شود و ارسال خودکار تکرار نمی‌شود.

## پوشه‌ها

| مسیر | محتوا |
|---|---|
| `kasra-mcp-sidecar/` | سرور MCP پایتون. پایه‌اش مخزن MIT `razavioo/kasra-mcp-server` است و ابزارهای مدیریتی و شخصی به آن اضافه شده. Dockerfile و تست هم دارد. |
| `laravel/app/Services/Hr/Kasra/` | کلاینت MCP، پارسر، سرویس همگام‌سازی، کارتابل، سرویس شخصی، کنترل دسترسی |
| `laravel/app/Models`, `Jobs`, `Console`, `Providers` | مدل‌ها، جاب‌های صف، فرمان زمان‌بند، بایندینگ |
| `laravel/app/Livewire/Hr`, `resources/views` | رابط کاربری مدیر و شخصی (Livewire) |
| `laravel/database/migrations/tenant/` | جدول‌ها: `kasra_connections`، `kasra_records`، `kasra_decisions`، `kasra_personal_*` |
| `laravel/config/kasra.php`, `docker-compose.kasra.yml` | تنظیمات و سرویس داکر |
| `kasra-mcp-sidecar/compose.server.yml` | compose واقعی سایدکار روی سرور (شبکهٔ external همان شبکهٔ اپ) |

## نصب روی یک سایت Laravel دیگر

1. پوشهٔ `laravel/` را روی ریشهٔ پروژه کپی کنید. پوشهٔ `kasra-mcp-sidecar/` را هم در `services/kasra-mcp/` بگذارید.
2. در `bootstrap/providers.php` این خط را اضافه کنید:
   ```php
   App\Providers\KasraServiceProvider::class,
   ```
3. در `routes/console.php`:
   ```php
   Schedule::command('attendance:sync-kasra')->everyFifteenSeconds()->withoutOverlapping(3)->onOneServer();
   Schedule::command('attendance:sync-kasra --pending-only')->everyFiveSeconds()->withoutOverlapping(1)->onOneServer();
   ```
   و در `config/queue.php` داخل `connections`:
   ```php
   'redis-kasra' => [
       'driver' => 'redis',
       'connection' => env('REDIS_EXTERNAL_QUEUE_CONNECTION', 'default'),
       'queue' => 'kasra-hours',
       'retry_after' => 480,
       'block_for' => null,
       'after_commit' => true,
   ],
   ```
   روی این کانکشن یک worker با هر دو صف بالا بیاورید. `kasra-fast` اول بیاید تا کار مدیر عقب نماند:
   ```bash
   php artisan queue:work redis-kasra --queue=kasra-fast,kasra-hours --timeout=170
   ```
4. در `.env` سایت دو مقدار تصادفی بسازید و همین دو مقدار را به سایدکار هم بدهید:
   ```bash
   echo "KASRA_MCP_TOKEN=$(openssl rand -hex 32)"
   ```
   ```bash
   echo "KASRA_MCP_ENVELOPE_KEY=$(openssl rand -base64 32)"
   ```
   طول توکن باید حداقل ۳۲ کاراکتر باشد. کلید باید دقیقاً ۳۲ بایت باشد (به صورت base64).
5. فایل compose را کنار compose اصلی اجرا کنید:
   ```bash
   docker compose -f docker-compose.yml -f docker-compose.kasra.yml up -d --build kasra-mcp app
   ```
6. migrationها را اجرا کنید (چهار فایل، به ترتیب تاریخ).
7. مدیر نام کاربری و رمز کسرا را **فقط در فرم اتصال داخل سایت** وارد کند، نه در `.env`، چت یا سورس.

## جاهایی که مخصوص تیمیک است و باید عوض شود

- **Multi-tenancy (stancl/tenancy):** کدها از `tenant()`، `tenancy()->initialize()`، `Tenant::` و migrationهای `tenant/` استفاده می‌کنند. در سایت تک‌سازمانی، این بخش‌ها را در `Jobs/SyncKasra*.php`، `Console/Commands/SyncKasraAttendance.php` و فیلد `account` در `KasraMcpClient::requestForAccount()` حذف کنید یا با یک شناسهٔ ثابت جایگزین کنید. migrationها را هم به `database/migrations/` منتقل کنید.
- **دسترسی‌ها:** `KasraAccess.php` از نقش‌های `admin` و `founder` و مجوزهای `attendance.manage`، `integrations.manage` و `users.manage` (spatie/permission) استفاده می‌کند.
- **وابستگی‌ها به مدل‌های تیمیک:** `App\Models\User`، `AttendanceMapping` (تطبیق کد پرسنلی با کاربر)، `CartableItem` (نمایش درخواست‌های کسرا در کارتابل عمومی) و `App\Helpers\Jalali`.
- **صف:** `redis-kasra` با صف‌های `kasra-fast` و `kasra-hours`. اگر صف دیگری دارید، در Jobها عوض کنید.
- **اتصال جداگانه برای هر مدیر:** بعد از migration `2026_11_04` هر مدیر اتصال کسرای خودش را دارد. کلید یکتا روی `(provider, configured_by)` است.
- **میزبان MCP:** `KasraMcpClient` فقط این میزبان‌ها را قبول می‌کند: `kasra-mcp`، `localhost` و `127.0.0.1`، با مسیر `/mcp`. این محدودیت امنیتی است و بهتر است بماند.
- **دامنهٔ کسرا:** `app.kasralite.com` در `organization.py` و `runtime.py` ثابت نوشته شده است. در `kasra_client.py` از `KASRA_BASE_URL` خوانده می‌شود. اگر سازمانی روی دامنهٔ دیگری است، هر سه جا را عوض کنید.

## استفاده بدون Laravel

سایدکار یک سرور MCP استاندارد است و از هر زبانی قابل استفاده است. برای هر فراخوانی این مراحل را انجام دهید:
1. `POST /mcp` با `Authorization: Bearer <TOKEN>`، `Accept: application/json, text/event-stream` و هدر `X-Kasra-Account`.
2. ترتیب پیام‌ها: `initialize` ← `notifications/initialized` ← `tools/call`.
3. ساختار `X-Kasra-Account`: `base64(nonce12 + tag16 + ciphertext)`. الگوریتم AES-256-GCM است و AAD برابر `teamik-kasra-mcp:v1`. متن رمزنشده یک JSON است با این فیلدها: `account`، `username`، `password`، `can_decide`، `iat`، `scope` (`manager` یا `personal`)، `expected_code` و `can_submit`. نمونهٔ کامل در `KasraMcpClient::requestForAccount()` است.
4. اگر فقط یک عامل محلی (مثل Claude Desktop) می‌خواهید، `KASRA_MCP_MODE=stdio` بگذارید و رمز را از env بدهید. جزئیات در `kasra-mcp-sidecar/README.md` است.

## اصلاح `SyncKasraDailyRange` (در این بسته اعمال شده است)

نسخهٔ قبلی روی سرور `$tries = 60` داشت. هر بار انتظار برای قفل حساب کسرا (`release`) یک تلاش حساب می‌شد، پس جاب در چند دقیقه با `MaxAttemptsExceeded` شکست می‌خورد. در نسخهٔ این بسته:
- محدودیت تلاش بر اساس زمان است: `retryUntil()` برابر ۳۰ دقیقه، `maxExceptions = 3` و `backoff = [15, 60, 120]`.
- قطع ارتباط با کسرا (`KasraException`) جاب را fail نمی‌کند. جاب به اندازهٔ `retryAfter` همان خطا صبر می‌کند (بین ۱۵ تا ۹۰۰ ثانیه) و قفل آزاد می‌شود.

## هشدارها

- کسرا API رسمی ندارد. این اتصال فرم ورود و HTML/JSON پنل را می‌خواند. اگر کسرا ظاهر پنل را عوض کند، اتصال خطای `login_form_changed` یا `portal_response_invalid` می‌دهد و باید پارسر به‌روز شود.
- CAPTCHA دور زده نمی‌شود. در این حالت خطای `captcha_required` برمی‌گردد.
- تأیید یا رد فقط با تصمیم صریح انسان (`confirmed=true`) انجام می‌شود. بعد از `needs_review`، وضعیت را دستی در پنل کسرا چک کنید.
- متن مجوز MIT پروژهٔ اصلی (`LICENSE`) باید همراه کد بماند.
