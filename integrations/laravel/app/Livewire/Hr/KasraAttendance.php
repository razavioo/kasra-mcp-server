<?php

namespace App\Livewire\Hr;

use App\Helpers\Jalali;
use App\Jobs\SyncKasraAttendance;
use App\Models\AttendanceMapping;
use App\Models\KasraConnection;
use App\Models\KasraDecision;
use App\Models\KasraRecord;
use App\Services\Hr\Kasra\KasraAccess;
use App\Services\Hr\Kasra\KasraClient;
use App\Services\Hr\Kasra\KasraException;
use App\Services\Hr\Kasra\KasraSyncService;
use App\Services\Hr\Kasra\KasraCartableService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class KasraAttendance extends Component
{
    use WithPagination;

    #[Locked]
    public bool $settingsOnly = false;

    public string $pane = 'requests';

    public string $username = '';

    public string $password = '';

    public string $requestStatus = 'all';

    public string $message = '';

    public string $decisionNote = '';

    #[Url(as: 'kasraRequest')]
    public ?int $requestedRecord = null;

    #[Locked]
    public ?int $decisionRecordId = null;

    #[Locked]
    public string $selectedDecision = '';

    #[Locked]
    public string $selectedFingerprint = '';

    #[Locked]
    public array $dailyRows = [];

    #[Locked]
    public string $dailyName = '';

    public function mount(bool $settingsOnly = false): void
    {
        $this->settingsOnly = $settingsOnly;
        if ($settingsOnly) $this->pane = 'connection';
        $this->access()->authorizeView(auth()->user());
        if ($this->requestedRecord) {
            $record = $this->records()->where('kind', 'request')->findOrFail($this->requestedRecord);
            abort_unless((int) KasraConnection::findOrFail($record->connection_id)->configured_by === (int) auth()->id(), 403);
            $this->requestStatus = in_array($record->source_status, ['pending', 'approved', 'rejected'], true) ? $record->source_status : 'not_in_cartable';
            $this->pane = 'requests';
        }
    }

    public function hydrate(): void
    {
        $this->access()->authorizeView(auth()->user());
    }

    private function access(): KasraAccess
    {
        return app(KasraAccess::class);
    }

    public function selectPane(string $pane): void
    {
        abort_unless(in_array($pane, ['requests', 'monthly', 'connection'], true), 422);
        $this->pane = $pane;
    }

    public function updatedRequestStatus(): void
    {
        $this->requestedRecord = null;
        $this->resetPage(pageName: 'kasraRequestsPage');
    }

    public function saveConnection(): void
    {
        $this->access()->authorizeConfigure(auth()->user());
        try {
            $this->validate(['username' => ['required', 'string', 'max:150'], 'password' => ['required', 'string', 'max:500']]);
            $previous = KasraConnection::where('configured_by', auth()->id())->first();
            $lock = Cache::lock('kasra:'.tenant('id').':'.($previous?->id ?? 'setup').':operation', 180);
            if (! $lock->get()) {
                throw new KasraException(__('عملیات اتصال در حال انجام است؛ چند لحظه بعد دوباره امتحان کنید.'));
            }
            try {
                $candidate = new KasraConnection([
                    'credentials' => ['username' => trim($this->username), 'password' => $this->password],
                    'generation' => Str::uuid()->toString(), 'configured_by' => auth()->id(),
                ]);
                $client = app()->makeWith(KasraClient::class, ['connection' => $candidate]);
                $profile = $client->authenticate();
                // authenticate verifies the signed-in profile, not just HTTP 200.
                // Report validation belongs to the atomic background sync: a
                // report format error must not discard a verified credential.
                $connection = DB::transaction(function () use ($candidate, $profile): KasraConnection {
                    $connection = KasraConnection::firstOrNew(['provider' => 'kasra', 'configured_by' => auth()->id()]);
                    if ($connection->exists) {
                        Cache::forget($connection->cacheKey().':session');
                    }
                    $connection->fill([
                        'credentials' => $candidate->credentials,
                        'generation' => $candidate->generation,
                        'configured_by' => $candidate->configured_by,
                    ]);
                    $connection->forceFill([
                        'provider' => 'kasra', 'enabled' => true, 'account_name' => $profile['name'],
                        'status' => 'configured', 'period_id' => null, 'snapshot_id' => null, 'last_synced_at' => null, 'next_attempt_at' => null, 'last_error' => null,
                    ])->save();
                    app(KasraCartableService::class)->reconcile($connection);

                    return $connection;
                });
                activity('attendance')->performedOn($connection)->causedBy(auth()->user())->event('kasra_connected')->log('kasra_connected');
                SyncKasraAttendance::dispatch((string) tenant('id'), $connection->id);
                $this->message = __('ورود به کسرا تأیید و اتصال ذخیره شد؛ گزارش‌ها در پس‌زمینه دریافت و بررسی می‌شوند.');
                $this->username = '';
                $this->pane = $this->settingsOnly ? 'connection' : 'requests';
            } finally {
                $lock->release();
            }
        } catch (KasraException $exception) {
            $this->addError('connection', $exception->getMessage());
        } finally {
            $this->password = '';
        }
    }

    public function toggleConnection(): void
    {
        $this->access()->authorizeConfigure(auth()->user());
        $connection = KasraConnection::where('configured_by', auth()->id())->firstOrFail();
        $lock = Cache::lock('kasra:'.tenant('id').':'.$connection->id.':operation', 180);
        if (! $lock->get()) {
            $this->message = __('عملیات دیگری در حال انجام است؛ کمی بعد دوباره امتحان کنید.');

            return;
        }
        try {
            $connection->refresh();
            $connection->update(['enabled' => ! $connection->enabled]);
            app(KasraCartableService::class)->reconcile($connection);
            Cache::forget($connection->cacheKey().':session');
            activity('attendance')->performedOn($connection)->causedBy(auth()->user())
                ->event($connection->enabled ? 'kasra_resumed' : 'kasra_paused')->log('kasra_connection_toggled');
        } finally {
            $lock->release();
        }
    }

    public function syncNow(): void
    {
        $this->access()->authorizeView(auth()->user());
        $connection = KasraConnection::where('configured_by', auth()->id())->where('enabled', true)->firstOrFail();
        if ($connection->next_attempt_at?->isFuture()) {
            $this->message = __('کسرا موقتاً در انتظار تلاش مجدد است؛ دریافت خودکار ادامه پیدا می‌کند.');

            return;
        }
        if (Cache::add($connection->cacheKey().':manual-sync', true, 30)) {
            SyncKasraAttendance::dispatch((string) tenant('id'), $connection->id);
        }
        $this->message = __('درخواست تازه‌سازی ثبت شد؛ نتیجه پس از دریافت از کسرا نمایش داده می‌شود.');
    }

    public function selectDecision(int $recordId, string $decision): void
    {
        $this->access()->authorizeDecide(auth()->user());
        abort_unless(in_array($decision, ['approved', 'rejected'], true), 422);
        $record = $this->records()->where('kind', 'request')->where('source_status', 'pending')->findOrFail($recordId);
        $this->decisionRecordId = $record->id;
        $this->selectedDecision = $decision;
        $this->selectedFingerprint = $record->fingerprint;
        $this->decisionNote = '';
        $this->resetErrorBag('decision');
    }

    public function cancelDecision(): void
    {
        $this->reset(['decisionRecordId', 'selectedDecision', 'selectedFingerprint', 'decisionNote']);
    }

    public function confirmDecision(KasraSyncService $sync): void
    {
        $this->access()->authorizeDecide(auth()->user());
        $this->validate(['decisionNote' => [$this->selectedDecision === 'rejected' ? 'required' : 'nullable', 'string', 'max:1000']]);
        $record = $this->records()->where('kind', 'request')->findOrFail($this->decisionRecordId);
        if ($record->fingerprint !== $this->selectedFingerprint) {
            $this->addError('decision', __('درخواست از زمان بازشدن بررسی تغییر کرده است؛ دوباره درخواست را انتخاب کنید.'));

            return;
        }
        try {
            $result = $sync->decide($record, $this->selectedDecision, $this->decisionNote, (int) auth()->id());
            activity('attendance')->performedOn($result)->causedBy(auth()->user())->event('kasra_decision_requested')->log('kasra_decision_requested');
            $this->message = match ($result->status) {
                'processed' => __('نتیجهٔ اقدام در کارتابل کسرا بازخوانی شد. نتیجهٔ نهایی سند تابع گردش کار کسراست.'),
                'needs_review' => __('نتیجهٔ ارسال قطعی نیست؛ پیش از هر اقدام دیگر، وضعیت سند در کسرا باید بررسی شود.'),
                default => __('اقدام ارسال شده و در انتظار بازخوانی نتیجه از کسراست.'),
            };
            $this->cancelDecision();
        } catch (KasraException $exception) {
            $this->addError('decision', $exception->getMessage());
        }
    }

    public function showDaily(int $recordId, KasraSyncService $sync): void
    {
        $this->access()->authorizeView(auth()->user());
        $record = $this->records()->where('kind', 'monthly')->findOrFail($recordId);
        try {
            // Use the first day of the current Jalali period as a report anchor;
            // a Gregorian day number must never be treated as a Jalali day.
            $date = str_replace('-', '/', Jalali::currentMonthKey()).'/01';
            $this->dailyRows = $sync->daily(KasraConnection::findOrFail($record->connection_id), $record->personnel_code, $date);
            $this->dailyName = $record->person_name;
        } catch (KasraException $exception) {
            $this->addError('daily', $exception->getMessage());
        }
    }

    private function records()
    {
        $connection = KasraConnection::where('configured_by', auth()->id())->first();

        return KasraRecord::query()->where('connection_id', $connection?->id ?? 0)->where('generation', $connection?->generation ?? '');
    }

    public function render()
    {
        $this->access()->authorizeView(auth()->user());
        $connection = KasraConnection::where('configured_by', auth()->id())->first();
        $status = in_array($this->requestStatus, ['all', 'pending', 'approved', 'rejected', 'not_in_cartable'], true) ? $this->requestStatus : 'all';
        $monthly = $this->records()->where('kind', 'monthly')->where('period', $connection?->period_id ?? '')
            ->where('snapshot_id', $connection?->snapshot_id ?? '')->orderBy('person_name')->get();
        $requests = $this->records()->where('kind', 'request')->when($status !== 'all', fn ($q) => $q->where('source_status', $status))
            ->when($this->requestedRecord, fn ($q) => $q->whereKey($this->requestedRecord))
            ->orderByDesc('id')->paginate(25, pageName: 'kasraRequestsPage');

        return view('livewire.hr.kasra-attendance', [
            'connection' => $connection, 'monthly' => $monthly, 'requests' => $requests,
            'pendingCount' => $this->records()->where('kind', 'request')->where('source_status', 'pending')->count(),
            'requestCount' => $this->records()->where('kind', 'request')->count(),
            'decisions' => KasraDecision::whereIn('record_id', $requests->getCollection()->pluck('id'))->latest('id')->get()
                ->filter(fn ($decision) => $requests->getCollection()->firstWhere('id', $decision->record_id)?->fingerprint === $decision->expected_fingerprint)
                ->unique('record_id')->keyBy('record_id'),
            'selectedRequest' => $this->decisionRecordId ? $this->records()->find($this->decisionRecordId) : null,
            'mappings' => AttendanceMapping::with('user:id,name')->get()->keyBy('personnel_code'),
            'canConfigure' => auth()->user()->hasAnyRole(['admin', 'founder']) || (auth()->user()->can('integrations.manage') || auth()->user()->can('attendance.manage')),
            'canDecide' => $connection && (int) $connection->configured_by === (int) auth()->id()
                && (auth()->user()->hasAnyRole(['admin', 'founder']) || auth()->user()->can('attendance.manage')),
        ]);
    }
}
