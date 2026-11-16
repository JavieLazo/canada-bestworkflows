@props(['paginator', 'noun'])
@if ($paginator->lastPage() > 1)
    <div {{ $attributes->class('flex items-center justify-between gap-3 px-5 py-3') }}>
        <span class="num text-xs text-muted-foreground">{{ $paginator->firstItem() }}–{{ $paginator->lastItem() }} of {{ $paginator->total() }}</span>
        <div class="flex items-center gap-2">
            <flux:button size="sm" variant="ghost" square icon="chevron-left" wire:click="previousPage('{{ $paginator->getPageName() }}')" :disabled="$paginator->onFirstPage()" aria-label="Previous {{ $noun }} page" />
            <flux:button size="sm" variant="ghost" square icon="chevron-right" wire:click="nextPage('{{ $paginator->getPageName() }}')" :disabled="! $paginator->hasMorePages()" aria-label="Next {{ $noun }} page" />
        </div>
    </div>
@endif
