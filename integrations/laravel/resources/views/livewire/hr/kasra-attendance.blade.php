<section wire:poll.15s.visible class="space-y-4" aria-label="{{ __('حضور و غیاب کسرا') }}">
  <div class="rounded-2xl border border-border bg-surface p-5">
    <div class="flex flex-wrap items-start justify-between gap-4">
      <div>
        <h2 class="font-bold text-ink">{{ __('اتصال حضور و غیاب کسرا') }}</h2>
        <p class="mt-2 text-sm text-ink-muted">{{ $connection?->account_name ?: __('با حساب مدیریتی کسرا متصل شوید تا گزارش پرسنل و درخواست‌ها اینجا نمایش داده شوند.') }}</p>
        @if($connection)
          <p class="mt-2 text-xs text-ink-muted" role="status">
            @if(!$connection->enabled){{ __('دریافت خودکار متوقف است.') }}
            @elseif($connection->last_synced_at && $connection->last_synced_at->gt(now()->subSeconds(45)) && !$connection->last_error){{ __('دریافت خودکار فعال است.') }}
            @else{{ __('اطلاعات هنوز تازه نشده یا اتصال نیاز به بررسی دارد.') }}@endif
            {{ __('آخرین دریافت موفق:') }} {{ $connection->last_synced_at ? \App\Helpers\Jalali::formatDateTime($connection->last_synced_at) : __('هنوز انجام نشده') }}
          </p>
        @endif
      </div>
      <div class="flex flex-wrap gap-2">
        @if($connection?->enabled)<button type="button" wire:click="syncNow" wire:loading.attr="disabled" class="teamik-button-secondary">{{ __('تازه‌سازی') }}</button>@endif
        <button type="button" wire:click="selectPane('connection')" class="teamik-button-secondary">{{ __('تنظیم اتصال') }}</button>
      </div>
    </div>
    <p class="mt-3 text-xs leading-6 text-ink-muted">{{ __('دریافت خودکار در بازه‌های کوتاه انجام می‌شود. زمان پاسخ و ثبت اطلاعات در کسرا ممکن است باعث تأخیر شود. بسته‌شدن مرورگر دریافت سروری را متوقف نمی‌کند.') }}</p>
    @if($connection?->last_error)<p class="mt-3 rounded-xl bg-danger-soft p-3 text-sm text-danger" role="alert">{{ $connection->last_error }}</p>@endif
    @if($message)<p class="mt-3 rounded-xl bg-accent-soft p-3 text-sm text-accent" role="status">{{ $message }}</p>@endif
  </div>

  @if($connection && !$settingsOnly)
    <nav class="flex flex-wrap gap-2" aria-label="{{ __('گزارش‌های کسرا') }}">
      @foreach(['requests' => __('درخواست‌ها').' ('.fa_num($requestCount).')', 'monthly' => __('کارکرد ماهانه'), 'connection' => __('اتصال')] as $key => $label)
        <button type="button" wire:click="selectPane('{{ $key }}')" aria-pressed="{{ $pane === $key ? 'true' : 'false' }}" class="{{ $pane === $key ? 'teamik-button-primary' : 'teamik-button-secondary' }}">{{ $label }}</button>
      @endforeach
    </nav>
  @endif

  @if(!$connection || $pane === 'connection')
    <div class="rounded-2xl border border-border bg-surface p-5">
      @if($canConfigure)
        <h3 class="font-bold">{{ __('ورود امن حساب مدیریتی کسرا') }}</h3>
        <p class="mt-2 text-sm leading-7 text-ink-muted">{{ __('اطلاعات ورود فقط برای اتصال تیمیک به app.kasralite.com استفاده و پس از تأیید ورود، به‌صورت رمز‌شده ذخیره می‌شود. دریافت گزارش و کارتابل در پس‌زمینه انجام می‌شود و وضعیت آن همین‌جا نمایش داده می‌شود. رمز ذخیره‌شده دوباره نمایش داده نمی‌شود.') }}</p>
        <form wire:submit="saveConnection" class="mt-4 grid max-w-xl gap-4" autocomplete="off">
          <label class="text-sm font-semibold">{{ __('نام کاربری کسرا') }}<input wire:model="username" name="kasra_username" autocomplete="off" dir="ltr" class="teamik-field mt-2" maxlength="150" required></label>
          @error('username')<p class="text-sm text-danger">{{ $message }}</p>@enderror
          <label class="text-sm font-semibold">{{ __('رمز عبور کسرا') }}<input type="password" wire:model="password" name="kasra_password" autocomplete="off" dir="ltr" class="teamik-field mt-2" maxlength="500" required></label>
          @error('password')<p class="text-sm text-danger">{{ $message }}</p>@enderror
          @error('connection')<p role="alert" class="text-sm text-danger">{{ $message }}</p>@enderror
          <button type="submit" wire:loading.attr="disabled" class="teamik-button-primary"><span wire:loading.remove wire:target="saveConnection">{{ __('بررسی و ذخیرهٔ اتصال') }}</span><span wire:loading wire:target="saveConnection">{{ __('در حال بررسی دسترسی کسرا…') }}</span></button>
        </form>
        @if($connection)<button type="button" wire:click="toggleConnection" wire:loading.attr="disabled" class="teamik-button-secondary mt-4">{{ $connection->enabled ? __('توقف دریافت خودکار') : __('ادامهٔ دریافت خودکار') }}</button>@endif
        @if($connection && (int)$connection->configured_by === (int)auth()->id() && (int)$connection->id === (int)(\App\Models\AttendanceReminderSetting::find(1)?->connection_id ?? \App\Models\KasraConnection::where('enabled',true)->orderBy('id')->value('id')))<livewire:hr.attendance-reminder-settings />@endif
      @else<p class="text-sm text-ink-muted">{{ __('تنظیم حساب اتصال در اختیار مدیر سازمان یا مدیر اتصالات است.') }}</p>@endif
    </div>
  @elseif($pane === 'requests')
    <div class="rounded-2xl border border-border bg-surface p-5">
      <label class="text-sm font-bold">{{ __('وضعیت در کارتابل کسرا') }}
        <select wire:model.live="requestStatus" class="teamik-field mt-2 max-w-xs">
          <option value="all">{{ __('همهٔ وضعیت‌ها') }}</option>
          <option value="pending">{{ __('در انتظار بررسی') }}</option><option value="approved">{{ __('تأیید در کارتابل کسرا') }}</option><option value="rejected">{{ __('رد در کارتابل کسرا') }}</option><option value="not_in_cartable">{{ __('نیازمند بازخوانی وضعیت') }}</option>
        </select>
      </label>
      <p class="mt-3 text-sm text-ink-muted">{{ __('کل درخواست‌ها:') }} {{ fa_num($requestCount) }} · {{ __('منتظر بررسی:') }} {{ fa_num($pendingCount) }}</p>
      <p class="mt-3 text-xs leading-6 text-ink-muted">{{ __('تأیید در کارتابل ممکن است یکی از مراحل گردش کار کسرا باشد. نتیجهٔ نهایی تابع مراحل همان سامانه است. ماندهٔ کاردکس هنوز به این اتصال افزوده نشده است.') }}</p>
      <div class="mt-4 space-y-3">
        @forelse($requests as $request)
          @php($data = $request->payload)
          @php($attempt = $decisions->get($request->id))
          <article wire:key="kasra-request-{{ $request->id }}" class="rounded-xl border border-border p-4">
            <div class="flex flex-wrap items-start justify-between gap-3">
              <div><h3 class="font-bold">{{ $request->person_name ?: __('درخواست پرسنل') }}</h3><p class="mt-1 text-sm">{{ $data['title'] ?? '' }}</p><p class="mt-2 text-xs text-ink-muted">{{ $data['type'] ?? '' }} · {{ $data['requested_at'] ?? '' }} · {{ __('سند') }} {{ fa_num($request->external_key) }}</p><p class="mt-2 text-xs font-semibold">{{ match($request->source_status) { 'pending' => __('در انتظار بررسی'), 'approved' => __('تأیید در کارتابل کسرا'), 'rejected' => __('رد در کارتابل کسرا'), default => __('نیازمند بازخوانی وضعیت') } }}</p></div>
              @if($request->source_status === 'pending' && !$attempt && $canDecide && $connection->enabled)
                <div class="flex gap-2"><button type="button" wire:click="selectDecision({{ $request->id }}, 'approved')" class="teamik-button-primary">{{ __('بررسی و تأیید') }}</button><button type="button" wire:click="selectDecision({{ $request->id }}, 'rejected')" class="teamik-button-secondary">{{ __('بررسی و رد') }}</button></div>
              @endif
            </div>
            @if(!empty($data['note']))<p class="mt-3 whitespace-pre-wrap text-sm text-ink-muted">{{ $data['note'] }}</p>@endif
            @if($attempt)
              <p class="mt-3 text-xs font-bold" role="status">{{ match($attempt->status) { 'processed' => __('نتیجه در کارتابل کسرا بازخوانی شد'), 'sending' => __('ارسال ثبت شده؛ در انتظار بررسی نتیجه'), 'needs_review' => __('نتیجهٔ ارسال نامشخص؛ بررسی در کسرا لازم است'), default => __('در انتظار بازخوانی نتیجه از کسرا') } }}</p>
              @if($attempt->error)<p class="mt-2 text-xs text-danger">{{ $attempt->error }}</p>@endif
            @endif
          </article>
        @empty<p class="py-8 text-center text-sm text-ink-muted">{{ $connection->last_synced_at ? __('در بازهٔ کارتابل دریافت‌شده، درخواستی با این وضعیت نیست.') : __('اولین دریافت اطلاعات هنوز انجام نشده است.') }}</p>@endforelse
      </div>
      <div class="mt-4">{{ $requests->links() }}</div>
    </div>
  @elseif($pane === 'monthly')
    <div class="rounded-2xl border border-border bg-surface p-5">
      <h3 class="font-bold">{{ __('کارکرد پرسنل') }} · {{ $monthly->first()?->payload['period_title'] ?? '' }}</h3>
      <p class="mt-2 text-xs leading-6 text-ink-muted">{{ __('حضور، کارکرد و مرخصی با واحد گزارش کسرا نمایش داده می‌شوند. مقدار ترکیبی روز و ساعت، به ساعت تخمینی تبدیل نمی‌شود.') }}</p>
      <div class="mt-4 overflow-x-auto"><table class="w-full min-w-[1000px] text-right text-sm">
        <thead><tr class="border-b border-border"><th class="p-3">{{ __('پرسنل') }}</th>@foreach(['حضور','کارکرد','اضافه‌کار','کسری','مرخصی روزانه','مرخصی ساعتی','دورکاری روزانه','دورکاری ساعتی'] as $metric)<th class="whitespace-nowrap p-3">{{ __($metric) }}</th>@endforeach<th class="p-3">{{ __('جزئیات') }}</th></tr></thead>
        <tbody>@forelse($monthly as $person)
          <tr wire:key="kasra-person-{{ $person->id }}" class="border-b border-border">
              <td class="p-3"><strong>{{ $person->person_name }}</strong><span class="mt-1 block text-xs text-ink-muted">{{ $mappings->get($person->personnel_code)?->accountLabel() ?: __('هنوز با کاربر تیمیک تطبیق داده نشده') }}</span></td>
            @foreach(['حضور','کارکرد','اضافه‌کار','کسری','مرخصی روزانه','مرخصی ساعتی','دورکاری روزانه','دورکاری ساعتی'] as $metric)<td dir="ltr" class="whitespace-nowrap p-3 text-center">{{ $person->payload['metrics'][$metric] ?? '—' }}</td>@endforeach
            <td class="p-3"><button type="button" wire:click="showDaily({{ $person->id }})" wire:loading.attr="disabled" class="teamik-button-secondary">{{ __('تردد روزانه') }}</button></td>
          </tr>
        @empty<tr><td colspan="10" class="p-8 text-center text-ink-muted">{{ __('گزارش ماهانه هنوز دریافت نشده است.') }}</td></tr>@endforelse</tbody>
      </table></div>
      @error('daily')<p class="mt-3 text-sm text-danger" role="alert">{{ $message }}</p>@enderror
      @if($dailyName)
        <h4 class="mt-6 font-bold">{{ __('جزئیات تردد :name', ['name' => $dailyName]) }}</h4>
        <div class="mt-3 overflow-x-auto"><table class="w-full text-right text-xs"><tbody>@foreach($dailyRows as $day)<tr class="border-b border-border"><th class="p-3">{{ $day['date'] }}</th><td class="p-3">@foreach($day['values'] as $label => $value)<span class="m-1 inline-block">{{ $label }}: {{ $value ?: '—' }}</span>@endforeach</td></tr>@endforeach</tbody></table></div>
      @endif
    </div>
  @endif

  @if($selectedRequest)
    <div class="rounded-2xl border border-primary bg-surface p-5" role="region" aria-label="{{ __('بررسی تصمیم درخواست') }}">
      <h3 class="font-bold">{{ $selectedDecision === 'approved' ? __('تأیید درخواست در کسرا') : __('رد درخواست در کسرا') }}</h3>
      <p class="mt-3 font-bold">{{ $selectedRequest->person_name }}</p><p class="mt-2 text-sm">{{ $selectedRequest->payload['title'] ?? '' }}</p>
      <label class="mt-4 block text-sm">{{ __('توضیح مدیر') }}<textarea wire:model="decisionNote" maxlength="1000" class="teamik-field mt-2" rows="3"></textarea></label>
      @error('decisionNote')<p class="mt-2 text-sm text-danger">{{ $message }}</p>@enderror
      @error('decision')<p class="mt-2 text-sm text-danger" role="alert">{{ $message }}</p>@enderror
      <div class="mt-4 flex flex-wrap gap-2"><button type="button" wire:click="confirmDecision" wire:confirm="{{ __('این تصمیم برای همین درخواست به کسرا ارسال شود؟') }}" wire:loading.attr="disabled" class="teamik-button-primary">{{ __('ارسال تصمیم به کسرا') }}</button><button type="button" wire:click="cancelDecision" class="teamik-button-secondary">{{ __('انصراف') }}</button></div>
    </div>
  @endif
</section>
