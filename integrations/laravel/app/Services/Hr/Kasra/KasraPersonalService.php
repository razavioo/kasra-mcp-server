<?php

namespace App\Services\Hr\Kasra;

use App\Models\AttendanceMapping;
use App\Models\KasraPersonalConnection;
use App\Models\KasraPersonalSubmission;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class KasraPersonalService
{
    protected function client(KasraPersonalConnection $connection): KasraMcpClient
    {
        return app()->makeWith(KasraMcpClient::class, ['account' => $connection]);
    }

    public function verify(User $user, array $credentials): array
    {
        $identity = User::findOrFail($user->id);
        $profile = $this->client(new KasraPersonalConnection([
            'user_id' => $user->id, 'credentials' => $credentials, 'generation' => 'verify-'.$user->id,
        ]))->authenticate();
        $code = $profile['code'] ?? '';
        if (! is_string($code) || ! preg_match('/^[0-9]{1,30}$/', $code) || ! is_string($profile['name'] ?? null)) {
            throw new KasraException(__('هویت حساب شخصی کسرا تأیید نشد.'));
        }
        $mapped = AttendanceMapping::where('user_id', $user->id)->pluck('personnel_code');
        if ($mapped->isNotEmpty() && ! $mapped->containsStrict($code)) {
            throw new KasraException(__('این حساب کسرا با کد پرسنلی متصل به حساب تیمیک شما یکسان نیست.'));
        }
        try { $national = (string) $identity->national_id; }
        catch (\Throwable) { throw new KasraException(__('هویت پروفایل تیمیک قابل بررسی نیست؛ ابتدا اطلاعات پرسنلی را بررسی کنید.')); }
        if (preg_match('/^[0-9]{10}$/', $code) && preg_match('/^[0-9]{10}$/', $national) && $national !== $code) {
            throw new KasraException(__('این حساب کسرا با هویت ثبت‌شدهٔ شما در تیمیک یکسان نیست.'));
        }
        if (KasraPersonalConnection::where('personnel_code', $code)->where('user_id', '!=', $user->id)->exists()) {
            throw new KasraException(__('این حساب کسرا به کاربر دیگری در همین سازمان متصل است.'));
        }

        return ['code' => $code, 'name' => $profile['name']];
    }

    public function connect(User $user, array $credentials, array $expectedProfile): KasraPersonalConnection
    {
        $lock = Cache::lock('kasra-personal:'.tenant('id').':'.$user->id.':operation', 180);
        if (! $lock->get()) throw new KasraException(__('عملیات دیگری برای حساب شما در حال انجام است؛ کمی بعد دوباره تلاش کنید.'));
        try {
        $profile = $this->verify($user, $credentials);
        if ($profile !== $expectedProfile) {
            throw new KasraException(__('هویت حساب از زمان بررسی تغییر کرده است؛ دوباره ورود را بررسی کنید.'));
        }
        return DB::transaction(function () use ($user, $credentials, $profile): KasraPersonalConnection {
            $connection = KasraPersonalConnection::where('user_id', $user->id)->lockForUpdate()->firstOrNew();
            $connection->fill([
                'user_id' => $user->id, 'personnel_code' => $profile['code'], 'profile' => $profile,
                'credentials' => $credentials, 'generation' => Str::uuid()->toString(), 'enabled' => true,
                'status' => 'configured', 'snapshot' => null, 'last_synced_at' => null, 'next_attempt_at' => null, 'last_error' => null,
            ])->save();
            activity('attendance')->performedOn($connection)->causedBy($user)->event('kasra_personal_connected')->log('kasra_personal_connected');

            return $connection;
        });
        } finally { $lock->release(); }
    }

    public function refresh(KasraPersonalConnection $connection): bool
    {
        if (! $connection->enabled || $connection->next_attempt_at?->isFuture()) {
            return false;
        }
        $lock = Cache::lock('kasra-personal:'.tenant('id').':'.$connection->user_id.':operation', 180);
        if (! $lock->get()) return false;
        try {
            $result = $this->client($connection)->personalSnapshot();
            if (($result['profile']['code'] ?? null) !== $connection->personnel_code
                || ! is_array($result['sections'] ?? null) || ! is_array($result['errors'] ?? null)) {
                throw new KasraException(__('پاسخ حساب شخصی کسرا کامل یا منطبق با هویت شما نیست.'));
            }
            // A failed or stale generation can never replace the last complete view.
            $fresh = $connection->fresh();
            if (! $fresh || ! $fresh->enabled || $fresh->generation !== $connection->generation) return false;
            $snapshot = $fresh->snapshot ?? [];
            $snapshot['profile'] = $result['profile'];
            foreach (['dashboard', 'periods', 'catalog'] as $section) {
                if (array_key_exists($section, $result['sections'])) {
                    $snapshot[$section] = $result['sections'][$section];
                    $snapshot['observed_at'][$section] = now()->toIso8601String();
                }
            }
            if (isset($result['sections']['report']['monthly']['period'], $result['sections']['report']['daily']['rows'], $result['sections']['report']['requests'])) {
                $snapshot = array_replace($snapshot, $result['sections']['report']);
                $snapshot['observed_at']['report'] = now()->toIso8601String();
            }
            $snapshot['section_errors'] = array_keys($result['errors']);
            $fresh->update(['snapshot' => $snapshot, 'status' => $result['errors'] ? 'attention' : 'connected',
                'last_synced_at' => $result['sections'] ? now() : $fresh->last_synced_at,
                'last_error' => $result['errors'] ? __('دریافت بخشی از اطلاعات کسرا کامل نشد؛ وضعیت هر بخش جدا نمایش داده می‌شود.') : null,
                'next_attempt_at' => $result['errors'] ? now()->addMinutes(2) : null]);
            return true;
        } catch (\Throwable $exception) {
            KasraPersonalConnection::whereKey($connection->id)->where('generation', $connection->generation)->where('enabled', true)->update([
                'status' => 'attention', 'last_error' => $this->safeMessage($exception),
                'next_attempt_at' => now()->addSeconds($exception instanceof KasraException ? $exception->retryAfter : 120),
            ]);
            return false;
        } finally {
            $lock->release();
        }
    }

    public function report(KasraPersonalConnection $connection, string $period): array
    {
        $this->assertOwner($connection);
        if (! collect($connection->snapshot['periods'] ?? [])->contains('id', $period)) {
            throw new KasraException(__('دورهٔ انتخاب‌شده در حساب شما موجود نیست.'));
        }
        $lock = Cache::lock('kasra-personal:'.tenant('id').':'.$connection->user_id.':operation', 180);
        if (! $lock->get()) throw new KasraException(__('اطلاعات حساب در حال دریافت است؛ چند لحظه بعد دوباره تلاش کنید.'));
        try {
            return $this->client($connection)->personalReport($period);
        } finally { $lock->release(); }
    }

    public function submit(KasraPersonalConnection $connection, array $payload, string $key, ?\Closure $beforeSend = null): KasraPersonalSubmission
    {
        $this->assertOwner($connection);
        $lock = Cache::lock('kasra-personal:'.tenant('id').':'.$connection->user_id.':operation', 180);
        if (! $lock->get()) throw new KasraException(__('عملیات دیگری برای همین حساب در حال انجام است؛ چند لحظه بعد تلاش کنید.'));
        try {
            $fresh = $connection->fresh();
            abort_unless($fresh && $fresh->enabled && $fresh->generation === $connection->generation, 409);
            $prior = KasraPersonalSubmission::where('request_key', $key)->first();
            if ($prior) {
                abort_unless($prior->connection_id === $connection->id && $prior->payload === $payload, 409);
                return $prior;
            }
            if ($beforeSend) $beforeSend($this->client($fresh));
            // Persist the attempt before any call capable of writing to Kasra.
            $attempt = KasraPersonalSubmission::create([
                'connection_id' => $connection->id, 'generation' => $connection->generation,
                'request_key' => $key, 'payload' => $payload, 'status' => 'sending',
            ]);
            try {
                $result = $this->client($connection)->submitPersonal($payload + ['request_key' => $key, 'confirmed' => true]);
                $status = $result['status'] ?? 'needs_review';
                $attempt->update(['status' => in_array($status, ['submitted', 'queued', 'rejected', 'needs_review'], true) ? $status : 'needs_review', 'result' => $result]);
            } catch (\Throwable) {
                $attempt->update(['status' => 'needs_review', 'result' => ['message' => __('نتیجهٔ ارسال قطعی نیست؛ پیش از ثبت دوباره، درخواست‌های خود را در کسرا بررسی کنید.')]]);
            }
            activity('attendance')->performedOn($attempt)->causedBy(auth()->user())->event('kasra_personal_request_sent')
                ->withProperties(['status' => $attempt->status])->log('kasra_personal_request_sent');
            return $attempt;
        } finally { $lock->release(); }
    }

    public function disconnect(KasraPersonalConnection $connection): void
    {
        $this->assertOwner($connection);
        $lock = Cache::lock('kasra-personal:'.tenant('id').':'.$connection->user_id.':operation', 180);
        if (! $lock->get()) throw new KasraException(__('عملیات حساب در حال انجام است؛ کمی بعد اتصال را قطع کنید.'));
        try {
            $connection->update(['enabled' => false, 'credentials' => null, 'snapshot' => null, 'generation' => Str::uuid()->toString(), 'status' => 'disconnected', 'last_error' => null, 'next_attempt_at' => null]);
            activity('attendance')->performedOn($connection)->causedBy(auth()->user())->event('kasra_personal_disconnected')->log('kasra_personal_disconnected');
        } finally { $lock->release(); }
    }

    private function assertOwner(KasraPersonalConnection $connection): void
    {
        abort_unless(auth()->check() && (int) $connection->user_id === (int) auth()->id() && $connection->enabled, 403);
    }

    private function safeMessage(\Throwable $exception): string
    {
        return $exception instanceof KasraException ? $exception->getMessage() : __('دریافت اطلاعات شخصی از کسرا کامل نشد؛ اطلاعات قبلی حفظ شده است.');
    }
}
