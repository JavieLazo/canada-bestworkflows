{{--
    One note on a payroll screen: an alert icon beside its text, so a reader
    can tell at a glance how much it matters.

    `tone`: danger (something refused), warning (check before finalizing),
    info (how the figures were reached), note (how the page behaves). The
    caller's class gives the box (a band's `border-b px-5 py-3`, a standalone
    `rounded-lg border px-4 py-3`); data-* hooks pass through to it.
--}}
@props(['tone' => 'warning'])

@php
    $look = match ($tone) {
        'danger' => 'border-danger/20 bg-danger-soft text-danger',
        'info' => 'border-primary/20 bg-primary-soft text-foreground',
        'note' => 'border-border bg-muted text-muted-foreground',
        default => 'border-warning/20 bg-warning-soft text-foreground',
    };
    $role = match ($tone) {
        'danger' => ['role' => 'alert'],
        'note' => [],
        default => ['role' => 'status'],
    };
@endphp

<div {{ $attributes->merge($role)->class(['flex gap-2 text-sm', $look]) }}>
    @if ($tone === 'danger')
        <flux:icon.exclamation-circle variant="mini" class="mt-0.5 size-4 shrink-0 text-danger" />
    @elseif ($tone === 'info')
        <flux:icon.information-circle variant="mini" class="mt-0.5 size-4 shrink-0 text-primary" />
    @elseif ($tone === 'note')
        <flux:icon.information-circle variant="mini" class="mt-0.5 size-4 shrink-0" />
    @else
        <flux:icon.exclamation-triangle variant="mini" class="mt-0.5 size-4 shrink-0 text-warning" />
    @endif
    <div class="min-w-0 flex-1 space-y-1">{{ $slot }}</div>
</div>
