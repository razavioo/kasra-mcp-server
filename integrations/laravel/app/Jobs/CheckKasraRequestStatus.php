<?php
namespace App\Jobs;
use App\Models\Tenant;
use App\Services\Hr\Kasra\KasraStatusCheck;
use Illuminate\Contracts\Queue\{ShouldQueue,ShouldBeUnique};
use Illuminate\Foundation\Queue\Queueable;
final class CheckKasraRequestStatus implements ShouldQueue,ShouldBeUnique {
 use Queueable;public int $tries=90;public int $timeout=150;public int $uniqueFor=240;
 public function __construct(public string $tenantId,public int $recordId,public int $actorId,public string $token,public int $requestedAt){$this->onConnection('redis-kasra')->onQueue('kasra-fast');}
 public function uniqueId():string{return 'kasra-status:'.$this->tenantId.':'.$this->recordId.':'.$this->actorId.':'.$this->token;}
 public function handle(KasraStatusCheck $checks):void {$tenant=Tenant::find($this->tenantId);if(!$tenant?->accessActive())return;$tenant->run(function()use($checks){if($checks->run($this->recordId,$this->actorId,$this->token,$this->requestedAt))$this->release(3);});}
}
