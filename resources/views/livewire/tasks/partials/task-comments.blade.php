{{--
    The task's conversation, oldest first, with a composer underneath — port of
    components/TaskComments.tsx as a Blade partial of the My Tasks dialog.

    This is what makes a second round of rework legible: `remarks` and
    `review_remarks` each hold only the latest text, so without an append-only
    thread the first exchange is gone the moment anyone writes over it.

    Expects: $comments (already narrowed to this task, oldest first),
    $side ('MANAGEMENT'|'ASSIGNEE' — the actor's own side), $canComment.
--}}
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
                <li wire:key="comment-{{ $c->id }}"><x-work.comment :comment="$c" /></li>
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
                    Post Comment
                </flux:button>
            </div>
        </div>
    @endif
</div>
