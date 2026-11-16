@use('App\Modules\WorkManagement\Application\ReportDefinitions')
<div class="grid items-start gap-4 lg:grid-cols-3">
@foreach (['Periods' => $periods, 'Templates' => $templates, 'Additional fields' => $fields] as $heading => $items)
    <section class="ppm-surface overflow-hidden"><h2 class="px-5 py-4 text-sm font-semibold">{{ $heading }}</h2><ul class="divide-y divide-border">
        @forelse ($items as $item)<li class="flex min-w-0 items-center justify-between gap-3 px-5 py-3" wire:key="report-setting-{{ $item->id }}"><div class="min-w-0 flex-1"><span class="block truncate text-sm font-medium" title="{{ $item->name }}">{{ $item->name }}</span><span class="mt-1 block text-xs text-muted-foreground">@if ($heading === 'Periods'){{ $item->starts_on }} – {{ $item->ends_on }}@elseif ($heading === 'Templates'){{ ReportDefinitions::KINDS[$item->kind] }} · v{{ $item->revision }}@else{{ ReportDefinitions::ENTITIES[$item->entity_type] }} · {{ ReportDefinitions::TYPES[$item->type] }}{{ $item->required ? ' · Required' : '' }}@endif{{ $item->archived ? ' · Archived' : '' }}</span></div><flux:button size="sm" variant="ghost" icon="pencil-square" wire:click="{{ $heading === 'Periods' ? 'openPeriod' : ($heading === 'Templates' ? 'openTemplate' : 'openField') }}('{{ $item->id }}')" aria-label="Edit {{ $item->name }}" /></li>
        @empty<li class="px-5 py-6 text-sm text-muted-foreground">Nothing added yet.</li>
        @endforelse
    </ul></section>
@endforeach
</div>
