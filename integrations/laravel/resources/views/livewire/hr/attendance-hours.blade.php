<section class="space-y-4 attendance-workspace" wire:poll.5s.visible="poll" aria-label="{{ __('ساعت‌های حضور و غیاب') }}">
  <div class="flex flex-wrap items-center justify-between gap-3">
    <p class="text-xs text-ink-muted" role="status">@if($manager && $connection?->last_synced_at){{ __('آخرین دریافت از کسرا:') }} {{ \App\Helpers\Jalali::diffForHumans($connection->last_synced_at) }}@elseif($personalConnection?->last_synced_at){{ __('آخرین دریافت حساب من:') }} {{ \App\Helpers\Jalali::diffForHumans($personalConnection->last_synced_at) }}@endif</p>
    <div class="flex flex-wrap gap-2">
      <a href="/general/cartable?type=kasra_request" wire:navigate class="teamik-button-secondary">{{ __('مشاهده درخواست‌ها') }}</a>
      <button type="button" wire:click="$set('noticeOpen', true)" class="teamik-button-secondary">{{ __('وضعیت من') }}</button>
      <button type="button" wire:click="$toggle('personalForm')" class="teamik-button-secondary">{{ __('درخواست جدید') }}</button>
      <button type="button" wire:click="refreshHours" wire:loading.attr="disabled" class="teamik-button-secondary">{{ __('تازه‌سازی') }}</button>
      @if($manager)<button type="button" wire:click="$toggle('settings')" class="teamik-button-secondary" aria-label="{{ __('تنظیمات حضور و غیاب') }}"><i class="ph ph-gear" aria-hidden="true"></i></button>@endif
    </div>
  </div>
  @if($message)<p role="status" class="rounded-xl bg-accent-soft p-3 text-sm">{{ $message }}</p>@endif
  @if($connection?->last_error)<p role="alert" class="text-sm text-danger">{{ $connection->last_error }} {{ __('اطلاعات قبلی حفظ شده است.') }}</p>@endif
  @if($manager && !$connection)<p class="text-sm text-ink-muted">{{ __('برای دریافت ساعت‌ها، حساب مدیریتی کسرا را از تنظیمات متصل کنید.') }}</p>@endif
  @if($settings && $manager)
    <div class="attendance-settings-panel rounded-xl border border-border p-4"><div class="flex flex-wrap gap-2"><a class="teamik-button-secondary" href="{{ url('/attendance?tab=import') }}">{{ __('ورود فایل') }}</a><a class="teamik-button-secondary" href="{{ url('/attendance?tab=mappings') }}">{{ __('تطبیق کارکنان') }}</a></div><livewire:hr.kasra-attendance :settings-only="true" :key="'kasra-settings'" /></div>
  @endif
  @if($personalForm)<div class="attendance-request-panel rounded-xl border border-border p-4"><livewire:hr.kasra-personal-attendance :request-only="true" /></div>@endif
  @foreach(['day','from','to','person','report'] as $field)@error($field)<p role="alert" class="text-sm text-danger">{{ $message }}</p>@enderror @endforeach

  @if($manager && $person==='')
    <form wire:submit="applyDay" class="attendance-daily-toolbar flex flex-wrap items-end gap-3" aria-label="{{ __('فیلتر گزارش روزانه') }}">
      <label class="text-xs">{{ __('روز (شمسی)') }}<input wire:model="day" dir="ltr" maxlength="10" class="teamik-field mt-1" required></label>
      <button type="submit" wire:loading.attr="disabled" class="teamik-button-primary">{{ __('نمایش روز') }}</button>
      <label class="text-xs attendance-person-search">{{ __('جستجوی فرد') }}<input type="search" wire:model.live.debounce.300ms="search" maxlength="80" class="teamik-field mt-1" placeholder="{{ __('نام یا کد پرسنلی') }}"></label>
    </form>
    <div class="ats-v2-table-wrap"><table class="ats-v2-table w-full" aria-label="{{ __('خلاصهٔ روزانهٔ کارکنان') }}">
      <thead><tr>@foreach(['شخص','ترددهای روز','حضور','کارکرد','اضافه‌کار','کسری','مرخصی‌ها','دورکاری'] as $label)<th class="whitespace-nowrap" scope="col">{{ __($label) }}</th>@endforeach</tr></thead>
      <tbody>@forelse($overview as $row)<tr wire:key="attendance-person-{{ $row['code'] }}">
        <th scope="row" class="whitespace-nowrap"><button type="button" wire:click="openPerson(@js($row['code']))" class="attendance-person-link" aria-label="{{ __('گزارش فردی :name',['name'=>$row['name']]) }}">{{ $row['name'] }}<i class="ph ph-caret-left" aria-hidden="true"></i></button></th>
        @foreach(['punches','presence','worked','overtime','deficit'] as $metric)<td><bdi class="whitespace-pre-wrap">{{ filled($row['payload'][$metric]??null)?$row['payload'][$metric]:'—' }}</bdi></td>@endforeach
        <td>@if(($row['payload']['daily_leave']??'—')==='—' && ($row['payload']['hourly_leave']??'—')==='—')—@else<span class="attendance-leave-line"><span class="text-ink-muted">{{ __('روزانه') }}</span><bdi>{{ $row['payload']['daily_leave']??'—' }}</bdi></span><span class="attendance-leave-line"><span class="text-ink-muted">{{ __('ساعتی') }}</span><bdi>{{ $row['payload']['hourly_leave']??'—' }}</bdi></span>@endif</td>
        <td>@if(($row['payload']['daily_remote']??'—')==='—' && ($row['payload']['hourly_remote']??'—')==='—')—@else<span class="attendance-leave-line"><span class="text-ink-muted">{{ __('روزانه') }}</span><bdi>{{ $row['payload']['daily_remote']??'—' }}</bdi></span><span class="attendance-leave-line"><span class="text-ink-muted">{{ __('ساعتی') }}</span><bdi>{{ $row['payload']['hourly_remote']??'—' }}</bdi></span>@endif</td>
      </tr>@empty<tr><td colspan="8" class="p-5 text-center text-ink-muted">{{ __('فردی با این فیلتر در فهرست مجاز کسرا پیدا نشد.') }}</td></tr>@endforelse</tbody>
    </table></div>{{ $overview->links() }}
    <p class="text-xs text-ink-muted">{{ __('برای دیدن ترددهای ماه و انتخاب بازهٔ تاریخ، روی نام فرد بزنید. — یعنی مقداری از کسرا دریافت نشده است.') }}</p>
  @else
    @if($manager)
      <div class="flex flex-wrap items-center justify-between gap-2"><h2 class="teamik-section-title">{{ __('گزارش فردی') }} · {{ $selected?->person_name }}</h2><button type="button" wire:click="closePerson" class="teamik-button-secondary"><i class="ph ph-arrow-right" aria-hidden="true"></i>{{ __('بازگشت به افراد') }}</button></div>
    @else
      <div class="attendance-self-summary" aria-label="{{ __('خلاصهٔ حضور و غیاب من') }}">
        <article><h2>{{ __('تردد امروز') }}</h2><p class="attendance-self-punches"><bdi>{{ $todayPayload['punches']??'—' }}</bdi></p><span class="text-xs text-ink-muted"><bdi>{{ \App\Services\Hr\Kasra\KasraDailyReport::jalali(now('Asia/Tehran')) }}</bdi></span></article>
        <article><h2>{{ __('ماندهٔ مرخصی فعلی') }}</h2>@forelse($balance as $metric)<div class="attendance-balance-value"><span class="text-xs text-ink-muted">{{ $metric['name'] }}</span><p><bdi>{{ $metric['value'] }}</bdi> <span class="text-xs text-ink-muted">{{ $metric['unit']??'' }}</span></p></div>@empty<p>—</p><span class="text-xs text-ink-muted">{{ $personalConnection?__('مانده از کسرا دریافت نشده است.'):__('با اتصال حساب شخصی کسرا نمایش داده می‌شود.') }}</span>@endforelse</article>
        <article><h2>{{ __('کارکرد این بازه') }}</h2><p><bdi>{{ $totals['worked']['value'] }}</bdi></p><span class="text-xs text-ink-muted">{{ $totals['worked']['source']==='official'?__('جمع دوره در کسرا'):__('جمع روزهای کامل دریافت‌شده') }}</span></article>
        <article><h2>{{ __('اضافه‌کاری این بازه') }}</h2><p><bdi>{{ $totals['overtime']['value'] }}</bdi></p><span class="text-xs text-ink-muted">{{ $totals['overtime']['source']==='official'?__('جمع دوره در کسرا'):__('جمع روزهای کامل دریافت‌شده') }}</span></article>
      </div>
      @if(!$personalConnection)<p class="text-xs text-ink-muted">{{ __('ساعت‌های خودتان در صورت تطبیق حساب سازمانی نمایش داده می‌شوند. برای ماندهٔ مرخصی و ثبت درخواست، حساب شخصی کسرا را یک‌بار متصل کنید.') }} <button type="button" wire:click="$set('personalForm', true)" class="attendance-person-link">{{ __('اتصال حساب شخصی') }}</button></p>@endif
    @endif
    <div class="attendance-history-header flex flex-wrap items-center justify-between gap-3"><h3>{{ $manager?__('ترددهای فرد'):__('ترددهای من') }}</h3><div class="flex flex-wrap gap-2"><button type="button" wire:click="chooseMonth('current')" wire:loading.attr="disabled" class="teamik-button-secondary">{{ __('ماه جاری') }}</button><button type="button" wire:click="chooseMonth('previous')" wire:loading.attr="disabled" class="teamik-button-secondary">{{ __('ماه قبل') }}</button></div></div>
    <form wire:submit="applyFilters" class="flex flex-wrap items-end gap-3" aria-label="{{ __('فیلتر تاریخ ترددهای فرد') }}">
      <label class="text-xs">{{ __('از روز (شمسی)') }}<input wire:model="from" dir="ltr" maxlength="10" class="teamik-field mt-1" required></label>
      <label class="text-xs">{{ __('تا روز (شمسی)') }}<input wire:model="to" dir="ltr" maxlength="10" class="teamik-field mt-1" required></label>
      <button type="submit" wire:loading.attr="disabled" class="teamik-button-primary">{{ __('نمایش بازه') }}</button>
      <span wire:loading wire:target="applyFilters,chooseMonth" class="text-xs text-ink-muted">{{ __('در حال دریافت گزارش…') }}</span>
    </form>
    <div class="attendance-matrix-wrap" role="region" aria-label="{{ __('ترددهای فرد بر اساس تاریخ؛ برای تاریخ‌های بیشتر جدول را افقی پیمایش کنید') }}" tabindex="0">
      <table class="ats-v2-table attendance-matrix"><thead><tr><th scope="col">{{ __('شاخص / تاریخ') }}</th>@foreach($dates as $date)<th scope="col" @class(['is-future'=>$date['future']])><bdi>{{ $date['date'] }}</bdi><small>{{ __($date['weekday']) }}@if($date['future']) · {{ __('آینده') }}@endif</small></th>@endforeach</tr></thead>
      <tbody>@foreach($fields as $key=>$label)<tr><th scope="row">{{ __($label) }}</th>@foreach($dates as $date)<td @class(['is-future'=>$date['future']])><bdi class="whitespace-pre-wrap">{{ !$date['future']&&filled($matrix[$date['key']][$key]??null)?$matrix[$date['key']][$key]:'—' }}</bdi></td>@endforeach</tr>@endforeach</tbody>
      </table>
    </div>
    <p class="text-xs text-ink-muted">{{ __(':received روز از :total روز گذشتهٔ این بازه دریافت شده است. — مقدار نامشخص است؛ صفر یا غیبت محسوب نمی‌شود.',['received'=>fa_num($receivedDays),'total'=>fa_num($pastDayCount)]) }}</p>
  @endif
  @if($noticeOpen)
    <div class="teamik-dialog-backdrop fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4" role="dialog" aria-modal="true" aria-labelledby="attendance-notice-title" data-modal wire:click.self="closeNotice" x-on:keydown.escape.window="$wire.closeNotice()"><div class="teamik-dialog-surface w-full max-w-lg rounded-2xl bg-surface border border-border p-5 shadow-xl">
      <h2 id="attendance-notice-title" class="font-bold">{{ __('وضعیت من برای امروز') }}</h2>
      @if($noticeReview)
        <p class="mt-4 whitespace-pre-wrap text-sm leading-7">{{ $noticeReview['text'] }}</p><p class="mt-3 text-xs font-bold">{{ __('مخاطبان: همکاران فعال همین شرکت') }} ({{ fa_num($recipients->count()) }})</p><details class="mt-2 text-xs"><summary>{{ __('نمایش اسامی') }}</summary><p class="mt-2 leading-7">{{ $recipients->pluck('name')->join('، ') }}</p></details><p class="mt-2 text-xs text-ink-muted">{{ __('اعلان طبق تنظیمات دریافت هر همکار ارسال می‌شود.') }}</p>
        <div class="mt-4 flex gap-2"><button wire:click="sendNotice" wire:loading.attr="disabled" class="teamik-button-primary">{{ __('ارسال اعلان') }}</button><button wire:click="editNotice" class="teamik-button-secondary">{{ __('ویرایش') }}</button><button wire:click="closeNotice" class="teamik-button-secondary">{{ __('انصراف') }}</button></div>
      @else
        <label class="mt-4 block text-sm">{{ __('چه وضعیتی اعلام شود؟') }}<select wire:model.live="noticeMode" class="teamik-field mt-2"><option value="none">{{ __('بدون اعلان') }}</option><option value="available">{{ __('مرخصی‌ام و در دسترسم') }}</option><option value="offline">{{ __('مرخصی‌ام و در دسترس نیستم') }}</option><option value="remote">{{ __('دورکارم و در دسترسم') }}</option><option value="custom">{{ __('متن دلخواه') }}</option></select></label>
        @if($noticeMode === 'custom')<label class="mt-3 block text-sm">{{ __('متن پیام') }}<textarea wire:model="noticeText" maxlength="500" rows="3" class="teamik-field mt-2"></textarea></label>@elseif(isset(\App\Services\Hr\AttendanceNotice::MESSAGES[$noticeMode]))<p class="mt-4 text-sm leading-7">{{ \App\Services\Hr\AttendanceNotice::MESSAGES[$noticeMode] }}</p>@endif
        @error('noticeText')<p role="alert" class="text-sm text-danger">{{ $message }}</p>@enderror
        <div class="mt-4 flex gap-2"><button wire:click="previewNotice" class="teamik-button-primary">{{ $noticeMode === 'none' ? __('بستن') : __('مرور اعلان') }}</button><button wire:click="closeNotice" class="teamik-button-secondary">{{ __('انصراف') }}</button></div>
      @endif
    </div></div>
  @endif
</section>
