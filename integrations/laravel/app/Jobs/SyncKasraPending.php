<?php
namespace App\Jobs;
use App\Models\{KasraConnection,Tenant};
use App\Services\Hr\Kasra\KasraSyncService;
use Illuminate\Contracts\Queue\{ShouldQueue,ShouldBeUnique};
use Illuminate\Foundation\Queue\Queueable;
class SyncKasraPending implements ShouldQueue,ShouldBeUnique
{
    use Queueable;
    public int $timeout=120;
    public int $tries=1;
    public int $uniqueFor=150;
    public function __construct(public string $tenantId,public int $connectionId) { $this->onConnection('redis-kasra')->onQueue('kasra-fast'); }
    public function uniqueId(): string { return 'kasra-fast:'.$this->tenantId.':'.$this->connectionId; }
    public function handle(KasraSyncService $sync): void
    {
        $tenant=Tenant::find($this->tenantId);
        if (!$tenant || !$tenant->accessActive()) return;
        $previous=tenant();tenancy()->initialize($tenant);
        try { $connection=KasraConnection::find($this->connectionId);if($connection)$sync->syncPending($connection); }
        finally { tenancy()->end();if($previous)tenancy()->initialize($previous); }
    }
}
