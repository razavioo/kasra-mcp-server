<?php

namespace App\Jobs;

use App\Models\KasraPersonalConnection;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Hr\Kasra\KasraPersonalService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SyncKasraPersonalAttendance implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 130;
    public int $uniqueFor = 180;

    public function __construct(public string $tenantId, public int $userId)
    {
        $this->onConnection('redis-kasra');
        $this->onQueue('kasra-hours');
    }

    public function uniqueId(): string { return 'kasra-personal:'.$this->tenantId.':'.$this->userId; }

    public function handle(KasraPersonalService $service): void
    {
        $tenant = Tenant::find($this->tenantId);
        if (! $tenant?->accessActive()) return;
        $tenant->run(function () use ($service): void {
            if (! User::whereKey($this->userId)->where('status', 'active')->exists()) return;
            $connection = KasraPersonalConnection::where('user_id', $this->userId)->where('enabled', true)->first();
            if ($connection) $service->refresh($connection);
        });
    }
}
