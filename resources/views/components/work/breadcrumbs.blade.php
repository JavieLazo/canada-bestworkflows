@props(['portfolio' => null, 'client' => null, 'project' => null, 'page' => null])
<nav class="ppm-breadcrumbs" aria-label="Project location">
    <a href="{{ route('projects.index') }}" wire:navigate>Project Management</a>
    @if ($portfolio !== null)<flux:icon.chevron-right class="size-3 shrink-0" /><a href="{{ route('projects.portfolios') }}" wire:navigate>Portfolios</a><flux:icon.chevron-right class="size-3 shrink-0" /><a href="{{ route('projects.index', ['portfolio' => $portfolio->id]) }}" class="truncate" title="{{ $portfolio->name }}" wire:navigate>{{ $portfolio->name }}</a>
    @elseif ($client !== null)<flux:icon.chevron-right class="size-3 shrink-0" /><a href="{{ route('projects.clients') }}" wire:navigate>Clients</a>
    @endif
    @if ($client !== null)<flux:icon.chevron-right class="size-3 shrink-0" /><a href="{{ route('projects.index', ['client' => $client->id, 'portfolio' => $portfolio?->id ?? 'ALL']) }}" class="truncate" title="{{ $client->name }}" wire:navigate>{{ $client->name }}</a>
    @endif
    @if ($project !== null)<flux:icon.chevron-right class="size-3 shrink-0" /><span class="num shrink-0" aria-current="page">{{ $project->code }}</span>
    @elseif ($page !== null && (($portfolio === null && $client === null) || $page !== 'Overview'))<flux:icon.chevron-right class="size-3 shrink-0" /><span aria-current="page">{{ $page }}</span>
    @endif
</nav>
