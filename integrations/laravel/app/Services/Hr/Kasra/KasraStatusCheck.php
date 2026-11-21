<?php
namespace App\Services\Hr\Kasra;
use App\Models\{KasraRecord,KasraConnection,User};
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
final class KasraStatusCheck {
 private function connection(KasraRecord $r,int $actor):KasraConnection {
  $u=User::findOrFail($actor);abort_unless($u->status==='active',403);app(KasraAccess::class)->authorizeDecide($u);
  $c=KasraConnection::findOrFail($r->connection_id);abort_unless($c->enabled&&(int)$c->configured_by===$actor&&$r->generation===$c->generation&&$r->kind==='request',403);return $c;
 }
 public function key(KasraRecord $r,int $actor):string {return 'kasra-status:'.tenant('id').':'.$r->connection_id.':'.$r->generation.':'.$r->id.':'.$actor;}
 public function current(KasraRecord $r,int $actor):?array {
  $data=Cache::get($this->key($r,$actor));
  if($data&&($data['waiting']??false)&&now()->getTimestamp()-(int)($data['requested_at']??0)>240){$data['state']='error';$data['waiting']=false;$data['error']=true;$data['text']=__('بررسی در زمان مجاز کامل نشد؛ نتیجه نامشخص است. دوباره بررسی کنید.');Cache::put($this->key($r,$actor),$data,600);$c=KasraConnection::find($r->connection_id);if($c)$this->clear($c,$data['token']);}
  return $data;
 }
 private function save(KasraRecord $r,int $actor,string $token,string $state,string $text,bool $error=false):array {
  $old=Cache::get($this->key($r,$actor));$data=['requested_at'=>($old&&($old['token']??null)===$token)?($old['requested_at']??now()->getTimestamp()):now()->getTimestamp(),'token'=>$token,'state'=>$state,'text'=>$text,'error'=>$error,'waiting'=>in_array($state,['queued','checking'],true),'at'=>now('Asia/Tehran')->format('H:i:s')];Cache::put($this->key($r,$actor),$data,600);return $data;
 }
 private function clear(KasraConnection $c,string $token):void {if(Cache::get($c->cacheKey().':decision-check-waiting')===$token)Cache::forget($c->cacheKey().':decision-check-waiting');}
 private function result(string $status):string {return match($status){'approved'=>__('همین حالا بررسی شد: درخواست در کسرا تأیید شده است.'),'rejected'=>__('همین حالا بررسی شد: درخواست در کسرا رد شده است.'),'pending'=>__('همین حالا بررسی شد: درخواست هنوز در کارتابل انتظار کسراست؛ نتیجهٔ اقدام قبلی تأیید نشده است.'),default=>__('همین حالا بررسی شد: نتیجهٔ قطعی در کسرا پیدا نشد؛ تأیید یا رد فرض نمی‌شود.')};}
 public function request(KasraRecord $r,int $actor):array {
  $c=$this->connection($r,$actor);$lock=Cache::lock($this->key($r,$actor).':request',180);
  if(!$lock->get())return $this->current($r,$actor)??['text'=>__('بررسی در حال انجام است.'),'at'=>now('Asia/Tehran')->format('H:i:s'),'error'=>false,'waiting'=>true];
  try {
   $current=$this->current($r,$actor);if($current&&$current['waiting'])return $current;
   $token=(string)Str::uuid();Cache::put($c->cacheKey().':decision-check-waiting',$token,240);
   $this->save($r,$actor,$token,'checking',__('در حال بررسی وضعیت کسرا…'));
   try {$status=app(KasraSyncService::class)->refreshDecisionStatus($r,$actor);$this->clear($c,$token);return $this->save($r,$actor,$token,'done',$this->result($status));}
   catch(KasraAccountBusy){$data=$this->save($r,$actor,$token,'queued',__('بررسی وضعیت کسرا در نوبت است؛ نتیجه خودکار همین‌جا نمایش داده می‌شود.'));\App\Jobs\CheckKasraRequestStatus::dispatch((string)tenant('id'),$r->id,$actor,$token,now()->getTimestamp());return $data;}
   catch(KasraException $e){$this->clear($c,$token);return $this->save($r,$actor,$token,'error',$e->getMessage(),true);}
   catch(\Throwable $e){$this->clear($c,$token);Cache::forget($this->key($r,$actor));throw $e;}
  } finally {$lock->release();}
 }
 /** Returns true only when a busy account needs a short, bounded retry. Source reads only. */
 public function run(int $recordId,int $actor,string $token,int $requestedAt):bool {
  $r=KasraRecord::find($recordId);if(!$r)return false;$current=$this->current($r,$actor);if(!$current||$current['token']!==$token||!$current['waiting'])return false;
  $c=KasraConnection::find($r->connection_id);if(!$c)return false;
  try {
   $this->connection($r,$actor);
   if(now()->getTimestamp()-$requestedAt>180){$this->save($r,$actor,$token,'error',__('بررسی در زمان مجاز کامل نشد؛ نتیجه همچنان نامشخص است. دوباره بررسی کنید.'),true);$this->clear($c,$token);return false;}
   Cache::put($c->cacheKey().':decision-check-waiting',$token,240);$this->save($r,$actor,$token,'checking',__('در حال بررسی وضعیت کسرا…'));
   $status=app(KasraSyncService::class)->refreshDecisionStatus($r,$actor);$this->save($r,$actor,$token,'done',$this->result($status));$this->clear($c,$token);return false;
  } catch(KasraAccountBusy){$this->save($r,$actor,$token,'queued',__('بررسی وضعیت کسرا در نوبت است؛ نتیجه خودکار همین‌جا نمایش داده می‌شود.'));return true;}
  catch(\Throwable $e){$this->save($r,$actor,$token,'error',$e instanceof KasraException?$e->getMessage():__('بررسی وضعیت کامل نشد؛ نتیجهٔ قبلی نامشخص باقی مانده است.'),true);$this->clear($c,$token);return false;}
 }
}
