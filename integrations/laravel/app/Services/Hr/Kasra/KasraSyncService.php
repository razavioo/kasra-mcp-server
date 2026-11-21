<?php

namespace App\Services\Hr\Kasra;

use App\Models\AttendanceMapping;
use App\Models\KasraConnection;
use App\Models\KasraDecision;
use App\Models\KasraRecord;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class KasraSyncService
{
    protected function client(KasraConnection $connection): KasraClient
    {
        return app()->makeWith(KasraClient::class, ['connection' => $connection]);
    }

    public function sync(KasraConnection $connection, bool $live = false): bool
    {
        if (! $connection->enabled || $connection->next_attempt_at?->isFuture()) {
            return false;
        }
        if (Cache::has($connection->cacheKey().':pending-waiting') || Cache::has($connection->cacheKey().':decision-check-waiting')) return false;
        $lock = Cache::lock('kasra:'.tenant('id').':'.$connection->id.':operation', 180);
        if (! $lock->get()) {
            return false;
        }
        try {
            $client = $this->client($connection);
            $profile = $client->authenticate();
            // Fetch all datasets before committing so a partial or truncated
            // provider response cannot retire existing records.
            $history = ! $live || ! Cache::has($connection->cacheKey().':history');
            $monthly = $live && $client instanceof KasraMcpClient ? $client->currentMonthly() : $client->monthly();
            $items = [];
            foreach ($history ? ['pending', 'approved', 'rejected'] : ['pending'] as $status) {
                foreach ($client->cartable($status) as $item) {
                    if (isset($items[$item['id']]) && $items[$item['id']]['status'] !== $status) {
                        throw new KasraException(__('کارتابل کسرا هنگام دریافت تغییر کرده است؛ دریافت بعدی وضعیت را تطبیق می‌دهد.'));
                    }
                    $items[$item['id']] = $item;
                }
            }
            $snapshotId = Str::uuid()->toString();
            DB::transaction(function () use ($connection, $profile, $monthly, $items, $snapshotId): void {
                $fresh = KasraConnection::query()->lockForUpdate()->findOrFail($connection->id);
                if (! $fresh->enabled || $fresh->generation !== $connection->generation) {
                    return;
                }
                foreach ($monthly['people'] as $person) {
                    $this->store($connection, 'monthly', $monthly['period'].':'.$person['code'], $person, $monthly['period'], null, $monthly['title'], $snapshotId);
                    AttendanceMapping::firstOrCreate(['personnel_code' => $person['code']], ['name_hint' => $person['name']]);
                }
                foreach ($items as $item) {
                    $record = $this->store($connection, 'request', $item['id'], $item, null, $item['status']);
                    if (in_array($item['status'], ['approved', 'rejected'], true)) {
                        KasraDecision::query()->where('record_id', $record->id)
                            ->whereIn('status', ['sending', 'awaiting_confirmation', 'needs_review'])
                            ->where('decision', $item['status'])->update(['status' => 'processed', 'error' => null]);
                    }
                }
                KasraRecord::query()->where('connection_id', $connection->id)->where('generation', $connection->generation)
                    ->where('kind', 'request')->where('source_status', 'pending')
                    ->whereNotIn('external_key', array_keys($items))->update(['source_status' => 'not_in_cartable']);
                $fresh->update([
                    'account_name' => $profile['name'], 'status' => 'connected',
                    'period_id' => $monthly['period'],
                    'snapshot_id' => $snapshotId,
                    'last_synced_at' => now(), 'last_error' => null, 'next_attempt_at' => null,
                ]);
                app(KasraCartableService::class)->reconcile($fresh);
            });

            if ($history) Cache::put($connection->cacheKey().':history', true, 300);
            return true;
        } catch (\Throwable $exception) {
            $this->failure($connection, $exception);

            return false;
        } finally {
            $lock->release();
        }
    }

    public function syncPending(KasraConnection $connection): bool
    {
        if (! $connection->enabled || $connection->next_attempt_at?->isFuture()) return false;
        if (Cache::has($connection->cacheKey().':decision-check-waiting')) return false;
        $priority = $connection->cacheKey().':pending-waiting';
        Cache::put($priority, true, 30);
        $started = microtime(true);
        $lock = Cache::lock('kasra:'.tenant('id').':'.$connection->id.':operation', 180);
        if (! $lock->get()) return false;
        try {
            $items = $this->client($connection)->cartable('pending');
            DB::transaction(function () use ($connection, $items, $started): void {
                $fresh = KasraConnection::lockForUpdate()->find($connection->id);
                if (! $fresh || ! $fresh->enabled || $fresh->generation !== $connection->generation) return;
                foreach ($items as $item) $this->store($fresh, 'request', $item['id'], $item, null, 'pending');
                KasraRecord::where('connection_id', $fresh->id)->where('generation', $fresh->generation)
                    ->where('kind', 'request')->where('source_status', 'pending')
                    ->whereNotIn('external_key', array_column($items, 'id'))->update(['source_status'=>'not_in_cartable']);
                app(KasraCartableService::class)->reconcile($fresh);
                Cache::put($fresh->cacheKey().':pending-at', gmdate(DATE_ATOM), 300);
                Cache::put($fresh->cacheKey().':pending-epoch', microtime(true), 300);
                Cache::put($fresh->cacheKey().':pending-duration-ms', (int) round((microtime(true)-$started)*1000), 300);
            });
            return true;
        } catch (\Throwable $e) {
            // Preserve the last successful work list, and respect provider backoff.
            $this->failure($connection, $e);
            return false;
        } finally { $lock->release(); Cache::forget($priority); }
    }

    public function daily(KasraConnection $connection, string $code, string $jalaliDate): array
    {
        $lock = Cache::lock('kasra:'.tenant('id').':'.$connection->id.':operation', 180);
        if (! $lock->get()) {
            throw new KasraException(__('دریافت اطلاعات کسرا در حال انجام است؛ چند لحظه بعد دوباره امتحان کنید.'));
        }
        try {
            $fresh = $connection->fresh();
            if (! $fresh->enabled || $fresh->generation !== $connection->generation || $fresh->next_attempt_at?->isFuture()) {
                throw new KasraException(__('اتصال آمادهٔ دریافت اطلاعات نیست.'));
            }

            return $this->client($connection)->daily($code, $jalaliDate);
        } finally {
            $lock->release();
        }
    }

    /** Persist only an explicit human decision; workers cannot invent or change it. */
    public function queueDecision(KasraRecord $record,string $decision,string $note,int $actorId):KasraDecision
    {
        $actor=\App\Models\User::findOrFail($actorId);abort_unless((int)auth()->id()===$actorId&&$actor->status==='active',403);app(KasraAccess::class)->authorizeDecide($actor);
        abort_unless(in_array($decision,['approved','rejected'],true),422);
        validator(['note'=>$note],['note'=>[$decision==='rejected'?'required':'nullable','string','max:1000']])->validate();
        $attempt=DB::transaction(function()use($record,$decision,$note,$actorId){
            $c=KasraConnection::lockForUpdate()->findOrFail($record->connection_id);$r=KasraRecord::lockForUpdate()->findOrFail($record->id);
            abort_unless($c->enabled&&(int)$c->configured_by===$actorId&&$r->generation===$c->generation&&$r->kind==='request'&&$r->source_status==='pending'&&hash_equals($r->fingerprint,$record->fingerprint),409);
            $old=KasraDecision::where('record_id',$r->id)->where('expected_fingerprint',$r->fingerprint)->first();
            if($old&&$old->status!=='not_sent')throw new KasraException(__('برای این نسخه قبلاً تصمیم ثبت شده است؛ نتیجه را بررسی کنید.'));
            return KasraDecision::updateOrCreate(['record_id'=>$r->id,'expected_fingerprint'=>$r->fingerprint],['actor_id'=>$actorId,'decision'=>$decision,'note'=>$note,'status'=>'queued','error'=>null]);
        });
        \App\Jobs\SendQueuedKasraDecision::dispatch((string)tenant('id'),$attempt->id,now()->getTimestamp());
        return $attempt;
    }
    /** Busy retries happen only before the durable sending claim and provider write. */
    public function runQueuedDecision(int $id,int $requestedAt):bool
    {
        $attempt=KasraDecision::find($id);if(!$attempt||$attempt->status!=='queued')return false;
        $record=KasraRecord::find($attempt->record_id);$connection=$record?KasraConnection::find($record->connection_id):null;$token='decision-'.$id;
        try {
            $actor=\App\Models\User::find($attempt->actor_id);abort_unless($actor&&$actor->status==='active',403);app(KasraAccess::class)->authorizeDecide($actor);
            if(!$record||!$connection||now()->getTimestamp()-$requestedAt>180)throw new KasraException(__('تصمیم پیش از ارسال منقضی شد؛ هیچ تصمیمی ارسال نشد. دوباره بررسی کنید.'));
            if(!hash_equals($attempt->expected_fingerprint,$record->fingerprint))throw new KasraException(__('درخواست تغییر کرده است؛ تصمیم ارسال نشد.'));
            Cache::put($connection->cacheKey().':decision-check-waiting',$token,240);
            $this->decide($record,$attempt->decision,(string)$attempt->note,(int)$attempt->actor_id,$attempt->id);
            return false;
        }catch(KasraAccountBusy){return true;}
        catch(\Throwable $e){KasraDecision::whereKey($id)->where('status','queued')->update(['status'=>'not_sent','error'=>$this->message($e)]);return false;}
        finally {if($connection&&KasraDecision::find($id)?->status!=='queued'&&Cache::get($connection->cacheKey().':decision-check-waiting')===$token)Cache::forget($connection->cacheKey().':decision-check-waiting');}
    }

    /** Called only after an explicit manager action. Background sync never decides. */
    public function decide(KasraRecord $record, string $decision, string $note, int $actorId, ?int $queuedId = null): KasraDecision
    {
        abort_unless(in_array($decision, ['approved', 'rejected'], true), 422);
        $connection = KasraConnection::findOrFail($record->connection_id);
        abort_unless((int) $connection->configured_by === $actorId, 403);
        abort_unless($connection->enabled && $record->generation === $connection->generation && $record->kind === 'request' && $record->source_status === 'pending', 409);
        if ($connection->next_attempt_at?->isFuture()) {
            throw new KasraException(__('اتصال کسرا موقتاً در انتظار تلاش مجدد است.'));
        }
        $lock = Cache::lock('kasra:'.tenant('id').':'.$connection->id.':operation', 180);
        if (! $lock->get()) {
            throw new KasraAccountBusy(__('عملیات دیگری برای کسرا در حال انجام است؛ تصمیم در نوبت ارسال می‌ماند.'));
        }
        try {
            $client = $this->client($connection);
            $current = collect($client->cartable())->firstWhere('id', $record->external_key);
            if (! $current || $current['fingerprint'] !== $record->fingerprint) {
                throw new KasraException(__('درخواست در کسرا تغییر کرده است؛ ابتدا اطلاعات را تازه کنید.'));
            }
            $attempt = DB::transaction(function () use ($record, $decision, $note, $actorId, $connection, $queuedId): KasraDecision {
                $fresh = KasraConnection::query()->lockForUpdate()->findOrFail($connection->id);
                abort_unless($fresh->enabled && $fresh->generation === $record->generation, 409);
                if($queuedId!==null){
                    $queued=KasraDecision::lockForUpdate()->findOrFail($queuedId);
                    abort_unless($queued->status==='queued'&&(int)$queued->record_id===(int)$record->id&&(int)$queued->actor_id===$actorId&&$queued->decision===$decision&&$queued->expected_fingerprint===$record->fingerprint&&(string)$queued->note===$note,409);
                    $queued->update(['status'=>'sending','error'=>null]);return $queued;
                }
                if (KasraDecision::where('record_id', $record->id)->where('expected_fingerprint', $record->fingerprint)->where('status', '!=', 'not_sent')->exists()) {
                    throw new KasraException(__('برای این نسخهٔ درخواست قبلاً اقدام ثبت شده؛ نتیجه را در کسرا بررسی کنید.'));
                }

                // Only a proven preflight failure may reuse the unique version row.
                // All sending/unknown/final rows remain blocked above.
                return KasraDecision::updateOrCreate([
                    'record_id' => $record->id, 'expected_fingerprint' => $record->fingerprint,
                ], [
                    'actor_id' => $actorId, 'decision' => $decision, 'note' => $note,
                    'status' => 'sending', 'error' => null,
                ]);
            });
            try {
                $client->decide($current, $decision, $note);
                $attempt->update(['status' => 'awaiting_confirmation']);
                $confirmed = collect($client->cartable($decision))->firstWhere('id', $record->external_key);
                if ($confirmed) {
                    $this->store($connection, 'request', $confirmed['id'], $confirmed, null, $decision);
                    $attempt->update(['status' => 'processed']);
                }
            } catch (KasraDecisionNotSent $exception) {
                $attempt->update(['status' => 'not_sent', 'error' => $exception->getMessage()]);
            } catch (\Throwable $exception) {
                // A timeout can happen after the provider committed. Preserve
                // this version's attempt and never resend automatically.
                $attempt->update(['status' => 'needs_review', 'error' => $this->message($exception)]);
            }

            app(KasraCartableService::class)->reconcile($connection->fresh());
            return $attempt;
        } finally {
            $lock->release();
        }
    }

    /** Explicit read-through: do not enqueue attendance reports or repeat decisions. */
    public function refreshDecisionStatus(KasraRecord $record, int $actorId): string
    {
        $connection = KasraConnection::findOrFail($record->connection_id);
        abort_unless((int) $connection->configured_by === $actorId && $connection->enabled
            && $record->generation === $connection->generation && $record->kind === 'request', 403);
        if ($connection->next_attempt_at?->isFuture()) throw new KasraException(__('کسرا موقتاً پاسخ‌گو نیست؛ پس از پایان زمان انتظار دوباره بررسی کنید.'));
        $lock = Cache::lock('kasra:'.tenant('id').':'.$connection->id.':operation', 180);
        if (! $lock->get()) throw new KasraAccountBusy(__('حساب کسرا مشغول همگام‌سازی است؛ بررسی در نوبت می‌ماند.'));
        try {
            $client = $this->client($connection);
            $matches = [];
            foreach (['pending', 'approved', 'rejected'] as $status) {
                $match = collect($client->cartable($status))->firstWhere('id', $record->external_key);
                if ($match) $matches[$status] = $match;
            }
            if (count($matches) > 1) throw new KasraException(__('وضعیت درخواست هنگام بررسی تغییر کرد؛ نتیجه قطعی نیست. دوباره بررسی کنید.'));
            return DB::transaction(function () use ($connection, $record, $matches): string {
                $fresh = KasraConnection::lockForUpdate()->findOrFail($connection->id);
                abort_unless($fresh->enabled && $fresh->generation === $record->generation && (int) $fresh->configured_by === (int) $connection->configured_by, 409);
                $status = array_key_first($matches);
                if ($status !== null) {
                    $this->store($fresh, 'request', $record->external_key, $matches[$status], null, $status);
                    if (in_array($status, ['approved', 'rejected'], true)) {
                        KasraDecision::where('record_id', $record->id)->where('decision', $status)
                            ->whereIn('status', ['sending', 'awaiting_confirmation', 'needs_review'])->update(['status'=>'processed', 'error'=>null]);
                    }
                    app(KasraCartableService::class)->reconcile($fresh);
                }
                // A missing document is not evidence of an approval or rejection.
                return $status ?? 'unknown';
            });
        } finally { $lock->release(); }
    }

    private function store(KasraConnection $connection, string $kind, string $key, array $data, ?string $period = null, ?string $status = null, ?string $periodTitle = null, ?string $snapshotId = null): KasraRecord
    {
        $fingerprint = $data['fingerprint'] ?? hash('sha256', json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        $data['period_title'] = $periodTitle;

        return KasraRecord::updateOrCreate([
            'connection_id' => $connection->id, 'generation' => $connection->generation, 'kind' => $kind, 'external_key' => $key,
        ], [
            'personnel_code' => $data['code'] ?? null, 'person_name' => $data['name'] ?? null,
            'period' => $period, 'source_status' => $status, 'payload' => $data,
            'snapshot_id' => $snapshotId,
            'fingerprint' => $fingerprint, 'observed_at' => now(),
        ]);
    }

    private function failure(KasraConnection $connection, \Throwable $exception): void
    {
        KasraConnection::whereKey($connection->id)->where('generation', $connection->generation)->update([
            'status' => 'attention', 'last_error' => $this->message($exception),
            'next_attempt_at' => now()->addSeconds($exception instanceof KasraException ? $exception->retryAfter : 120),
        ]);
    }

    private function message(\Throwable $exception): string
    {
        return $exception instanceof KasraException ? $exception->getMessage() : __('دریافت یا ارسال کسرا کامل نشد؛ وضعیت اتصال و سند را بررسی کنید.');
    }
}
