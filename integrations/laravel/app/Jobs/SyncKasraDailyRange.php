<?php
namespace App\Jobs;

use App\Models\KasraConnection;
use App\Models\Tenant;
use App\Services\Hr\Kasra\KasraDailyReport;
use App\Services\Hr\Kasra\KasraException;
use App\Services\Hr\Kasra\KasraMcpClient;
use Carbon\Carbon;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class SyncKasraDailyRange implements ShouldQueue, ShouldBeUnique
{
    use Queueable;
    public int $timeout = 150;
    // Waiting for the shared account lock is a release, and every release is
    // an attempt; a count limit was exhausted within minutes. Bound by time.
    public int $maxExceptions = 3;
    public int $uniqueFor = 3600;
    public function retryUntil(): \DateTimeInterface { return now()->addMinutes(30); }
    public function backoff(): array { return [15, 60, 120]; }
    public ?int $requestedAt = null;
    public function __construct(public string $tenantId, public int $connectionId, public string $generation, public array $range, ?int $requestedAt = null)
    { $this->requestedAt = $requestedAt ?? now()->getTimestamp(); $this->onConnection('redis-kasra')->onQueue('kasra-hours'); }
    public function uniqueId(): string { return 'kasra-range:'.$this->tenantId.':'.$this->generation.':'.hash('sha256', json_encode($this->range)); }
    /** A newer complete cached range already satisfies an older queued read. */
    private function fulfilled(KasraConnection $connection, ?int $requestedAt): bool
    {
        if ($requestedAt === null) return false;
        $start = Carbon::parse($this->range['start'], 'Asia/Tehran')->startOfDay();
        $end = Carbon::parse($this->range['end'], 'Asia/Tehran')->startOfDay();
        if ($end->lt($start) || $start->diffInDays($end) > 31) return false;
        $dates = \App\Models\KasraRecord::where('connection_id', $connection->id)->where('generation', $connection->generation)
            ->where('kind', 'daily')->where('personnel_code', $this->range['person'])
            ->whereBetween('period', [$start->toDateString(), $end->toDateString()])
            ->get(['period', 'observed_at'])->filter(fn ($row) => $row->observed_at?->getTimestamp() > $requestedAt)
            ->pluck('period')->unique()->count();
        return $dates === (int) $start->diffInDays($end) + 1;
    }
    public function handle(KasraDailyReport $report): void
    {
        $tenant = Tenant::find($this->tenantId);
        if (! $tenant || ! $tenant->accessActive()) return;
        $previous = tenant(); tenancy()->initialize($tenant);
        try {
            $connection = KasraConnection::find($this->connectionId);
            if (! $connection || ! $connection->enabled || $connection->generation !== $this->generation || $connection->next_attempt_at?->isFuture()) return;
            $requestedAt = $this->requestedAt ?? ($this->job?->payload()['createdAt'] ?? null);
            if ($this->range['person'] === '') {
                foreach (\App\Models\KasraRecord::where('connection_id', $connection->id)->where('generation', $connection->generation)
                    ->where('kind', 'monthly')->where('snapshot_id', $connection->snapshot_id)->pluck('personnel_code') as $code) {
                    self::dispatch($this->tenantId, $this->connectionId, $this->generation, array_replace($this->range, ['person'=>$code]), $requestedAt);
                }
                return;
            }
            $person = \App\Models\KasraRecord::where('connection_id', $connection->id)->where('generation', $connection->generation)
                ->where('kind', 'monthly')->where('snapshot_id', $connection->snapshot_id)->where('personnel_code', $this->range['person'])->first();
            if (! $person) return;
            if ($this->fulfilled($connection, $requestedAt)) return;
            if (Cache::has($connection->cacheKey().':pending-waiting') || Cache::has($connection->cacheKey().':decision-check-waiting')) { $this->release(2); return; }
            $lock = Cache::lock('kasra:'.$this->tenantId.':'.$connection->id.':operation', 180);
            if (! $lock->get()) { $this->release(3); return; }
            try {
                $start = Carbon::parse($this->range['start'], 'Asia/Tehran'); $end = Carbon::parse($this->range['end'], 'Asia/Tehran');
                if ($end->lt($start) || $start->diffInDays($end) > 31) return;
                $dates = [];
                for ($day = $start->copy(); $day->lte($end); $day->addDay()) {
                    $date = KasraDailyReport::jalali($day);
                    $dates[substr($date, 0, 7)] = $date;
                }
                $client = new KasraMcpClient($connection);
                $days = [];
                try {
                    foreach ($dates as $date) foreach ($client->daily($person->personnel_code, $date) as $day) $days[$day['date']] = $day;
                } catch (KasraException $e) {
                    // A read-only provider outage waits as the provider asks instead of retrying at once.
                    $this->release(min(900, max(15, $e->retryAfter))); return;
                }
                $result = ['people'=>[['code'=>$person->personnel_code, 'name'=>$person->person_name, 'rows'=>array_values($days)]]];
                DB::transaction(function () use ($connection, $result, $report): void {
                    $fresh = KasraConnection::lockForUpdate()->find($connection->id);
                    if (! $fresh || ! $fresh->enabled || $fresh->generation !== $this->generation) return;
                    $report->store($fresh, $result['people'], $this->range['start'], $this->range['end']);
                });
            } finally { $lock->release(); }
        } finally { tenancy()->end(); if ($previous) tenancy()->initialize($previous); }
    }
}
