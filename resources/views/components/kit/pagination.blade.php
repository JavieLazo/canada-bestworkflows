{{--
    Port of src/components/TablePagination.tsx — the footer for a paginated
    table: "Showing x–y of z rows", a page-size select, "page / pageCount" and
    real prev/next buttons that stay keyboard-reachable and disableable.

    Must sit inside a Livewire component that `use`s
    App\Livewire\Concerns\WithTableFilters: the page-size select binds to its
    `perPage` property and the arrows call Livewire's own
    `previousPage()` / `nextPage()`.

    Usage:
        $rows = $this->paginateClamped($query);          // in render()
        <x-kit.pagination :paginator="$rows" noun="punches" />

    A second list in the same component supplies its own page-size-model so
    changing the dialog's size does not change the page behind it.
--}}
@props([
    'paginator',
    /** Plural noun for the count line, e.g. "punches". */
    'noun' => 'rows',
    'pageSizeModel' => 'perPage',
    'pageSizeLabel' => 'Rows per page',
])
<div {{ $attributes->class('flex flex-wrap items-center justify-between gap-3 border-t border-border px-4 py-3') }}>
    <p class="text-xs text-muted-foreground">
        @if ($paginator->total() === 0)
            No {{ $noun }}
        @else
            Showing <span class="num">{{ $paginator->firstItem() }}</span>&ndash;<span class="num">{{ $paginator->lastItem() }}</span> of
            <span class="num">{{ $paginator->total() }}</span> {{ $noun }}
        @endif
    </p>

    <div class="flex items-center gap-2">
        <x-kit.select
            size="sm"
            class="w-[4.5rem]"
            wire:model.live="{{ $pageSizeModel }}"
            :aria-label="$pageSizeLabel"
        >
            @foreach (\App\Livewire\Concerns\TableDefaults::PAGE_SIZE_OPTIONS as $size)
                <flux:select.option value="{{ $size }}">{{ $size }}</flux:select.option>
            @endforeach
        </x-kit.select>

        <span class="num whitespace-nowrap text-xs text-muted-foreground">
            {{ $paginator->currentPage() }} / {{ $paginator->lastPage() }}
        </span>

        <flux:button
            variant="outline"
            size="sm"
            square
            icon="chevron-left"
            wire:click="previousPage('{{ $paginator->getPageName() }}')"
            :disabled="$paginator->onFirstPage()"
            aria-label="Previous page"
        />
        <flux:button
            variant="outline"
            size="sm"
            square
            icon="chevron-right"
            wire:click="nextPage('{{ $paginator->getPageName() }}')"
            :disabled="! $paginator->hasMorePages()"
            aria-label="Next page"
        />
    </div>
</div>
