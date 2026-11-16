@php
    $scopeClient = (string) request()->query('client', 'ALL');
    $scopePortfolio = (string) request()->query('portfolio', 'ALL');
    $projectsSection = match (true) {
        request()->routeIs('projects.portfolios') => 'portfolios',
        request()->routeIs('projects.clients') => 'clients',
        request()->routeIs('projects.show') => 'projects',
        $scopeClient !== 'ALL' => 'clients',
        $scopePortfolio !== 'ALL' => 'portfolios',
        default => (string) request()->query('section', 'overview'),
    };
@endphp
<div x-data="{ open: @js($isCurrent($module['path'])) }">
    <button type="button" x-on:click="open = !open" class="{{ $itemClasses }}{{ $isCurrent($module['path']) ? $itemActive : '' }} ppm-nav-control cursor-pointer" title="Project Management" aria-label="Project Management navigation" :aria-expanded="open">
        @include('layouts.app.module-icon', ['icon' => $module['icon'], 'class' => 'size-4 shrink-0'])
        <span class="min-w-0 flex-1 truncate {{ $hideCollapsed }}">{{ $module['label'] }}</span><flux:icon.chevron-right class="size-3 shrink-0 {{ $hideCollapsed }}" x-bind:class="open && 'rotate-90'" />
    </button>
    <ul x-show="open" x-cloak class="mx-3.5 flex min-w-0 flex-col gap-1 border-s border-sidebar-border px-2.5 py-1 {{ $hideCollapsed }}">
        @foreach (['overview' => 'Overview', 'portfolios' => 'Portfolios', 'clients' => 'Clients', 'projects' => 'Projects', 'capacity' => 'Availability'] as $key => $label)
            <li><a href="{{ in_array($key, ['portfolios', 'clients'], true) ? route('projects.'.$key) : route('projects.index', ['section' => $key]) }}" class="{{ $subItemClasses }}{{ $isCurrent($module['path']) && $projectsSection === $key ? $itemActive : '' }} ppm-nav-control cursor-pointer" @if ($isCurrent($module['path']) && $projectsSection === $key) aria-current="page" @endif wire:navigate>{{ $label }}</a></li>
        @endforeach
    </ul>
</div>
