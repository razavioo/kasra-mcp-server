<?php

namespace App\Console\Commands;

use App\Jobs\SyncKasraAttendance as SyncJob;
use App\Jobs\SyncKasraPersonalAttendance;
use App\Models\KasraConnection;
use App\Models\KasraPersonalConnection;
use App\Models\User;
use App\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

class SyncKasraAttendance extends Command
{
    protected $signature = 'attendance:sync-kasra {--pending-only}';

    protected $description = 'دریافت خودکار کارکرد و کارتابل کسرا برای سازمان‌های متصل';

    public function handle(): int
    {
        foreach (Tenant::query()->where('is_active', true)->cursor() as $tenant) {
            try {
                if (! $tenant->accessActive()) {
                    continue;
                }
                tenancy()->initialize($tenant);
                if (Schema::hasTable('kasra_connections')) {
                    foreach (KasraConnection::where('enabled', true)
                        ->where(fn ($query) => $query->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now()))->pluck('id') as $id) {
                        if ($this->option('pending-only')) \App\Jobs\SyncKasraPending::dispatch((string) $tenant->id, (int) $id);
                        else SyncJob::dispatch((string) $tenant->id, (int) $id);
                    }
                }
                if (! $this->option('pending-only') && Schema::hasTable('kasra_personal_connections')) {
                    foreach (KasraPersonalConnection::where('enabled', true)
                        ->whereIn('user_id', User::where('status', 'active')->select('id'))
                        ->where(fn ($q) => $q->whereNull('last_synced_at')->orWhere('last_synced_at', '<=', now()->subMinute()))
                        ->where(fn ($q) => $q->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now()))->cursor() as $personal) {
                        SyncKasraPersonalAttendance::dispatch((string) $tenant->id, (int) $personal->user_id);
                    }
                }
            } catch (\Throwable) {
                $this->warn(__('آماده‌سازی دریافت کسرا برای یک سازمان کامل نشد؛ سازمان‌های دیگر بررسی می‌شوند.'));
            } finally {
                tenancy()->end();
            }
        }

        return self::SUCCESS;
    }
}
