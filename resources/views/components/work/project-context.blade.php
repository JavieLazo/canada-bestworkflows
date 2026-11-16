@props(['client' => null, 'project' => null])
<span {{ $attributes->class('inline-flex min-w-0 items-center gap-1.5 text-xs text-muted-foreground') }} title="{{ $client?->name ?? $project?->client_name ?? 'Internal' }}">
    <flux:icon.building-office-2 class="size-3.5 shrink-0" />
    <span class="truncate">{{ $client?->name ?? $project?->client_name ?? 'Internal' }}</span>
</span>
