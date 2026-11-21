<?php

namespace App\Livewire\Hr;

use App\Helpers\Jalali;
use App\Jobs\SyncKasraPersonalAttendance;
use App\Models\KasraPersonalConnection;
use App\Models\KasraPersonalSubmission;
use App\Services\Hr\Kasra\KasraException;
use App\Services\Hr\Kasra\KasraPersonalService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\Component;

class KasraPersonalAttendance extends Component
{
    public string $username = '';
    public string $password = '';
    #[Locked] public bool $requestOnly = false;
    public string $pane = 'overview';
    public string $period = '';
    public string $message = '';
    public string $creditType = '';
    public string $startDate = '';
    public string $endDate = '';
    public string $startTime = '';
    public string $endTime = '';
    public string $description = '';

    #[Locked] public string $candidateToken = '';
    #[Locked] public array $candidateProfile = [];
    #[Locked] public array $review = [];
    #[Locked] public string $requestKey = '';
    #[Locked] public string $reviewGeneration = '';
    #[Locked] public array $historicalReport = [];

    public function mount(bool $requestOnly = false): void
    {
        $this->requestOnly = $requestOnly;
        if ($requestOnly) $this->pane = 'new';
        abort_unless(auth()->check(), 403);
        $this->startDate = $this->endDate = $this->jalaliDate(now());
        $this->poll();
    }

    public function hydrate(): void { abort_unless(auth()->check(), 403); }

    private function account(): ?KasraPersonalConnection
    {
        return KasraPersonalConnection::where('user_id', auth()->id())->first();
    }

    private function candidateKey(): string
    {
        return 'kasra-personal-candidate:'.tenant('id').':'.auth()->id().':'.$this->candidateToken;
    }

    public function checkConnection(KasraPersonalService $service): void
    {
        try {
            $this->validate(['username' => 'required|string|max:150', 'password' => 'required|string|max:500']);
            $credentials = ['username' => trim($this->username), 'password' => $this->password];
            $profile = $service->verify(auth()->user(), $credentials);
            if ($this->candidateToken) Cache::forget($this->candidateKey());
            $this->candidateToken = Str::random(48);
            Cache::put($this->candidateKey(), Crypt::encryptString(json_encode(['credentials' => $credentials, 'profile' => $profile], JSON_THROW_ON_ERROR)), 300);
            $this->candidateProfile = $profile;
        } catch (KasraException $exception) {
            $this->addError('connection', $exception->getMessage());
        } finally { $this->password = ''; }
    }

    public function confirmConnection(KasraPersonalService $service): void
    {
        $candidate = $this->candidateToken ? Cache::get($this->candidateKey()) : null;
        if (! $candidate) { $this->addError('connection', __('زمان تأیید ورود تمام شده است؛ دوباره اطلاعات ورود را وارد کنید.')); return; }
        try {
            $data = json_decode(Crypt::decryptString($candidate), true, flags: JSON_THROW_ON_ERROR);
            $service->connect(auth()->user(), $data['credentials'], $data['profile']);
            Cache::forget($this->candidateKey());
            $this->reset(['candidateToken', 'candidateProfile', 'username', 'historicalReport', 'review', 'requestKey']);
            SyncKasraPersonalAttendance::dispatch((string) tenant('id'), (int) auth()->id());
            $this->message = __('حساب شخصی متصل شد؛ اطلاعات شما در حال دریافت است.');
        } catch (KasraException $exception) {
            $this->addError('connection', $exception->getMessage());
        } catch (\Illuminate\Database\QueryException) {
            $this->addError('connection', __('این حساب هم‌زمان به کاربر دیگری متصل شده است؛ اتصال ثبت نشد.'));
        }
    }

    public function cancelConnection(): void
    {
        if ($this->candidateToken) Cache::forget($this->candidateKey());
        $this->reset(['candidateToken', 'candidateProfile', 'password']);
    }

    public function disconnect(KasraPersonalService $service): void
    {
        try {
            $connection = $this->account();
            if ($connection) $service->disconnect($connection);
            $this->cancelConnection();
            $this->reset(['historicalReport', 'review', 'requestKey', 'description']);
            $this->message = __('اتصال شخصی قطع و اطلاعات ورود ذخیره‌شده حذف شد.');
        } catch (KasraException $exception) { $this->addError('connection', $exception->getMessage()); }
    }

    public function selectPane(string $pane): void
    {
        abort_unless(in_array($pane, ['overview', 'daily', 'requests', 'new', 'university', 'connection'], true), 422);
        $this->pane = $pane;
    }

    public function poll(): void
    {
        $connection = $this->account();
        if ($connection?->enabled && (! $connection->last_synced_at || $connection->last_synced_at->lt(now()->subMinute()))
            && ! $connection->next_attempt_at?->isFuture()
            && Cache::add($connection->cacheKey().':refresh', true, 60)) {
            SyncKasraPersonalAttendance::dispatch((string) tenant('id'), (int) auth()->id());
        }
    }

    public function refreshNow(): void
    {
        $this->reset(['historicalReport', 'period']);
        $this->poll();
        $this->message = __('دریافت تازه‌ترین اطلاعات ثبت شد؛ نتیجه پس از پاسخ کسرا نمایش داده می‌شود.');
    }

    public function loadPeriod(KasraPersonalService $service): void
    {
        $connection = $this->account();
        abort_unless($connection?->enabled, 403);
        if ($this->period === '') { $this->historicalReport = []; return; }
        $this->validate(['period' => 'required|regex:/^[0-9]{1,30}$/']);
        try { $this->historicalReport = $service->report($connection, $this->period); }
        catch (KasraException $exception) { $this->addError('report', $exception->getMessage()); }
    }

    public function reviewRequest(): void
    {
        $this->resetErrorBag('request');
        $connection = $this->account();
        abort_unless($connection?->enabled, 403);
        if ($this->requestKey && KasraPersonalSubmission::where('request_key', $this->requestKey)->exists()) {
            $this->addError('request', __('این درخواست قبلاً ارسال شده است؛ ابتدا نتیجه و فهرست درخواست‌ها را بررسی کنید.')); return;
        }
        $this->validate([
            'creditType' => 'required|regex:/^[0-9]+$/', 'startDate' => 'required|string|max:10', 'endDate' => 'required|string|max:10',
            'startTime' => 'nullable|date_format:H:i', 'endTime' => 'nullable|date_format:H:i', 'description' => 'nullable|string|max:1000',
        ]);
        $type = collect($connection->snapshot['catalog'] ?? [])->firstWhere('id', (int) $this->creditType);
        if (! $type) { $this->addError('request', __('نوع درخواست را از فهرست مجاز کسرا انتخاب کنید.')); return; }
        $start = Jalali::toGregorian($this->startDate); $end = Jalali::toGregorian($this->endDate);
        if (! $start || ! $end || $end->lt($start)) { $this->addError('request', __('بازهٔ تاریخ شمسی معتبر وارد کنید.')); return; }
        if (! $type['daily'] && (! $this->startTime || ! $this->endTime || ($start->equalTo($end) && $this->startTime >= $this->endTime))) {
            $this->addError('request', __('برای درخواست ساعتی، ساعت شروع و پایان معتبر وارد کنید.')); return;
        }
        $this->review = ['credit_type_id' => (int) $type['id'], 'start_date' => $this->jalaliDate($start),
            'end_date' => $this->jalaliDate($end),
            'start_time' => $type['daily'] ? '' : $this->startTime, 'end_time' => $type['daily'] ? '' : $this->endTime,
            'is_daily' => (bool) $type['daily'], 'description' => $this->description];
        $this->requestKey = Str::uuid()->toString();
        $this->reviewGeneration = $connection->generation;
        $this->pane = 'new';
    }

    private function jalaliDate($date): string
    {
        $formatter = new \IntlDateFormatter('en_US@calendar=persian', \IntlDateFormatter::NONE, \IntlDateFormatter::NONE, 'Asia/Tehran', \IntlDateFormatter::TRADITIONAL, 'yyyy/MM/dd');
        return (string) $formatter->format($date->getTimestamp());
    }

    public function cancelReview(): void { $this->reset(['review', 'requestKey', 'reviewGeneration']); }

    public function confirmRequest(KasraPersonalService $service): void
    {
        $connection = $this->account();
        abort_unless($connection?->enabled && $this->review && Str::isUuid($this->requestKey) && $connection->generation === $this->reviewGeneration, 409);
        try {
            $attempt = $service->submit($connection, $this->review, $this->requestKey);
            $this->message = match ($attempt->status) {
                'submitted' => __('کسرا دریافت درخواست را تأیید کرد؛ نتیجهٔ تأیید مدیریتی در فهرست درخواست‌های شما پیگیری می‌شود.'),
                'queued' => __('کسرا درخواست را برای پردازش دریافت کرد؛ نتیجه را در فهرست درخواست‌ها پیگیری کنید.'),
                'rejected' => __('کسرا درخواست را نپذیرفت: ').($attempt->result['message'] ?? ''),
                default => __('نتیجهٔ ارسال قطعی نیست؛ پیش از ثبت دوباره، درخواست‌های خود را در کسرا بررسی کنید. ارسال خودکار تکرار نمی‌شود.'),
            };
            $this->review = [];
            $this->pane = $this->requestOnly ? 'new' : 'requests';
            SyncKasraPersonalAttendance::dispatch((string) tenant('id'), (int) auth()->id());
        } catch (KasraException $exception) { $this->addError('request', $exception->getMessage()); }
    }

    public function startAnotherRequest(): void
    {
        $this->reset(['review', 'requestKey', 'reviewGeneration', 'creditType', 'startTime', 'endTime', 'description']);
        $this->pane = 'new';
    }

    public function render()
    {
        $connection = $this->account();
        $snapshot = $connection?->enabled ? ($connection->snapshot ?? []) : [];
        $report = $this->historicalReport ?: $snapshot;
        return view('livewire.hr.kasra-personal-attendance', [
            'connection' => $connection, 'snapshot' => $snapshot, 'report' => $report,
            'attempts' => $connection?->enabled ? KasraPersonalSubmission::where('connection_id', $connection->id)->latest()->limit(10)->get() : collect(),
            'selectedType' => collect($snapshot['catalog'] ?? [])->firstWhere('id', (int) $this->creditType),
        ]);
    }
}
