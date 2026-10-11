{{--
    One comment and its replies, drawn the way Reddit draws a thread: a line
    drops straight from the comment's avatar, and each reply's branch curves
    into the middle of that reply's avatar. Every part of the line is a
    button (the drop, the stretches between replies, each curve, and the ⊖
    on it), and a click on any of them folds the replies away. "N replies"
    brings them back. Replies start folded, so a busy post stays short, and
    the line and the replies fade in and out together.

    The line is drawn with solid shapes in one opaque colour rather than
    borders, because the border colour is see-through in dark mode, and two
    pieces crossing would show as a lighter patch.

    Threads go three levels deep (SocialRules::MAX_COMMENT_DEPTH). Reply works
    at every level: at the deepest one, the reply lands beside the comment
    instead of under it. Either way the box starts with an @mention of whoever
    wrote the comment, so it's clear who it's for, and it can be deleted.

    The ⋯ at the end of the actions deletes the comment, for its author and
    for company-wide moderators. Its replies go with it, like a post's
    comments go with the post, and the question says so first.

    A comment a notification points at ($focusComment) gets a soft brand
    background and is scrolled to, and the replies above it unfold on the way.
    The background sits behind the words and photos only, so it never covers
    the thread line.

    The numbers line up with the avatar column: avatars are 32px with a 10px
    gap, so replies sit 42px in, the line runs down the middle at 16px, and a
    branch meets a reply's avatar 16px down, at its centre.

    Expects: $comment (CommentView), $postId, $groupId, $canInteract, $names,
    $people, $groupNames, $viewer, $now, $focusComment; reads $staged,
    $maxAttachments, $maxBytes, $maxComment. This file includes itself for
    each reply.
--}}
@use('App\Modules\Social\Application\Attachments')
@use('App\Support\SocialFormat')
@php
    $hasReplies = $comment->replies !== [];
    $replyCount = $comment->replyTotal();
    $reactionTotal = $comment->reactionTotal();
    $slot = 'r-'.$comment->id;
    $held = count($staged[$slot] ?? []);
    $accept = '.'.implode(',.', Attachments::FILE_EXTENSIONS);
    $photoUrls = array_map(static fn (string $id): string => route('community.photos', ['photo' => $id]), $comment->photos);
    $linkClasses = 'cursor-pointer font-semibold hover:underline';
    // Solid in both themes: the card colour with the border colour laid over it.
    $line = "hover ? 'bg-muted-foreground' : 'bg-card bg-[linear-gradient(var(--color-border),var(--color-border))]'";
    $lineEvents = 'x-on:click="open = false" x-on:mouseenter="hover = true" x-on:mouseleave="hover = false"';
    $boxButton = 'inline-flex size-6 shrink-0 cursor-pointer items-center justify-center rounded-full text-muted-foreground transition-colors hover:bg-accent hover:text-accent-foreground disabled:cursor-default disabled:opacity-40';
    $focusComment ??= null;
    $found = $focusComment !== null && $comment->id === $focusComment;
@endphp

<div
    id="comment-{{ $comment->id }}"
    class="relative scroll-mt-24"
    x-data="{ open: @js($focusComment !== null && $comment->holds($focusComment)), replying: false, hover: false }"
    x-on:community-replied.window="if ($event.detail.id === @js($comment->id)) { replying = false; open = true }"
>
    <div class="flex gap-2.5">
        <div class="flex w-8 shrink-0 flex-col items-center">
            <x-social.avatar :name="$comment->author->name" :photo="$comment->author->photo" size="sm" />
            @if ($hasReplies)
                <button
                    type="button"
                    x-show="open"
                    x-cloak
                    x-transition.opacity.duration.150ms
                    {!! $lineEvents !!}
                    class="relative w-4 flex-1 cursor-pointer"
                    aria-label="Hide replies to {{ $comment->author->name }}"
                >
                    <span class="absolute inset-y-0 left-[7px] w-0.5 transition-colors" x-bind:class="{{ $line }}"></span>
                </button>
                {{-- The ⊖ on the line, level with the actions, the way Reddit marks a thread you can fold. --}}
                <button
                    type="button"
                    x-show="open"
                    x-cloak
                    x-transition.opacity.duration.150ms
                    {!! $lineEvents !!}
                    class="flex size-4 shrink-0 cursor-pointer items-center justify-center rounded-full border-2 bg-card transition-colors"
                    x-bind:class="hover ? 'border-muted-foreground text-foreground' : 'border-[color-mix(in_oklab,var(--color-card),var(--color-muted-foreground)_18%)] text-muted-foreground'"
                    aria-hidden="true"
                    tabindex="-1"
                >
                    <flux:icon.minus class="size-2.5" />
                </button>
            @endif
        </div>

        <div
            @class([
                'min-w-0 flex-1',
                'relative isolate before:absolute before:-inset-x-2 before:-inset-y-1.5 before:-z-10 before:rounded-2xl before:bg-primary-soft' => $found,
            ])
            @if ($found) x-init="requestAnimationFrame(() => $el.scrollIntoView({ block: 'center', behavior: 'smooth' }))" @endif
        >
            @if (trim($comment->body) !== '')
                <div class="inline-block max-w-full rounded-2xl bg-muted px-3.5 py-2">
                    <p class="text-xs font-semibold">{{ $comment->author->name }}</p>
                    <p class="text-sm"><x-social.text :body="$comment->body" :names="$names" :groups="$groupNames" open-group /></p>
                </div>
            @else
                <p class="px-1 text-xs font-semibold">{{ $comment->author->name }}</p>
            @endif

            <x-social.photo-grid :urls="$photoUrls" :key="'comment-'.$comment->id.'-img'" compact class="mt-1.5" />

            <x-social.files :files="$comment->files" route="community.files" compact class="mt-1.5" />

            <div class="mt-1 flex flex-wrap items-center gap-x-1.5 gap-y-1 px-2 text-xs text-muted-foreground">
                <time datetime="{{ $comment->createdAt->toIso8601String() }}" title="{{ SocialFormat::stamp($comment->createdAt) }}">{{ SocialFormat::ago($comment->createdAt, $now) }}</time>
                @if ($canInteract)
                    <span aria-hidden="true">&middot;</span>
                    <x-social.react-button method="reactToComment" :target="$comment->id" :mine="$comment->reaction" variant="link" />
                    <span aria-hidden="true">&middot;</span>
                    <button
                        type="button"
                        x-on:click="
                            replying = true;
                            $nextTick(() => {
                                const box = $root.querySelector('[data-reply-box]');
                                if (! box) return;
                                if (box.value.trim() === '') {
                                    box.value = '@' + @js($comment->author->name) + ' ';
                                    box.dispatchEvent(new Event('input', { bubbles: true }));
                                }
                                box.focus();
                                box.setSelectionRange(box.value.length, box.value.length);
                            })
                        "
                        class="{{ $linkClasses }}"
                    >Reply</button>
                @endif
                @if ($reactionTotal > 0)
                    <span aria-hidden="true">&middot;</span>
                    <x-social.who
                        :reactions="$comment->reactions"
                        :people="$people"
                        :me="$viewer->id"
                        class="inline-flex items-center gap-1"
                        aria-label="{{ $reactionTotal }} {{ $reactionTotal === 1 ? 'reaction' : 'reactions' }}. Show who reacted"
                    >
                        <span class="flex -space-x-0.5" aria-hidden="true">
                            @foreach (SocialFormat::topReactions($comment->reactionCounts()) as $type)
                                <span>{{ SocialFormat::REACTIONS[$type]['emoji'] ?? '' }}</span>
                            @endforeach
                        </span>
                        <span class="num group-hover/who:underline">{{ $reactionTotal }}</span>
                    </x-social.who>
                @endif
                @if ($hasReplies)
                    <span x-show="! open" aria-hidden="true">&middot;</span>
                    <button type="button" x-show="! open" x-on:click="open = true" class="{{ $linkClasses }} inline-flex items-center gap-1">
                        <flux:icon.chevron-down class="size-3" />
                        {{ $replyCount }} {{ $replyCount === 1 ? 'reply' : 'replies' }}
                    </button>
                @endif
                @if ($comment->canDelete)
                    @php
                        $askDelete = $comment->mine
                            ? ['title' => 'Delete this comment?', 'body' => $hasReplies ? 'Its replies go with it.' : '', 'confirm' => 'Delete comment']
                            : ['title' => 'Remove this comment for everyone?', 'body' => ($hasReplies ? 'Its replies go with it. ' : '').'This is recorded in the audit trail.', 'confirm' => 'Remove comment'];
                        $askDelete += ['danger' => true, 'call' => ['deleteComment', $comment->id]];
                    @endphp
                    <flux:dropdown position="bottom" align="start">
                        <button
                            type="button"
                            class="inline-flex size-5 cursor-pointer items-center justify-center rounded-full transition-colors hover:bg-accent hover:text-accent-foreground"
                            aria-label="More for {{ $comment->author->name }}’s comment"
                            title="More"
                        >
                            <flux:icon.ellipsis-horizontal class="size-4" />
                        </button>
                        <flux:menu>
                            <flux:menu.item icon="trash" variant="danger" x-on:click="$dispatch('social-confirm', @js($askDelete))">
                                Delete comment
                            </flux:menu.item>
                        </flux:menu>
                    </flux:dropdown>
                @endif
            </div>

            @if ($canInteract)
                {{-- Kept in the page while hidden, so a half-written reply survives the fold. --}}
                <form
                    x-show="replying"
                    x-cloak
                    wire:submit="reply('{{ $postId }}', '{{ $comment->id }}')"
                    class="mt-2 space-y-1.5"
                >
                    <x-social.staged :draft="$slot" :files="$staged[$slot] ?? []" compact class="ml-8" />
                    <div class="flex items-center gap-2">
                        <x-social.avatar :name="$viewer->name" :photo="$viewer->photo" size="xs" />
                        <div
                            class="relative flex min-w-0 flex-1 items-center gap-0.5 rounded-full bg-muted pl-3.5 pr-1 focus-within:ring-2 focus-within:ring-ring"
                            x-data="mentionPicker({ audience: @js($groupId ?? '') })"
                        >
                            <input
                                data-reply-box
                                x-on:keydown.escape="replying = false"
                                type="text"
                                wire:model="drafts.{{ $comment->id }}"
                                maxlength="{{ $maxComment }}"
                                data-paste-limit="A reply"
                                placeholder="Reply to {{ $comment->author->name }}…"
                                aria-label="Reply to {{ $comment->author->name }}"
                                class="h-8 min-w-0 flex-1 bg-transparent text-sm outline-hidden placeholder:text-muted-foreground"
                            />
                            <x-social.mention-list />
                            <span class="contents" x-data="attachmentPicker({ maxBytes: @js($maxBytes), slot: @js($slot), max: @js($maxAttachments) })">
                                <input x-ref="photos" type="file" accept="image/*" multiple class="sr-only" tabindex="-1" aria-hidden="true" x-on:change="pick($event)" />
                                <input x-ref="files" type="file" accept="{{ $accept }}" multiple class="sr-only" tabindex="-1" aria-hidden="true" x-on:change="pick($event)" />
                                <button type="button" x-on:click="$refs.photos.click()" x-bind:disabled="busy" class="{{ $boxButton }}" aria-label="Add photos" title="Add photos" @disabled($held >= $maxAttachments)>
                                    <flux:icon.photo class="size-3.5" />
                                </button>
                                <button type="button" x-on:click="$refs.files.click()" x-bind:disabled="busy" class="{{ $boxButton }}" aria-label="Add files" title="Add files" @disabled($held >= $maxAttachments)>
                                    <flux:icon.paper-clip class="size-3.5" />
                                </button>
                            </span>
                            <button type="submit" class="inline-flex size-6 shrink-0 cursor-pointer items-center justify-center rounded-full text-primary transition-colors hover:bg-primary-soft" aria-label="Post reply">
                                <flux:icon.paper-airplane class="size-3.5" />
                            </button>
                        </div>
                    </div>
                </form>
            @endif
        </div>
    </div>

    @if ($hasReplies)
        <div x-show="open" x-cloak x-transition.opacity.duration.150ms class="ml-[42px] space-y-3 pt-3">
            @foreach ($comment->replies as $reply)
                <div wire:key="comment-{{ $reply->id }}" class="relative">
                    @unless ($loop->last)
                        {{-- The line carries on past this reply to the next one. --}}
                        <button
                            type="button"
                            {!! $lineEvents !!}
                            class="absolute -left-[34px] -top-3 bottom-0 w-4 cursor-pointer"
                            aria-label="Hide replies to {{ $comment->author->name }}"
                        >
                            <span class="absolute inset-y-0 left-[7px] w-0.5 transition-colors" x-bind:class="{{ $line }}"></span>
                        </button>
                    @endunless
                    {{-- The branch: down from the line, then a curve into the middle of this reply's avatar. --}}
                    <button
                        type="button"
                        {!! $lineEvents !!}
                        class="absolute -left-[27px] -top-3 h-[29px] w-[27px] cursor-pointer"
                        aria-hidden="true"
                        tabindex="-1"
                    >
                        <span class="absolute inset-0 rounded-bl-[12px] transition-colors" x-bind:class="{{ $line }}"></span>
                        <span class="absolute bottom-0.5 left-0.5 right-0 top-0 rounded-bl-[10px] bg-card"></span>
                    </button>
                    @include('livewire.community.partials.comment', ['comment' => $reply])
                </div>
            @endforeach
        </div>
    @endif
</div>
