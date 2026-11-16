<div class="ppm-actions">
    <flux:dropdown position="bottom" align="end"><flux:button size="sm" variant="ghost" icon="arrows-up-down" aria-label="Sort {{ $kind }}">Sort</flux:button><flux:menu>
        @foreach (['name' => 'Name', 'created' => 'Created', 'members' => 'People', ...($kind === 'portfolios' ? ['clients' => 'Clients'] : []), 'projects' => 'Projects'] as $key => $label)
            <flux:menu.item wire:click="sortBy('{{ $key }}')" :icon="$sortKey === $key ? ($sortDir === 'asc' ? 'arrow-up' : 'arrow-down') : null">{{ $label }}</flux:menu.item>
        @endforeach
    </flux:menu></flux:dropdown>
    <x-work.filters :id="$kind.'-filters'" :label="ucfirst($kind).' filters'" :count="($ownership !== 'ALL' ? 1 : 0) + ($contents !== 'ALL' ? 1 : 0) + ($kind === 'portfolios' && $archived ? 1 : 0)">
        <x-kit.select wire:model.live="ownership" label="Ownership" aria-label="Filter {{ $kind }} by ownership">
            <flux:select.option value="ALL">Owned and shared</flux:select.option><flux:select.option value="OWNED">Owned by me</flux:select.option><flux:select.option value="SHARED">Shared with me</flux:select.option>
        </x-kit.select>
        <x-kit.select wire:model.live="contents" label="Contents" aria-label="Filter {{ $kind }} by contents">
            <flux:select.option value="ALL">Any</flux:select.option><flux:select.option value="WITH_WORK">With {{ $kind === 'portfolios' ? 'clients' : 'projects' }}</flux:select.option><flux:select.option value="EMPTY">Without {{ $kind === 'portfolios' ? 'clients' : 'projects' }}</flux:select.option>
        </x-kit.select>
        @if ($kind === 'portfolios')<flux:checkbox wire:model.live="archived" label="Archived only" />
        @endif
    </x-work.filters>
    <div class="ppm-view-switch" role="group" aria-label="{{ ucfirst($kind) }} view">
        <flux:button size="sm" variant="{{ $collectionView === 'cards' ? 'filled' : 'ghost' }}" square icon="squares-2x2" wire:click="$set('collectionView', 'cards')" aria-label="{{ ucfirst($kind) }} cards" aria-pressed="{{ $collectionView === 'cards' ? 'true' : 'false' }}" />
        <flux:button size="sm" variant="{{ $collectionView === 'list' ? 'filled' : 'ghost' }}" square icon="list-bullet" wire:click="$set('collectionView', 'list')" aria-label="{{ ucfirst($kind) }} list" aria-pressed="{{ $collectionView === 'list' ? 'true' : 'false' }}" />
    </div>
</div>
