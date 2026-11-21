<div class="space-y-5" wire:poll.30s.visible="poll">
  <div class="flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-border bg-surface p-5">
    <div><h2 class="font-bold text-ink">{{ __('حضور و غیاب من در کسرا') }}</h2><p class="mt-1 text-xs text-ink-muted">{{ __('حساب شخصی، ساعت‌های کار، مانده مرخصی و درخواست‌های شما') }}</p>
      @if($connection?->enabled)<p class="mt-2 text-xs font-semibold">{{ $connection->profile['name'] ?? '' }} @if($connection->last_synced_at) · {{ __('آخرین دریافت:') }} {{ \App\Helpers\Jalali::diffForHumans($connection->last_synced_at) }} @endif</p>@endif
    </div>
    <div class="flex gap-2"><a href="https://app.kasralite.com/Lego.Web/" target="_blank" rel="noopener noreferrer" class="teamik-button-secondary">{{ __('باز کردن کسرا') }}</a>@if($connection?->enabled)<button type="button" wire:click="refreshNow" wire:loading.attr="disabled" class="teamik-button-primary">{{ __('تازه‌سازی') }}</button>@endif</div>
  </div>
  @if($message)<p role="status" class="rounded-xl border border-border bg-subtle p-4 text-sm leading-7">{{ $message }}</p>@endif
  @foreach(['connection','report','request'] as $errorKey)@error($errorKey)<p role="alert" class="rounded-xl border border-danger bg-danger-soft p-3 text-sm text-danger">{{ $message }}</p>@enderror @endforeach
  @if($connection?->enabled && $connection->last_error)<p role="alert" class="rounded-xl border border-danger bg-danger-soft p-3 text-sm text-danger">{{ $connection->last_error }} {{ __('اطلاعات نمایش‌داده‌شده مربوط به آخرین دریافت موفق است.') }}</p>@endif
  @foreach($snapshot['section_errors'] ?? [] as $section)<p role="status" class="rounded-xl border border-border bg-subtle p-3 text-sm">{{ __('دریافت بخش «:section» کامل نشد؛ اگر اطلاعات قبلی موجود باشد، همان نمایش داده می‌شود.', ['section'=>match($section){'dashboard'=>__('خلاصه و مانده مرخصی'),'report'=>__('ترددها و درخواست‌ها'),'catalog'=>__('انواع درخواست'),default=>__('دوره‌های کارکرد')}]) }}</p>@endforeach
  @if($requestOnly)<div class="flex flex-wrap gap-2"><button type="button" wire:click="selectPane('new')" class="teamik-button-secondary">{{ __('درخواست موردی') }}</button><button type="button" wire:click="selectPane('university')" class="teamik-button-secondary">{{ __('فرایند مرخصی خودکار') }}</button></div>@endif
  @if($connection?->enabled && !$requestOnly)
    <nav class="teamik-secondary-tabs flex flex-wrap gap-2" aria-label="{{ __('بخش‌های حساب شخصی کسرا') }}">
      @foreach(['overview'=>__('خلاصه و مانده مرخصی'),'daily'=>__('ترددها و ساعت‌ها'),'requests'=>__('درخواست‌های من'),'new'=>__('درخواست جدید'),'connection'=>__('اتصال شخصی')] as $key=>$label)<button type="button" wire:click="selectPane('{{ $key }}')" class="teamik-tab {{ $pane===$key ? 'is-active' : '' }}" aria-pressed="{{ $pane===$key ? 'true' : 'false' }}">{{ $label }}</button>@endforeach
    </nav>
    @if(! $snapshot && $pane!=='connection')<p class="rounded-xl border border-border p-5 text-sm">{{ __('اطلاعات حساب شما در حال دریافت از کسراست؛ نتیجه همین‌جا نمایش داده می‌شود.') }}</p>@endif
  @endif
  @if($requestOnly && $pane==='university' && !$connection?->enabled)<livewire:hr.university-schedule />@endif
  @if(! $connection?->enabled || $pane==='connection')
    <section class="rounded-2xl border border-border bg-surface p-5">
      <h3 class="font-bold">{{ __('ورود به حساب شخصی کسرا') }}</h3>
      <p class="mt-2 text-sm leading-7 text-ink-muted">{{ __('اطلاعات ورود فقط برای اتصال حساب خودتان به کسرا استفاده و رمز‌شده نگهداری می‌شود. این اتصال دسترسی مدیریتی تیمیک ایجاد نمی‌کند.') }}</p>
      @if($candidateProfile)
        <div class="mt-4 rounded-xl border border-border p-4"><p class="font-bold">{{ $candidateProfile['name'] }}</p><p class="mt-1 text-sm">{{ __('کد پرسنلی:') }} <bdi>{{ $candidateProfile['code'] }}</bdi></p>
          <p class="mt-3 text-sm">{{ __('آیا این حساب شخصی شماست؟ با تأیید، اطلاعات همین حساب در حضور و غیاب شما نمایش داده می‌شود.') }}</p>
          <div class="mt-4 flex gap-2"><button type="button" wire:click="confirmConnection" wire:loading.attr="disabled" class="teamik-button-primary">{{ __('بله، حساب خودم را متصل کن') }}</button><button type="button" wire:click="cancelConnection" class="teamik-button-secondary">{{ __('انصراف') }}</button></div>
        </div>
      @else
        <form wire:submit="checkConnection" class="mt-4 grid gap-4 sm:grid-cols-2">
          <label class="text-sm font-semibold">{{ __('نام کاربری کسرا') }}<input wire:model="username" autocomplete="username" maxlength="150" dir="ltr" class="teamik-field mt-2" required>@error('username')<span class="text-danger">{{ $message }}</span>@enderror</label>
          <label class="text-sm font-semibold">{{ __('رمز کسرا') }}<input wire:model="password" type="password" autocomplete="current-password" maxlength="500" dir="ltr" class="teamik-field mt-2" required>@error('password')<span class="text-danger">{{ $message }}</span>@enderror</label>
          <div><button type="submit" wire:loading.attr="disabled" class="teamik-button-primary">{{ __('بررسی حساب شخصی') }}</button></div>
        </form>
      @endif
      @if($connection?->enabled)<button type="button" wire:click="disconnect" wire:confirm="{{ __('اتصال حساب شخصی و رمز ذخیره‌شدهٔ آن حذف شود؟') }}" class="mt-5 teamik-button-secondary text-danger">{{ __('قطع اتصال و حذف اطلاعات ورود') }}</button>@endif
    </section>
  @elseif($pane==='overview')
    <p class="text-sm text-ink-muted">{{ $snapshot['dashboard']['period'] ?? '' }}</p>
    <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-5">@foreach($snapshot['dashboard']['metrics'] ?? [] as $metric)<article class="rounded-2xl border border-border bg-surface p-5"><h3 class="text-xs font-semibold text-ink-muted">{{ $metric['name'] }}</h3><p class="mt-3 text-2xl font-bold"><bdi>{{ $metric['value'] }}</bdi></p><p class="mt-1 text-xs text-ink-muted">{{ $metric['unit'] }}</p></article>@endforeach</div>
    <div class="grid gap-4 md:grid-cols-2">
      <section class="rounded-2xl border border-border bg-surface p-5"><h3 class="font-bold">{{ __('موارد نیازمند بررسی') }}</h3>@forelse($snapshot['dashboard']['needed'] ?? [] as $item)<p class="mt-3 flex justify-between gap-3 text-sm"><span>{{ $item['name'] }}</span><span>{{ $item['value'] }} {{ $item['unit'] }}</span></p>@empty<p class="mt-3 text-sm text-ink-muted">{{ __('اطلاعات این بخش هنوز دریافت نشده است.') }}</p>@endforelse</section>
      <section class="rounded-2xl border border-border bg-surface p-5"><h3 class="font-bold">{{ __('وضعیت مجوزها در کسرا') }}</h3>@forelse($snapshot['dashboard']['credit_status'] ?? [] as $item)<p class="mt-3 flex justify-between gap-3 text-sm"><span>{{ $item['name'] }}</span><span>{{ $item['value'] }}</span></p>@empty<p class="mt-3 text-sm text-ink-muted">{{ __('برای جزئیات، درخواست‌های من را باز کنید.') }}</p>@endforelse</section>
    </div>
  @elseif(in_array($pane,['daily','requests'],true))
    <form wire:submit="loadPeriod" class="flex flex-wrap items-end gap-3"><label class="text-sm font-semibold">{{ __('دورهٔ گزارش') }}<select wire:model="period" class="teamik-field mt-2"><option value="">{{ __('دورهٔ جاری') }}</option>@foreach($snapshot['periods'] ?? [] as $option)<option value="{{ $option['id'] }}">{{ $option['title'] }}</option>@endforeach</select></label><button type="submit" wire:loading.attr="disabled" class="teamik-button-secondary">{{ __('نمایش دوره') }}</button>@error('period')<span class="text-sm text-danger">{{ $message }}</span>@enderror</form>
    <p class="text-sm font-semibold">{{ $report['monthly']['title'] ?? '' }}</p>
    @if($pane==='daily')
      <div class="grid gap-3 sm:grid-cols-3">@foreach($report['monthly']['people'][0]['metrics'] ?? [] as $name=>$amount)<div class="rounded-xl border border-border p-3 text-sm"><span class="text-ink-muted">{{ $name }}</span><strong class="ms-3"><bdi>{{ $amount }}</bdi></strong></div>@endforeach</div>
      @php $columns=array_keys($report['daily']['rows'][0]['values'] ?? []); @endphp
      <div class="overflow-x-auto rounded-xl border border-border"><table class="ats-v2-table min-w-full"><thead><tr>@foreach($columns as $column)<th class="whitespace-nowrap">{{ $column }}</th>@endforeach</tr></thead><tbody>@forelse($report['daily']['rows'] ?? [] as $row)<tr>@foreach($columns as $column)<td class="whitespace-nowrap"><bdi>{{ $row['values'][$column] ?? '—' }}</bdi></td>@endforeach</tr>@empty<tr><td class="p-5">{{ __('ترددی برای نمایش از این دوره دریافت نشده است.') }}</td></tr>@endforelse</tbody></table></div>
    @else
      <div class="flex justify-end"><button type="button" wire:click="startAnotherRequest" class="teamik-button-primary">{{ __('درخواست جدید') }}</button></div>
      @if($attempts->isNotEmpty())<section class="rounded-2xl border border-border bg-surface p-4"><h3 class="font-bold">{{ __('پیگیری ارسال‌های اخیر از تیمیک') }}</h3>@foreach($attempts as $attempt)<div class="mt-3 border-t border-border pt-3 text-sm"><span>{{ $attempt->payload['start_date'] ?? '' }} — {{ $attempt->payload['end_date'] ?? '' }}</span><strong class="ms-3">{{ match($attempt->status){'submitted'=>__('دریافت توسط کسرا تأیید شد'),'queued'=>__('در صف پردازش کسرا'),'rejected'=>__('کسرا نپذیرفت'),default=>__('نیازمند بررسی نتیجه در کسرا')} }}</strong>@if($attempt->result['message'] ?? null)<p class="mt-2 leading-7 text-ink-muted">{{ $attempt->result['message'] }}</p>@endif</div>@endforeach</section>@endif
      <div class="space-y-3">@forelse($report['requests'] ?? [] as $request)<article class="rounded-xl border border-border bg-surface p-4"><div class="flex flex-wrap justify-between gap-3"><h3 class="font-bold">{{ $request['type'] ?: __('درخواست ثبت‌شده در کسرا') }}</h3><span class="text-sm font-semibold">{{ $request['status'] ?: __('وضعیت در پاسخ کسرا مشخص نشده است') }}</span></div><p class="mt-2 text-sm"><bdi>{{ $request['start_date'] }} {{ $request['start_time'] }}</bdi> — <bdi>{{ $request['end_date'] }} {{ $request['end_time'] }}</bdi></p><p class="mt-2 text-sm leading-7 text-ink-muted">{{ $request['description'] }}</p><p class="mt-2 text-xs text-ink-muted">{{ __('شمارهٔ سند:') }} {{ $request['id'] }}</p></article>@empty<p class="rounded-xl border border-border p-5 text-sm">{{ __('درخواست ثبت‌شده‌ای برای نمایش از این دوره دریافت نشده است.') }}</p>@endforelse</div>
    @endif
  @elseif($pane==='university')
    <livewire:hr.university-schedule />
  @elseif($pane==='new')
    <section class="rounded-2xl border border-border bg-surface p-5">
      <h3 class="font-bold">{{ __('ثبت درخواست در کسرا') }}</h3><p class="mt-2 text-sm leading-7 text-ink-muted">{{ __('نوع درخواست از امکانات مجاز حساب شما در کسرا خوانده می‌شود. پس از مرور و تأیید شما، درخواست برای همان حساب ارسال می‌شود.') }}</p>
      @if($requestKey && ! $review)<p class="mt-4 text-sm">{{ __('این درخواست قبلاً ارسال شده است. ابتدا نتیجهٔ آن را در درخواست‌های من یا کسرا بررسی کنید.') }}</p><button type="button" wire:click="startAnotherRequest" class="teamik-button-secondary mt-4">{{ __('نتیجه را بررسی کردم؛ درخواست دیگری دارم') }}</button>
      @elseif($review)
        <div class="mt-4 rounded-xl border border-border p-4 space-y-3"><h4 class="font-bold">{{ __('مرور پیش از ارسال') }}</h4><p>{{ collect($snapshot['catalog'] ?? [])->firstWhere('id',$review['credit_type_id'])['title'] ?? '' }}</p><p><bdi>{{ $review['start_date'] }} {{ $review['start_time'] }}</bdi> — <bdi>{{ $review['end_date'] }} {{ $review['end_time'] }}</bdi></p><p class="text-sm leading-7">{{ $review['description'] }}</p><p class="text-sm font-semibold">{{ __('ارسال این درخواست به‌معنای تأیید مرخصی نیست؛ گردش تأیید در کسرا ادامه پیدا می‌کند.') }}</p><div class="flex gap-2"><button type="button" wire:click="confirmRequest" wire:loading.attr="disabled" class="teamik-button-primary">{{ __('تأیید و ارسال به کسرا') }}</button><button type="button" wire:click="cancelReview" class="teamik-button-secondary">{{ __('ویرایش') }}</button></div></div>
      @else
        <form wire:submit="reviewRequest" class="mt-4 grid gap-4 sm:grid-cols-2">
          <label class="sm:col-span-2 text-sm font-semibold">{{ __('نوع درخواست') }}<select wire:model.live="creditType" class="teamik-field mt-2" required><option value="">{{ __('انتخاب کنید') }}</option>@foreach($snapshot['catalog'] ?? [] as $type)<option value="{{ $type['id'] }}">{{ $type['group'] }} · {{ $type['title'] }}</option>@endforeach</select></label>
          <label class="text-sm font-semibold">{{ __('از تاریخ شمسی') }}<input wire:model="startDate" dir="ltr" maxlength="10" placeholder="1405/08/01" class="teamik-field mt-2" required></label>
          <label class="text-sm font-semibold">{{ __('تا تاریخ شمسی') }}<input wire:model="endDate" dir="ltr" maxlength="10" placeholder="1405/08/01" class="teamik-field mt-2" required></label>
          @if($selectedType && ! $selectedType['daily'])<label class="text-sm font-semibold">{{ __('از ساعت') }}<input type="time" wire:model="startTime" class="teamik-field mt-2" required></label><label class="text-sm font-semibold">{{ __('تا ساعت') }}<input type="time" wire:model="endTime" class="teamik-field mt-2" required></label>@endif
          <label class="sm:col-span-2 text-sm font-semibold">{{ __('توضیحات') }}<textarea wire:model="description" maxlength="1000" rows="3" class="teamik-field mt-2"></textarea></label>
          @foreach(['creditType','startDate','endDate','startTime','endTime','description'] as $field)@error($field)<p class="text-sm text-danger">{{ $message }}</p>@enderror @endforeach
          <div><button type="submit" wire:loading.attr="disabled" class="teamik-button-primary" @disabled(empty($snapshot['catalog']))>{{ __('مرور درخواست') }}</button></div>
        </form>
      @endif
    </section>
  @endif
</div>
