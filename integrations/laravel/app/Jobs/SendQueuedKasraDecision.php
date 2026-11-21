<?php
namespace App\Jobs;
use App\Models\Tenant;
use App\Services\Hr\Kasra\KasraSyncService;
use Illuminate\Contracts\Queue\{ShouldQueue,ShouldBeUnique};
use Illuminate\Foundation\Queue\Queueable;
final class SendQueuedKasraDecision implements ShouldQueue,ShouldBeUnique {
 use Queueable;public int $tries=90;public int $timeout=150;public int $uniqueFor=300;
 public function __construct(public string $tenantId,public int $attemptId,public int $requestedAt){$this->onConnection('redis-kasra')->onQueue('kasra-fast');}
 public function uniqueId():string{return 'kasra-human-decision:'.$this->tenantId.':'.$this->attemptId;}
 public function handle(KasraSyncService $sync):void {$tenant=Tenant::find($this->tenantId);if(!$tenant?->accessActive())return;$tenant->run(function()use($sync){if($sync->runQueuedDecision($this->attemptId,$this->requestedAt))$this->release(3);});}
}
