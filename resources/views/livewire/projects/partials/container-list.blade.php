<div class="ppm-surface overflow-hidden"><div class="hris-table-wrap hris-table-scroll"><table class="hris-table ppm-table w-full">
    <caption class="sr-only">{{ ucfirst($kind) }}</caption>
    <thead><tr><x-kit.sort-header field="name" label="Name" /><th>Owner</th>
        @if ($kind === 'portfolios')<x-kit.sort-header field="clients" label="Clients" />
        @endif
        <x-kit.sort-header field="projects" label="Projects" /><x-kit.sort-header field="members" label="People" /><x-kit.sort-header field="created" label="Created" /><th><span class="sr-only">Actions</span></th>
    </tr></thead>
    <tbody>@foreach ($rows as $row)
        <tr wire:key="{{ $kind }}-list-{{ $row->id }}"><td class="max-w-96"><a href="{{ route('projects.index', [$kind === 'portfolios' ? 'portfolio' : 'client' => $row->id]) }}" class="block truncate font-medium hover:text-primary" title="{{ $row->name }}" wire:navigate>{{ $row->name }}</a>
            @if ($row->description)<p class="mt-1 max-w-96 truncate text-xs text-muted-foreground" title="{{ $row->description }}">{{ $row->description }}</p>
            @endif
        </td><td class="max-w-48 truncate">{{ $owners->get($row->owner_id)?->name ?? 'Account #'.$row->owner_id }}</td>
        @if ($kind === 'portfolios')<td class="num">{{ $counts[$row->id]['clients'] }}</td>
        @endif
        <td class="num">{{ $kind === 'portfolios' ? $counts[$row->id]['projects'] : ($projectCounts[$row->id] ?? 0) }}</td>
        <td><button type="button" class="num inline-flex items-center gap-1.5 rounded text-xs hover:text-primary" wire:click="{{ $kind === 'portfolios' ? 'openPeople' : 'openClient' }}('{{ $row->id }}')" aria-label="View people for {{ $row->name }}"><flux:icon.users class="size-4" />{{ $row->people_count }}</button></td>
        <td class="num whitespace-nowrap text-xs text-muted-foreground">{{ $row->created_at->format('M j, Y') }}</td>
        <td class="text-right">@if ($row->can_manage)<flux:dropdown position="bottom" align="end"><flux:button size="sm" variant="ghost" square icon="ellipsis-horizontal" aria-label="Actions for {{ $row->name }}" /><flux:menu>
            <flux:menu.item icon="pencil-square" wire:click="{{ $kind === 'portfolios' ? 'openEdit' : 'openRename' }}('{{ $row->id }}')">Edit {{ $kind === 'portfolios' ? 'portfolio' : 'client' }}</flux:menu.item>
            @if ($kind === 'clients')<flux:menu.item icon="rectangle-stack" wire:click="openOrganize('{{ $row->id }}')">Move client</flux:menu.item>
            @else<flux:menu.item :icon="$row->archived_at === null ? 'archive-box' : 'arrow-uturn-left'" wire:click="archivePortfolio('{{ $row->id }}', {{ $row->archived_at === null ? 'true' : 'false' }})">{{ $row->archived_at === null ? 'Archive' : 'Restore' }}</flux:menu.item>
            @endif
        </flux:menu></flux:dropdown>
        @endif</td></tr>
    @endforeach</tbody>
</table></div></div>
