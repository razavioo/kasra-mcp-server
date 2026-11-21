<?php

namespace App\Services\Hr\Kasra;

use App\Models\KasraConnection;
use GuzzleHttp\Cookie\CookieJar;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;

/**
 * Portal adapter; endpoints are from the Kasra UI and razavioo/kasra-mcp-server.
 * No JavaScript execution, shared admin browser cookies, or disabled TLS checks.
 */
class KasraClient
{
    public const ORIGIN = 'https://app.kasralite.com';

    private CookieJar $cookies;

    private ?array $profile = null;

    private KasraParser $parser;

    public function __construct(private KasraConnection $connection)
    {
        $this->parser = new KasraParser;
        $this->cookies = new CookieJar;
        if ($connection->exists && ($cached = Cache::get($connection->cacheKey().':session'))) {
            try {
                $data = json_decode(Crypt::decryptString($cached), true, 512, JSON_THROW_ON_ERROR);
                $this->cookies = new CookieJar(false, $data['cookies']);
                $this->profile = $data['profile'];
            } catch (\Throwable) {
                Cache::forget($connection->cacheKey().':session');
            }
        }
    }

    public function authenticate(): array
    {
        if ($this->profile !== null) {
            return $this->profile;
        }
        $credentials = $this->connection->credentials ?? [];
        if (empty($credentials['username']) || empty($credentials['password'])) {
            throw new KasraException(__('نام کاربری و رمز کسرا را در بخش اتصال وارد کنید.'), 300);
        }
        $loginPath = '/Lego.Web/Kevlar/Account/Login';
        $html = $this->request('GET', $loginPath, ['ReturnUrl' => '/Lego.Web/'], false)->body();
        $xpath = $this->parser->document($html);
        $forms = $xpath->query('//form[contains(concat(" ",normalize-space(@class)," ")," login-form ")]');
        if ($forms->length !== 1) {
            throw new KasraException(__('فرم ورود کسرا شناسایی نشد؛ اتصال نیاز به بررسی دارد.'), 300);
        }
        $form = [];
        $signed = [];
        foreach ($xpath->query('.//input[@name]', $forms->item(0)) as $input) {
            $name = $input->getAttribute('name');
            $form[$name] = $input->getAttribute('value');
            if ($input->getAttribute('type') === 'hidden' && ! in_array($name, ['nonce', 'chkRequestData'], true)) {
                $signed[$name] = $form[$name];
            }
            if ($input->getAttribute('id') === 'captchaValue' && $input->getAttribute('type') !== 'hidden' && $input->hasAttribute('required')) {
                throw new KasraException(__('کسرا برای ورود کد تصویری می‌خواهد؛ ورود خودکار این حساب در دسترس نیست.'), 900);
            }
        }
        $encrypted = openssl_encrypt($credentials['password'], 'AES-128-CBC', '8080808080808080', 0, '8080808080808080');
        if ($encrypted === false) {
            throw new KasraException(__('امکان آماده‌سازی ورود امن به کسرا وجود ندارد.'));
        }
        $form['UserName'] = $signed['UserName'] = $credentials['username'];
        $form['Password'] = $signed['Password'] = 'hashed_'.$encrypted;
        $signature = $this->signature(json_encode($signed, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        $form['nonce'] = $signature['nonce'];
        $form['chkRequestData'] = $signature['chkRequestData'];
        $this->request('POST', $loginPath, $form, false, ['Referer' => self::ORIGIN.$loginPath], true);
        $profileHtml = $this->request('GET', '/Lego.Web/', [], false)->body();
        $person = json_decode($this->parser->setting($profileHtml, 'UserProfileNameSpace.modelPerson'), true);
        if (! is_array($person) || empty($person[0]['CodeMask']) || empty($person[0]['Name'])) {
            throw new KasraException(__('ورود به کسرا تأیید نشد. نام کاربری، رمز و وضعیت ورود حساب را بررسی کنید.'), 300);
        }
        $this->profile = ['code' => (string) $person[0]['CodeMask'], 'name' => (string) $person[0]['Name']];
        if ($this->connection->exists) {
            Cache::put($this->connection->cacheKey().':session', Crypt::encryptString(json_encode([
                'cookies' => $this->cookies->toArray(), 'profile' => $this->profile,
            ], JSON_THROW_ON_ERROR)), now()->addMinutes(10));
        }

        return $this->profile;
    }

    public function monthly(?string $period = null): array
    {
        $this->authenticate();
        $path = '/TAPresentation/App_Pages/Reports/MainMonthlyReport';
        $html = $this->request('GET', $path)->body();
        $inputs = $this->parser->inputs($html);
        $xpath = $this->parser->document($html);
        $options = $xpath->query('//select[@id="ctl00_ContentPlaceHolder1_CmbPeriod"]/option');
        $selected = null;
        foreach ($options as $option) {
            if ($period === $option->getAttribute('value') || ($period === null && ($selected === null || $option->hasAttribute('selected')))) {
                $selected = $option;
            }
        }
        if ($selected === null || ($period !== null && $selected->getAttribute('value') !== $period)) {
            throw new KasraException(__('دورهٔ کارکرد در کسرا پیدا نشد.'));
        }
        $periodId = $selected->getAttribute('value');
        $this->requireInputs($inputs, ['ctl00_antiCsrfToken', 'ctl00_keyToken', 'ctl00_ContentPlaceHolder1_txtSessionID', 'ctl00_ContentPlaceHolder1_txtOnLineUser', 'ctl00_ContentPlaceHolder1_txtCompanyID']);
        $headers = [
            'antiCsrfTokenValue' => $inputs['ctl00_antiCsrfToken'],
            'keyTokenValue' => $inputs['ctl00_keyToken'],
            'Referer' => self::ORIGIN.$path,
        ];
        $people = [];
        for ($page = 1; $page <= 200; $page++) {
            $body = [
                'ReportID' => '1', 'PersonID' => $inputs['ctl00_ContentPlaceHolder1_txtOnLineUser'],
                'WPID' => $periodId, 'DepartmentID' => '0', 'ChkChildren' => '1', 'ChkShowManager' => '1',
                'GroupID' => '0', 'ShiftID' => '0', 'PageSize' => '50', 'PageNumber' => (string) $page,
                'CompanyID' => $inputs['ctl00_ContentPlaceHolder1_txtCompanyID'], 'SessionID' => $inputs['ctl00_ContentPlaceHolder1_txtSessionID'],
                'Search' => '', 'CreditNeed' => '0', 'SearchFlag' => '1', 'IsTotallyPerson' => '0',
                'FilterWorkPeriodName' => '', 'FilterShiftName' => '', 'FilterGroupName' => '', 'FilterPersonName' => '',
            ];
            $grid = $this->parser->grid($this->json('POST', $path.'.are/GenerateGridXmlData?SubmitMode=Ajax', $body, $headers));
            if ($grid['total'] !== null && count($grid['rows']) < min(50, max(0, $grid['total'] - ($page - 1) * 50))) {
                throw new KasraException(__('صفحهٔ گزارش کسرا ناقص دریافت شد؛ اطلاعات قبلی حفظ شده است.'));
            }
            $new = 0;
            foreach ($grid['rows'] as $row) {
                if (! is_array($row)) {
                    throw new KasraException(__('ردیف نامعتبر در گزارش ماهانهٔ کسرا دریافت شد.'));
                }
                $person = $this->parser->monthlyPerson($row, $grid['columns']);
                if ($person !== null) {
                    if (isset($people[$person['code']]) && $page > 1) {
                        throw new KasraException(__('صفحه‌بندی گزارش کسرا تکراری است؛ دریافت کامل تأیید نشد.'));
                    }
                    $people[$person['code']] = $person;
                    $new++;
                }
            }
            if (count($grid['rows']) < 50 || ($grid['total'] !== null && $page * 50 >= $grid['total'])) {
                if ($people !== [] && collect($people)->every(fn ($person) => collect($person['metrics'])->every(fn ($value) => in_array($value, ['', '—'], true)))) {
                    throw new KasraException(__('ستون‌های کارکرد در گزارش کسرا شناسایی نشدند؛ اتصال نیاز به تطبیق قالب گزارش دارد.'));
                }

                return ['period' => $periodId, 'title' => trim($selected->textContent), 'people' => array_values($people)];
            }
            if ($new === 0) {
                break;
            }
        }
        throw new KasraException(__('دریافت همهٔ صفحات گزارش کسرا تکمیل نشد.'));
    }

    public function cartable(string $status = 'pending'): array
    {
        $context = $this->context($status);
        $prefix = $context['prefix'];
        $items = [];
        for ($page = 1; $page <= 200; $page++) {
            $query = ['docTypeId' => 0, 'page' => $page, 'pageSize' => 100, 'take' => 100, 'skip' => ($page - 1) * 100, $prefix.'-page' => $page];
            $grid = $this->parser->grid($this->json('GET', '/Lego.Web/Frm/Cartable/'.$prefix.'_Read', $query));
            if ($grid['total'] !== null && count($grid['rows']) < min(100, max(0, $grid['total'] - ($page - 1) * 100))) {
                throw new KasraException(__('صفحهٔ کارتابل کسرا ناقص دریافت شد؛ اطلاعات قبلی حفظ شده است.'));
            }
            foreach ($grid['rows'] as $row) {
                $item = $this->parser->request($row, $status);
                if (isset($items[$item['id']])) {
                    throw new KasraException(__('صفحه‌بندی کارتابل کسرا تکراری است.'));
                }
                $items[$item['id']] = $item;
            }
            if (count($grid['rows']) < 100 || ($grid['total'] !== null && $page * 100 >= $grid['total'])) {
                return array_values($items);
            }
        }
        throw new KasraException(__('دریافت همهٔ صفحات کارتابل کسرا تکمیل نشد.'));
    }

    public function daily(string $code, string $jalaliDate): array
    {
        $this->authenticate();
        $context = $this->context('pending');
        $path = $this->parser->setting($context['html'], 'CartableInWaitNameSpace.MainDailyReportUrl');
        if ($path === '') {
            throw new KasraException(__('مسیر گزارش روزانه در نسخهٔ فعلی کسرا شناسایی نشد.'));
        }
        $html = $this->request('GET', $path, [
            'FromCartable' => 1, 'PersonCode' => base64_encode($code),
            'DocumentStartDate' => $jalaliDate, 'ParentMenuItemId' => '111122',
        ])->body();

        return $this->parser->daily($html, $code);
    }

    /** Never automatically retry a mutation, including after an ambiguous timeout. */
    public function decide(array $item, string $decision, string $note): void
    {
        if (! in_array($decision, ['approved', 'rejected'], true)) {
            throw new KasraException(__('تصمیم درخواست معتبر نیست.'));
        }
        $context = $this->context('pending');
        $html = $context['html'];
        $path = $this->parser->setting($html, 'CartableInWaitNameSpace.ModifyCasingWorkTableUrl');
        if ($path === '') {
            throw new KasraException(__('مسیر تأیید درخواست در کسرا شناسایی نشد؛ اقدامی ارسال نشد.'));
        }
        if (! preg_match('~^(?:https://app\.kasralite\.com)?/?(?:Lego\.Web/)?Frm/Cartable/[A-Za-z]*Modify[A-Za-z]*$~', $path)) {
            throw new KasraException(__('مسیر تغییر سند با قرارداد کارتابل کسرا مطابقت ندارد؛ اقدامی ارسال نشد.'));
        }
        $values = [];
        foreach (['SessionId', 'CompanyID', 'AccepterCode', 'PeriodLookupId'] as $key) {
            $values[$key] = $this->parser->setting($html, 'CartableInWaitNameSpace.'.$key);
            if ($values[$key] === '') {
                throw new KasraException(__('اطلاعات مرحلهٔ تأیید کسرا کامل نیست؛ اقدامی ارسال نشد.'));
            }
        }
        $row = $item['source'];
        $document = [];
        foreach (['RowNo' => 'RowNumber', 'DocID' => 'DocId', 'action' => 'Action', 'DocTypeID' => 'DocTypeId', 'PageNumber' => 'PageNumber', 'StepNo' => 'StepNo', 'ProcessInstanceId' => 'ProcessInstanceId'] as $field => $alias) {
            $document[$field] = $this->parser->value($row, [$alias]);
        }
        if ($document['DocID'] !== $item['id'] || $document['DocTypeID'] === '' || $document['action'] === '' || $document['action'] === '-4' || $document['PageNumber'] === '') {
            throw new KasraException(__('سند یا مرحلهٔ گردش کار برای تأیید از این حساب معتبر نیست.'));
        }
        $inputs = $this->parser->inputs($html);
        $start = $inputs['FromDateCartable'] ?? '';
        $end = $inputs['ToDateCartable'] ?? '';
        if ($start === '' || $end === '') {
            throw new KasraException(__('بازهٔ کارتابل برای ثبت تصمیم شناسایی نشد.'));
        }
        $document['ActorDesc'] = $note;
        $body = [
            'ErjaStatus' => 0, 'IsSelectAllGrdChk' => 0,
            'ActionData' => json_encode(['Tb' => [$document], 'ActionTb' => ['Action' => $decision === 'approved' ? '1' : '2']], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'CartablOwner' => $values['AccepterCode'], 'periodLookup' => $values['PeriodLookupId'],
            'Requestor' => '', 'Department' => 0, 'Group' => 0, 'DocType' => 0, 'StrFilter' => '', 'SortStr' => '',
            'PageNumber' => $document['PageNumber'], 'SessionID' => $values['SessionId'],
            'CompanyID' => $values['CompanyID'], 'Sdate' => $start, 'Edate' => $end,
        ];
        $json = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $headers = $this->signature($json);
        $response = $this->request('POST', $path, $body, true, $headers, false, $json);
        $data = $response->json();
        $result = is_array($data) ? ($data['d'] ?? $data['Data'] ?? null) : $data;
        if ($result !== '' && ! (is_string($result) && str_contains($result, 'WorkFlowQueue'))) {
            throw new KasraException(__('کسرا نتیجهٔ موفق قابل‌تأیید برنگرداند؛ وضعیت سند را دوباره بررسی کنید.'));
        }
    }

    private function context(string $status): array
    {
        $profile = $this->authenticate();
        $prefix = match ($status) {
            'pending' => 'CartableInWait', 'approved' => 'CartableConfirmed', 'rejected' => 'CartableRejected',
            default => throw new KasraException(__('نوع کارتابل معتبر نیست.')),
        };
        // 1311104 is the vendor's period-lookup preset, not a fixed work-period year.
        $html = $this->request('GET', '/Lego.Web/Frm/Cartable/'.$prefix.'Render', ['personCode' => $profile['code'], 'periodId' => 1311104])->body();
        if (! str_contains($html, $prefix)) {
            throw new KasraException(__('صفحهٔ کارتابل معتبر از کسرا دریافت نشد.'));
        }

        return compact('html', 'prefix');
    }

    private function signature(string $data): array
    {
        $result = $this->request('POST', '/Lego.Web/api/Kevlar/RequestHeaderApi/GetHeader', ['data' => $data], false)->json();
        if (! is_array($result) || empty($result['nonce']) || empty($result['checksum'])) {
            throw new KasraException(__('کسرا امضای درخواست را صادر نکرد.'));
        }

        return ['nonce' => $result['nonce'], 'chkRequestData' => $result['checksum']];
    }

    private function json(string $method, string $path, array $data, array $headers = []): array
    {
        $response = $this->request($method, $path, $data, true, $headers);
        $value = $response->json();
        if (! is_array($value)) {
            throw new KasraException(__('پاسخ کسرا قابل خواندن نیست؛ اتصال یا قالب گزارش را بررسی کنید.'));
        }

        return $value;
    }

    private function requireInputs(array $inputs, array $keys): void
    {
        foreach ($keys as $key) {
            if (empty($inputs[$key])) {
                throw new KasraException(__('اطلاعات نشست گزارش کسرا ناقص است؛ دوباره وارد شوید.'));
            }
        }
    }

    private function request(string $method, string $path, array $data = [], bool $authenticated = true, array $headers = [], bool $form = false, ?string $rawBody = null): Response
    {
        $url = $this->safeUrl($path);
        try {
            $request = Http::withHeaders(array_merge([
                'Origin' => self::ORIGIN, 'X-Requested-With' => 'XMLHttpRequest', 'Accept-Language' => 'fa,en;q=0.9',
            ], $headers))->withOptions(['cookies' => $this->cookies, 'allow_redirects' => false])->connectTimeout(8)->timeout(25);
            if ($form) {
                $request = $request->asForm();
            }
            $response = $rawBody !== null
                ? $request->withBody($rawBody, 'application/json')->send($method, $url)
                : ($method === 'GET' ? $request->get($url, $data) : $request->post($url, $data));
        } catch (\Throwable) {
            throw new KasraException(__('ارتباط با کسرا کامل نشد؛ آخرین اطلاعات قبلی حفظ شده است.'));
        }
        if ($response->status() === 429) {
            throw new KasraException(__('کسرا تعداد درخواست‌ها را محدود کرده؛ دریافت با فاصلهٔ بیشتر ادامه پیدا می‌کند.'), min(3600, max(60, (int) $response->header('Retry-After'))));
        }
        if (in_array($response->status(), [401, 403], true) || ($authenticated && ($response->redirect() || str_contains($response->body(), 'class="login-form"')))) {
            Cache::forget($this->connection->cacheKey().':session');
            throw new KasraException(__('نشست کسرا منقضی شده یا دسترسی کافی ندارد؛ ورود اتصال را بررسی کنید.'), 300);
        }
        if (! $response->successful() && ! (! $authenticated && $response->redirect())) {
            throw new KasraException(__('کسرا درخواست را نپذیرفت. کد پاسخ: :code', ['code' => $response->status()]));
        }

        return $response;
    }

    private function safeUrl(string $path): string
    {
        if (str_starts_with($path, 'Frm/')) {
            $path = '/Lego.Web/'.$path;
        }
        $url = str_starts_with($path, 'https://') ? $path : self::ORIGIN.'/'.ltrim($path, '/');
        $parts = parse_url($url);
        if (($parts['scheme'] ?? '') !== 'https' || ($parts['host'] ?? '') !== 'app.kasralite.com' || isset($parts['port']) || isset($parts['user']) || isset($parts['pass']) || ! preg_match('~^/(Lego\.Web|TAPresentation)/~', $parts['path'] ?? '') || str_contains($parts['path'] ?? '', '..')) {
            throw new KasraException(__('نشانی دریافت‌شده برای اتصال کسرا مجاز نیست.'));
        }

        return $url;
    }
}
