<?php

namespace App\Jobs;

use App\Models\KasraConnection;
use App\Models\Tenant;
use App\Services\Hr\Kasra\KasraSyncService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SyncKasraAttendance implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 150;

    public int $uniqueFor = 180;

    public ?int $connectionId = null;

    public function __construct(public string $tenantId, ?int $connectionId = null)
    {
        $this->connectionId = $connectionId;
        $this->onConnection('redis-kasra')->onQueue('kasra-fast');
    }

    public function uniqueId(): string
    {
        return 'kasra-attendance:'.$this->tenantId.':'.($this->connectionId ?? 'all');
    }

    public function handle(KasraSyncService $sync): void
    {
        $tenant = Tenant::query()->find($this->tenantId);
        if (! $tenant || ! $tenant->accessActive()) {
            return;
        }
        $previous = tenant();
        tenancy()->initialize($tenant);
        try {
            foreach (KasraConnection::where('enabled', true)->when($this->connectionId, fn ($q) => $q->whereKey($this->connectionId))->cursor() as $connection) {
                if ($sync->sync($connection, live: true)) {
                    $today = now('Asia/Tehran')->toDateString();
                    SyncKasraDailyRange::dispatch($this->tenantId, $connection->id, $connection->generation, ['start'=>$today,'end'=>$today,'person'=>'']);
                }
            }
        } finally {
            tenancy()->end();
            if ($previous) {
                tenancy()->initialize($previous);
            }
        }
    }
}
