@props(['rows', 'today'])
<div {{ $attributes->class('overflow-hidden rounded-lg border border-border') }}>
    @if ($rows === null || $rows->isEmpty())
        <p class="px-4 py-5 text-sm text-muted-foreground">No subtasks to show.</p>
    @else
        <ul class="divide-y divide-border">
            @foreach ($rows as $child)
                <li wire:key="subtask-row-{{ $child->id }}">
                    <button type="button" wire:click="openTask('{{ $child->id }}')" aria-label="Open subtask {{ $child->code }}: {{ $child->title }}" class="flex w-full items-start gap-3 px-4 py-3 text-left hover:bg-accent focus-visible:outline-2 focus-visible:outline-primary">
                        <flux:icon.arrow-turn-down-right class="mt-1 size-3.5 shrink-0 text-muted-foreground" />
                        <span class="min-w-0 flex-1 space-y-2">
                            <span class="block truncate text-sm font-medium" title="{{ $child->title }}">{{ $child->title }}</span>
                            <span class="flex flex-wrap items-center gap-3"><span class="num text-xs text-muted-foreground">{{ $child->code }}</span><x-work.assignees :task="$child" class="max-w-48" />
                                @if ($child->due_date)<span class="num text-xs text-muted-foreground"><x-work.date :date="$child->due_date" :today="$today" /></span>
                                @endif
                            </span>
                        </span>
                        <span class="{{ $child->myStatusPill() }} shrink-0">{{ $child->myStatusLabel() }}</span>
                    </button>
                </li>
            @endforeach
        </ul>
        @if ($rows->hasPages())
            <nav aria-label="Subtask pages" class="flex items-center justify-between gap-2 border-t border-border px-3 py-2">
                <flux:button size="sm" variant="ghost" square icon="chevron-left" wire:click="previousPage('subtasks')" :disabled="$rows->onFirstPage()" aria-label="Previous subtasks" />
                <span class="num text-xs text-muted-foreground">{{ $rows->firstItem() }}–{{ $rows->lastItem() }} of {{ $rows->total() }}</span>
                <flux:button size="sm" variant="ghost" square icon="chevron-right" wire:click="nextPage('subtasks')" :disabled="! $rows->hasMorePages()" aria-label="Next subtasks" />
            </nav>
        @endif
    @endif
</div>
