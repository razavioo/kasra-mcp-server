<div class="flex flex-wrap items-center gap-2 text-xs" role="status" data-kasra-decision-state="{{ $state??'pending' }}">
  @if($state==='queued')
    <div><p class="text-ink-muted">{{ __('تصمیم شما در نوبت ارسال به کسراست؛ نتیجه خودکار به‌روز می‌شود.') }}</p></div>
    <span class="text-ink-muted">{{ __('در نوبت ارسال') }}</span>
  @elseif(in_array($state, ['sending','awaiting_confirmation','processed','needs_review'], true))
    <div>
      @if(isset($kasraChecks[$item->id]))
        <p role="status" class="{{ $kasraChecks[$item->id]['error'] ? 'text-danger' : 'text-ink-muted' }}" style="margin:0;@if($kasraChecks[$item->id]['error'])color:var(--ui-danger);@endif">{{ $kasraChecks[$item->id]['text'] }} <bdi class="whitespace-nowrap">{{ $kasraChecks[$item->id]['at'] }}</bdi></p>
      @else
        <p class="text-ink-muted" style="margin:0">{{ $state==='needs_review' ? __('نتیجهٔ اقدام قبلی در کسرا مشخص نیست؛ وضعیت را بررسی کنید.') : __('تصمیم ارسال شده؛ در انتظار تطبیق با کسرا.') }}</p>
      @endif
    </div>
    <button type="button" @disabled($kasraChecks[$item->id]['waiting']??false) wire:click="refreshKasraStatus({{ $item->id }})" wire:loading.attr="disabled" class="teamik-button-secondary"><span wire:loading.remove wire:target="refreshKasraStatus({{ $item->id }})">{{ ($kasraChecks[$item->id]['waiting']??false) ? __('در نوبت بررسی…') : __('بررسی وضعیت کسرا') }}</span><span wire:loading wire:target="refreshKasraStatus({{ $item->id }})">{{ __('در حال بررسی مستقیم…') }}</span></button>
  @else
    @if($state === 'not_sent')<span class="text-danger">{{ __('تصمیم ارسال نشد؛ می‌توانید دوباره بررسی و اقدام کنید.') }}</span>@else<span class="text-ink-muted">{{ __('در انتظار تصمیم شما') }}</span>@endif
    <div data-kasra-action-buttons>
    <button type="button" wire:click="reviewKasra({{ $item->id }}, 'approved')" wire:loading.attr="disabled" class="teamik-button-primary">{{ __('تأیید') }}</button>
    <button type="button" wire:click="reviewKasra({{ $item->id }}, 'rejected')" wire:loading.attr="disabled" class="teamik-button-secondary">{{ __('رد') }}</button>
    </div>
  @endif
</div>
