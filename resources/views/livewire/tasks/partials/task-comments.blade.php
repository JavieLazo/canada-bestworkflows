{{--
    The task's conversation, oldest first, with a composer underneath — port of
    components/TaskComments.tsx as a Blade partial of the My Tasks dialog.

    This is what makes a second round of rework legible: `remarks` and
    `review_remarks` each hold only the latest text, so without an append-only
    thread the first exchange is gone the moment anyone writes over it.

    Expects: $comments (already narrowed to this task, oldest first),
    $side ('MANAGEMENT'|'ASSIGNEE' — the actor's own side), $canComment.
--}}
@php
    use App\Domain\Projects\TaskPolicy;
    use App\Support\ManilaClock;
@endphp
<div class="space-y-2">
    <p class="flex items-center gap-1.5 text-xs font-medium uppercase tracking-wide text-muted-foreground">
        <flux:icon.chat-bubble-left class="h-3 w-3" />
        Discussion
        @if ($comments->isNotEmpty())
            <span class="num">({{ $comments->count() }})</span>
        @endif
    </p>

    @if ($comments->isEmpty())
        <p class="rounded-md border border-dashed border-border px-3 py-2 text-xs text-muted-foreground">
            No comments yet.
        </p>
    @else
        <ul class="space-y-2">
            @foreach ($comments as $c)
                {{-- The assignee's own side is tinted so a thread can be skimmed
                     for who said what without reading the names. --}}
                <li
                    wire:key="comment-{{ $c->id }}"
                    class="rounded-md border px-3 py-2 {{ $c->side === 'MANAGEMENT' ? 'border-border bg-muted/30' : 'border-border bg-card' }}"
                >
                    <div class="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-1">
                        <span class="text-sm font-medium">{{ $c->author_name }}</span>
                        <span class="num text-xs text-muted-foreground">
                            {{ $c->created_at?->setTimezone(ManilaClock::TZ)->format('M d, Y, g:i A') }}
                        </span>
                    </div>
                    @if ($c->event)
                        <p class="mt-0.5 text-xs text-muted-foreground">
                            Posted with {{ mb_strtolower(TaskPolicy::statusLabel($c->event)) }}
                        </p>
                    @endif
                    <p class="mt-1 whitespace-pre-wrap break-words text-sm">{{ $c->body }}</p>
                </li>
            @endforeach
        </ul>
    @endif

    @if ($canComment)
        <div class="space-y-2">
            <flux:textarea
                aria-label="Add a comment"
                rows="2"
                placeholder="{{ $side === 'MANAGEMENT'
                    ? 'Explain what needs changing, or answer a question…'
                    : 'Ask a question, or note what you have tried…' }}"
                wire:model="commentBody"
            />
            <div class="flex justify-end">
                <flux:button type="button" size="sm" icon="paper-airplane" wire:click="postComment">
                    Post comment
                </flux:button>
            </div>
        </div>
    @endif
</div>
