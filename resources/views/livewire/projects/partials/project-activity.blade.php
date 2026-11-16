@php
    use App\Livewire\Projects\ProjectsData;
@endphp
<div class="space-y-4">
    <div class="ppm-collection-toolbar" aria-label="Audit log controls">
        <flux:input size="sm" wire:model.live.debounce.250ms="activitySearch" icon="magnifying-glass" placeholder="Find an entry or author…" aria-label="Search audit log" class="ppm-search" />
        <div class="ppm-actions">
            <flux:dropdown position="bottom" align="end"><flux:button size="sm" variant="ghost" icon="arrows-up-down" aria-label="Sort audit log">Sort</flux:button><flux:menu>
                @foreach (['date-desc' => 'Newest first', 'date-asc' => 'Oldest first', 'author-asc' => 'Author A–Z', 'author-desc' => 'Author Z–A'] as $value => $label)<flux:menu.item wire:click="$set('activityOrder', '{{ $value }}')" :icon="$activityOrder === $value ? 'check' : null">{{ $label }}</flux:menu.item>
                @endforeach
            </flux:menu></flux:dropdown>
            <x-work.filters id="activity-filters" label="Audit log filters" :count="$activityEvent === 'ALL' ? 0 : 1">
                <x-kit.select wire:model.live="activityEvent" label="Type" aria-label="Audit entry type"><flux:select.option value="ALL">All entries</flux:select.option><flux:select.option value="COMMENTS">Comments</flux:select.option><flux:select.option value="CHANGES">Changes</flux:select.option></x-kit.select>
            </x-work.filters>
        </div>
    </div>
<section class="ppm-surface overflow-hidden" aria-label="Task audit log">
    <h2 class="ppm-section-title px-5 py-4">Task audit log</h2>
    @forelse ($recentActivity as $comment)
        @php
            $activityTask = $activityTasks->get($comment->task_id);
        @endphp
        <button type="button" class="flex w-full gap-3 px-5 py-4 text-left hover:bg-accent focus-visible:outline-2 focus-visible:outline-primary {{ $loop->first ? '' : 'border-t border-border/60' }}" wire:click="openTask('{{ $comment->task_id }}')" wire:key="activity-{{ $comment->id }}">
            <span class="mt-1.5 size-1.5 shrink-0 rounded-full bg-primary" aria-hidden="true"></span>
            <span class="min-w-0 flex-1 space-y-1">
                <span class="flex flex-wrap justify-between gap-x-3 gap-y-1 text-xs"><strong class="font-medium text-foreground">{{ $comment->author_name }}</strong><time datetime="{{ $comment->created_at->toIso8601String() }}" class="num text-muted-foreground">{{ ProjectsData::fmtDateTime($comment->created_at) }}</time></span>
                <span class="block truncate text-sm" title="{{ $activityTask?->title }}">{{ $activityTask?->title ?? 'Task' }} <span class="num text-xs text-muted-foreground">{{ $activityTask?->code }}</span></span>
                <span class="line-clamp-2 block break-words text-xs text-muted-foreground">{{ $comment->event === 'BRIEF_UPDATED' ? 'Updated task brief' : $comment->body }}</span>
            </span>
            <flux:icon.chevron-right class="mt-4 size-3.5 shrink-0 text-muted-foreground" />
        </button>
    @empty
        <p class="px-5 py-10 text-center text-sm text-muted-foreground">No task audit entries visible to you yet.</p>
    @endforelse
    <x-kit.pagination :paginator="$activity" noun="entries" />
</section>
</div>
