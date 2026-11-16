@props(['status', 'label' => null])
@php
    $icon = match ($status) { 'IN_PROGRESS' => 'play-circle', 'SUBMITTED' => 'clock', 'RETURNED' => 'arrow-uturn-left', 'COMPLETED', 'APPROVED' => 'check-circle', 'CANCELLED' => 'x-circle', default => 'minus-circle' };
    $color = match ($status) { 'IN_PROGRESS', 'SUBMITTED' => 'text-primary', 'RETURNED' => 'text-warning', 'COMPLETED', 'APPROVED' => 'text-success', default => 'text-muted-foreground' };
@endphp
<span {{ $attributes->class('inline-flex min-w-0 items-center gap-1.5 text-xs') }}><flux:icon :name="$icon" class="size-4 shrink-0 {{ $color }}" aria-hidden="true" /><span class="truncate">{{ $label ?? \App\Domain\Projects\TaskPolicy::statusLabel($status) }}</span></span>
