@props(['portfolio' => null, 'client' => null, 'active' => 'overview'])
@if ($portfolio !== null || $client !== null)
    <x-work.tabs aria-label="{{ $client !== null ? 'Client' : 'Portfolio' }} sections">
        @foreach (['overview' => 'Overview', 'clients' => 'Clients', 'projects' => 'Projects', 'capacity' => 'Availability'] as $key => $label)
            @if ($key !== 'clients' || $client === null)<x-work.tab :href="$key === 'clients' ? route('projects.clients', ['portfolio' => $portfolio?->id ?? 'ALL']) : route('projects.index', ['portfolio' => $portfolio?->id ?? 'ALL', 'client' => $client?->id ?? 'ALL', 'section' => $key])" :active="$key === $active" wire:navigate>{{ $label }}</x-work.tab>
            @endif
        @endforeach
    </x-work.tabs>
@endif
