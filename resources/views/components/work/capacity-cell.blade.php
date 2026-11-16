@props(['person', 'week', 'cell', 'mine' => false])
@php
    use App\Modules\WorkManagement\Domain\Projects\PlanningHours;
    $planned = $cell['planned'];
    $available = $cell['available'];
    $over = $available !== null && $planned > $available;
    $width = $available === null ? 0 : ($available === 0 ? ($planned > 0 ? 100 : 0) : min(100, (int) round($planned * 100 / $available)));
@endphp
<div @class(['relative rounded-lg border p-3', 'border-danger/30 bg-danger/10' => $over, 'border-border bg-background' => ! $over])>
    <button type="button" wire:click="openWeek('{{ $person->employee_id }}', '{{ $week }}')" @class(['block w-full text-left focus-visible:outline-2 focus-visible:outline-primary', 'pr-5' => $mine]) aria-label="{{ $person->name }}, week of {{ $week }}: {{ PlanningHours::format($planned) }} planned hours, {{ $available === null ? 'availability not shared or set' : PlanningHours::format($available).' available hours' }}">
        <span class="num block text-sm font-semibold">{{ PlanningHours::format($planned) }}<span class="font-normal text-muted-foreground"> / {{ $available === null ? '—' : PlanningHours::format($available) }}h</span></span>
        <span class="mt-3 block h-1.5 overflow-hidden rounded-full bg-muted"><span @class(['block h-full rounded-full', 'bg-danger' => $over, 'bg-primary/60' => ! $over]) style="width: {{ $width }}%"></span></span>
    </button>
    @if ($mine)<span class="mt-2 block text-xs text-muted-foreground">{{ $cell['shared'] ? 'Shared' : 'Private' }}</span><div class="absolute right-1 top-1"><flux:button size="xs" square variant="ghost" icon="pencil-square" wire:click="openAvailability('{{ $week }}')" aria-label="Set my availability for {{ $week }}" /></div>
    @endif
</div>
