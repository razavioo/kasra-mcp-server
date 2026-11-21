<?php

namespace App\Services\Hr\Kasra;

use App\Models\KasraConnection;
use App\Models\KasraPersonalConnection;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/** Streamable HTTP MCP client for the private, stateless Kasra sidecar. */
class KasraMcpClient extends KasraClient
{
    private int $requestId = 0;

    private ?array $snapshot = null;

    public function __construct(private KasraConnection|KasraPersonalConnection $account) {}

    public function authenticate(): array
    {
        return $this->call($this->account instanceof KasraPersonalConnection ? 'kasra_personal_get_profile' : 'kasra_manager_get_profile');
    }

    public function currentMonthly(): array
    {
        $people = $this->pages('kasra_manager_get_monthly_report', []);
        return ['period' => $people['period'], 'title' => $people['title'], 'people' => $people['items']];
    }

    public function personalSnapshot(): array
    {
        return $this->call('kasra_personal_get_workspace');
    }

    public function personalReport(string $period): array
    {
        return $this->call('kasra_personal_get_period_report', ['period' => $period]);
    }

    public function submitPersonal(array $request): array
    {
        return $this->call('kasra_personal_submit_request', ['params' => $request]);
    }

    public function monthly(?string $period = null): array
    {
        if ($period === null) {
            // A complete snapshot prevents a half-updated cartable being stored.
            $this->snapshot = $this->call('kasra_manager_get_sync_snapshot');
            if (! isset($this->snapshot['monthly'], $this->snapshot['cartables'])) {
                throw new KasraException(__('پاسخ همگام‌سازی MCP کامل نیست.'));
            }

            return $this->snapshot['monthly'];
        }
        $people = $this->pages('kasra_manager_get_monthly_report', ['period' => $period]);

        return ['period' => $people['period'], 'title' => $people['title'], 'people' => $people['items']];
    }

    public function cartable(string $status = 'pending'): array
    {
        if ($this->snapshot !== null && isset($this->snapshot['cartables'][$status])) {
            return $this->snapshot['cartables'][$status];
        }

        return $this->pages('kasra_manager_get_cartable', ['status' => $status])['items'];
    }

    public function daily(string $code, string $jalaliDate): array
    {
        return $this->call('kasra_manager_get_daily_report', ['personnel_code' => $code, 'jalali_date' => $jalaliDate])['rows'];
    }

    public function decide(array $item, string $decision, string $note): void
    {
        $this->snapshot = null;
        $result = $this->call('kasra_manager_decide_request', ['params' => [
            'document_id' => $item['id'], 'expected_fingerprint' => $item['fingerprint'],
            'decision' => $decision, 'note' => $note, 'confirmed' => true,
        ]], canDecide: true);
        if (($result['status'] ?? null) === 'not_sent') {
            throw new KasraDecisionNotSent(__('آماده‌سازی فرم تأیید کسرا کامل نشد؛ هیچ تصمیمی ارسال نشده است. پس از بررسی اتصال دوباره اقدام کنید.'));
        }
        if (! in_array($result['status'] ?? null, ['processed', 'awaiting_confirmation'], true)) {
            throw new KasraException(__('نتیجهٔ ارسال به کسرا قطعی نیست؛ وضعیت سند را بررسی کنید. ارسال تکرار نشد.'));
        }
    }

    private function pages(string $tool, array $arguments): array
    {
        $items = [];
        $offset = 0;
        do {
            $page = $this->call($tool, $arguments + ['page' => ['offset' => $offset, 'limit' => 100, 'search' => '']]);
            if (! isset($page['items'], $page['has_more']) || ! is_array($page['items'])) {
                throw new KasraException(__('صفحه‌بندی پاسخ MCP معتبر نیست.'));
            }
            $items = array_merge($items, $page['items']);
            $next = $page['next_offset'] ?? null;
            if ($page['has_more'] && (! is_int($next) || $next <= $offset || $next > 10000)) {
                throw new KasraException(__('دریافت همهٔ صفحات MCP تکمیل نشد.'));
            }
            $offset = $next;
        } while ($page['has_more']);
        $page['items'] = $items;

        return $page;
    }

    private function call(string $tool, array $arguments = [], bool $canDecide = false): array
    {
        $request = $this->requestForAccount($canDecide, $tool === 'kasra_personal_submit_request');
        // Each call has its own stateless MCP initialization. Never retry writes.
        $initialize = $this->rpc($request, 'initialize', [
            'protocolVersion' => '2025-06-18', 'capabilities' => (object) [],
            'clientInfo' => ['name' => 'teamik-attendance', 'version' => '1.1.0'],
        ]);
        $protocol = $initialize['protocolVersion'] ?? null;
        if (! in_array($protocol, ['2025-03-26', '2025-06-18', '2025-11-25'], true)) {
            throw new KasraException(__('نسخهٔ ارتباط MCP پشتیبانی نمی‌شود.'));
        }
        $request = $request->withHeaders(['MCP-Protocol-Version' => $protocol]);
        $this->rpc($request, 'notifications/initialized', (object) [], notification: true);
        $result = $this->rpc($request, 'tools/call', ['name' => $tool, 'arguments' => $arguments ?: (object) []]);
        if ($result['isError'] ?? false) {
            $this->throwToolError($result);
        }
        $data = $result['structuredContent'] ?? null;
        if (isset($data['result']) && is_array($data['result'])) {
            $data = $data['result'];
        }
        if (! is_array($data)) {
            foreach ($result['content'] ?? [] as $block) {
                if (($block['type'] ?? '') === 'text') {
                    $data = json_decode($block['text'], true);
                    break;
                }
            }
        }
        if (! is_array($data)) {
            throw new KasraException(__('دادهٔ ساختاریافته از MCP دریافت نشد.'));
        }

        return $data;
    }

    private function throwToolError(array $result): never
    {
        // Only recognized codes become local UI text; raw server/provider text
        // may contain account data and must never reach the user or app logs.
        $messages = [
            'credentials_missing' => 'نام کاربری و رمز کسرا را در فرم اتصال وارد کنید.',
            'login_form_changed' => 'قالب فرم ورود کسرا تغییر کرده است؛ اتصال نیاز به به‌روزرسانی دارد.',
            'captcha_required' => 'کسرا برای این حساب کد تصویری می‌خواهد؛ ابتدا ورود و وضعیت حساب را در پنل کسرا بررسی کنید.',
            'signature_rejected' => 'کسرا امضای فرم ورود را نپذیرفت؛ اتصال نیاز به بررسی دارد.',
            'login_not_confirmed' => 'ورود به کسرا تأیید نشد؛ نام کاربری و رمز و امکان ورود در پنل کسرا را بررسی کنید.',
            'session_rejected' => 'کسرا ورود یا دسترسی حساب را نپذیرفت؛ وضعیت حساب را بررسی کنید.',
            'identity_mismatch' => 'هویت گزارش با حساب متصل یکسان نیست؛ حساب شخصی را دوباره بررسی کنید.',
            'portal_unreachable' => 'ارتباط سرور با کسرا کامل نشد؛ کمی بعد دوباره تلاش کنید.',
            'rate_limited' => 'کسرا تعداد درخواست‌ها را محدود کرده است؛ چند دقیقه بعد دوباره تلاش کنید.',
            'retry_later' => 'کسرا موقتاً در انتظار تلاش مجدد است؛ چند دقیقه بعد دوباره تلاش کنید.',
            'portal_http_error' => 'کسرا درخواست اتصال را نپذیرفت؛ سرویس نیاز به بررسی دارد.',
            'portal_response_invalid' => 'قالب گزارش دریافتی از کسرا شناسایی نشد؛ اتصال نیاز به تطبیق دارد.',
            'operation_timeout' => 'پاسخ کسرا بیش از حد طول کشید؛ اطلاعات قبلی حفظ شده است.',
        ];
        foreach ($result['content'] ?? [] as $block) {
            if (($block['type'] ?? '') !== 'text') {
                continue;
            }
            $error = json_decode($block['text'] ?? '', true);
            $code = is_array($error) ? ($error['code'] ?? null) : null;
            if (is_string($code) && isset($messages[$code])) {
                $retry = is_int($error['retry_after'] ?? null) ? min(3600, max(60, $error['retry_after'])) : 120;
                throw new KasraException(__($messages[$code]), $retry);
            }
        }
        throw new KasraException(__('عملیات MCP کسرا کامل نشد؛ وضعیت اتصال و سند را بررسی کنید.'), 120);
    }

    private function requestForAccount(bool $canDecide, bool $canSubmit = false): PendingRequest
    {
        $token = (string) config('kasra.mcp_token');
        $key = base64_decode((string) config('kasra.envelope_key'), true);
        $url = parse_url((string) config('kasra.mcp_url'));
        if (strlen($token) < 32 || ! is_string($key) || strlen($key) !== 32
            || ! in_array($url['scheme'] ?? '', ['http', 'https'], true)
            || ! in_array($url['host'] ?? '', ['kasra-mcp', 'localhost', '127.0.0.1'], true)
            || ($url['path'] ?? '') !== '/mcp' || isset($url['user']) || isset($url['pass']) || isset($url['query']) || isset($url['fragment'])) {
            throw new KasraException(__('سرویس MCP کسرا هنوز پیکربندی نشده است؛ تنظیمات اتصال سرور را تکمیل کنید.'), 300);
        }
        $credentials = $this->account->credentials ?? [];
        $personal = $this->account instanceof KasraPersonalConnection;
        $raw = json_encode([
            'account' => (string) tenant('id').($personal ? ':personal:' : ':').$this->account->id.':'.$this->account->generation,
            'username' => $credentials['username'] ?? '', 'password' => $credentials['password'] ?? '',
            'can_decide' => $personal ? false : $canDecide, 'iat' => time(),
            'scope' => $personal ? 'personal' : 'manager',
            'expected_code' => $personal ? (string) $this->account->personnel_code : '',
            'can_submit' => $personal && $canSubmit,
        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $nonce = random_bytes(12);
        $encrypted = openssl_encrypt($raw, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag, 'teamik-kasra-mcp:v1', 16);
        if ($encrypted === false) {
            throw new KasraException(__('امکان آماده‌سازی اتصال MCP وجود ندارد.'));
        }

        return Http::withToken($token)->withHeaders([
            'Accept' => 'application/json, text/event-stream',
            'X-Kasra-Account' => base64_encode($nonce.$tag.$encrypted),
        ])->withOptions(['allow_redirects' => false])->connectTimeout(5)->timeout(115);
    }

    private function rpc(PendingRequest $request, string $method, array|object $params, bool $notification = false): array
    {
        $body = ['jsonrpc' => '2.0', 'method' => $method, 'params' => $params];
        if (! $notification) {
            $body['id'] = ++$this->requestId;
        }
        try {
            $response = $request->post((string) config('kasra.mcp_url'), $body);
        } catch (\Throwable) {
            throw new KasraException(__('ارتباط با سرویس MCP کامل نشد؛ اطلاعات قبلی حفظ شده است.'), 120);
        }
        if ($notification && $response->status() === 202) {
            return [];
        }
        $json = $response->json();
        if (! $response->successful() || ! is_array($json) || isset($json['error'])
            || ($json['jsonrpc'] ?? '') !== '2.0' || ($json['id'] ?? null) !== ($body['id'] ?? null)
            || ! is_array($json['result'] ?? null)) {
            throw new KasraException(__('پاسخ معتبر از سرویس MCP دریافت نشد؛ تنظیمات اتصال را بررسی کنید.'), 120);
        }

        return $json['result'];
    }
}
