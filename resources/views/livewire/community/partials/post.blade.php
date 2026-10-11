{{--
    One post: who, when and to whom; the body with its mentions; the photos
    and files; the reaction and comment tally; the action bar; and the
    thread.

    React: a click toggles Like, or takes back whatever the viewer already
    chose, and resting on the button offers all six. A click on the tally
    lists who reacted and with what. The thread shows its last two comments
    until asked for the rest; a post with none keeps it closed until
    "Comment".
    Inside a group the viewer has not joined, reacting and commenting give
    way to a note that joining is how to take part.

    The lines between the parts are all inset the same way, so the card reads
    as one piece.

    Opened from a notification about one of its comments, the thread opens
    all the way down to that comment, which is highlighted and scrolled to.

    Expects: $post (PostView), $names, $people, $groupNames, $tagsByCode,
    $viewer (PersonView), $now, $inGroup (true inside one group's feed, where
    naming the group is noise), and optionally $focusComment (a comment id);
    reads $staged, $maxAttachments, $maxBytes.
--}}
@use('App\Livewire\Community\Support\CommunityLabels')
@use('App\Modules\Social\Application\Attachments')
@use('App\Support\SocialFormat')
@php
    $tag = $post->tag !== null ? ($tagsByCode[$post->tag] ?? null) : null;
    $mine = $post->mine !== null ? (SocialFormat::REACTIONS[$post->mine] ?? null) : null;
    $reactionTotal = $post->reactionTotal();
    $earlier = max(0, count($post->comments) - 2);
    $focusComment ??= null;
    // The comment a notification points at may sit among the earlier ones, folded away.
    $revealEarlier = false;
    foreach (array_slice($post->comments, 0, $earlier) as $comment) {
        $revealEarlier = $revealEarlier || ($focusComment !== null && ($comment->id === $focusComment || $comment->holds($focusComment)));
    }
    $photoUrls = array_map(static fn (string $id): string => route('community.photos', ['photo' => $id]), $post->photos);
    $slot = 'c-'.$post->id;
    $held = count($staged[$slot] ?? []);
    $accept = '.'.implode(',.', Attachments::FILE_EXTENSIONS);
    $actionClasses = 'flex h-9 w-full cursor-pointer items-center justify-center gap-2 rounded-md text-sm font-medium transition-colors hover:bg-accent hover:text-accent-foreground';
    $boxButton = 'inline-flex size-7 shrink-0 cursor-pointer items-center justify-center rounded-full text-muted-foreground transition-colors hover:bg-accent hover:text-accent-foreground disabled:cursor-default disabled:opacity-40';
@endphp

<article
    id="{{ $post->id }}"
    wire:key="post-{{ $post->id }}"
    class="hris-card scroll-mt-20"
    x-data="{ thread: @js($post->comments !== []), all: @js($revealEarlier) }"
>
    <header class="flex items-start gap-3 px-5 pt-5">
        <x-social.avatar :name="$post->author->name" :photo="$post->author->photo" />
        <div class="min-w-0 flex-1">
            <p class="text-sm font-semibold leading-tight">
                {{ $post->author->name }}
                @if ($post->groupId !== null && ! $inGroup)
                    <flux:icon.chevron-right class="inline size-3 text-muted-foreground" />
                    <button
                        type="button"
                        wire:click="openGroup('{{ $post->groupId }}')"
                        class="cursor-pointer font-semibold hover:underline"
                    >{{ $post->groupName }}</button>
                @endif
            </p>
            <p class="mt-1 flex flex-wrap items-center gap-x-1.5 text-xs text-muted-foreground">
                {{-- A phone has room for when and to whom, not for the job title too. --}}
                @if ($post->author->position !== null)
                    <span class="hidden sm:inline">{{ $post->author->position }}</span>
                    <span class="hidden sm:inline" aria-hidden="true">&middot;</span>
                @endif
                <time datetime="{{ $post->createdAt->toIso8601String() }}" title="{{ SocialFormat::stamp($post->createdAt) }}">{{ SocialFormat::ago($post->createdAt, $now) }}</time>
                <span aria-hidden="true">&middot;</span>
                @if ($post->groupId === null)
                    <span title="Everyone at the company"><flux:icon.globe-asia-australia class="size-3.5" /><span class="sr-only">Everyone</span></span>
                @else
                    <span title="Members of {{ $post->groupName }}"><flux:icon.user-group class="size-3.5" /><span class="sr-only">Group members</span></span>
                @endif
            </p>
        </div>

        <div class="flex shrink-0 items-center gap-1">
            @if ($tag !== null)
                <span class="pill {{ CommunityLabels::pill($tag->tone) }} max-w-[9rem] truncate">{{ $tag->name }}</span>
            @endif
            @if ($post->canDelete || $post->canPin)
                <x-kit.row-actions :label="$post->author->name.'’s post'">
                    @if ($post->canPin)
                        <flux:menu.item icon="bookmark" wire:click="togglePin('{{ $post->id }}', {{ $post->pinned ? 'false' : 'true' }})">
                            {{ $post->pinned ? 'Unpin from the feed' : 'Pin to the top of the feed' }}
                        </flux:menu.item>
                    @endif
                    {{-- The author's alone: nobody else is offered a post's delete, and RemovePost checks again. --}}
                    @if ($post->canDelete)
                        @php
                            $askDelete = ['title' => 'Delete this post?', 'body' => 'Its comments, photos and files go with it.', 'confirm' => 'Delete post', 'danger' => true, 'call' => ['deletePost', $post->id]];
                        @endphp
                        <flux:menu.item icon="trash" variant="danger" x-on:click="$dispatch('social-confirm', @js($askDelete))">
                            Delete post
                        </flux:menu.item>
                    @endif
                </x-kit.row-actions>
            @endif
        </div>
    </header>

    @if (trim($post->body) !== '')
        <p class="px-5 pt-3 text-sm leading-relaxed">
            <x-social.text :body="$post->body" :names="$names" :groups="$groupNames" open-group />
        </p>
    @endif

    <x-social.photo-grid :urls="$photoUrls" :key="'post-'.$post->id.'-img'" class="mt-3" />

    <x-social.files :files="$post->files" route="community.files" class="px-5 pt-3" />

    @if ($reactionTotal > 0 || $post->commentCount > 0)
        <div class="flex items-center justify-between gap-3 px-5 pt-3 text-sm text-muted-foreground">
            @if ($reactionTotal > 0)
                <x-social.who
                    :reactions="$post->reactions"
                    :people="$people"
                    :me="$viewer->id"
                    class="flex items-center gap-1.5"
                    aria-label="{{ $reactionTotal }} {{ $reactionTotal === 1 ? 'reaction' : 'reactions' }}. Show who reacted"
                >
                    <span class="flex -space-x-1" aria-hidden="true">
                        @foreach (SocialFormat::topReactions($post->reactionCounts()) as $type)
                            <span class="flex size-6 items-center justify-center rounded-full bg-card text-sm ring-2 ring-card">{{ SocialFormat::REACTIONS[$type]['emoji'] ?? '' }}</span>
                        @endforeach
                    </span>
                    {{-- Only the words underline on hover, not the emoji. --}}
                    <span class="group-hover/who:underline">
                        @if ($mine !== null)
                            {{ $reactionTotal === 1 ? 'You' : 'You and '.($reactionTotal - 1).' '.($reactionTotal === 2 ? 'other' : 'others') }}
                        @else
                            <span class="num">{{ $reactionTotal }}</span>
                        @endif
                    </span>
                </x-social.who>
            @else
                <span></span>
            @endif

            @if ($post->commentCount > 0)
                <button type="button" x-on:click="thread = !thread" class="cursor-pointer hover:underline">
                    <span class="num">{{ $post->commentCount }}</span> {{ $post->commentCount === 1 ? 'comment' : 'comments' }}
                </button>
            @endif
        </div>
    @endif

    @if ($post->canInteract)
        <div class="mx-5 mt-3 grid grid-cols-2 gap-1 border-t border-border py-1">
            <x-social.react-button method="react" :target="$post->id" :mine="$post->mine" />

            <button
                type="button"
                x-on:click="thread = true; $nextTick(() => $root.querySelector('[data-comment-box]')?.focus())"
                class="{{ $actionClasses }} text-muted-foreground"
            >
                <flux:icon.chat-bubble-oval-left class="size-4" />
                Comment
            </button>
        </div>
    @else
        <p class="mx-5 mt-3 border-t border-border py-2.5 text-center text-xs text-muted-foreground">
            Join {{ $post->groupName }} to react and comment.
        </p>
    @endif

    <div x-show="thread" x-cloak class="mx-5 space-y-3 border-t border-border pb-4 pt-4">
        @if ($earlier > 0)
            <button
                type="button"
                x-show="! all"
                x-on:click="all = true"
                class="cursor-pointer text-xs font-semibold text-muted-foreground hover:underline"
            >
                View {{ $earlier }} earlier {{ $earlier === 1 ? 'comment' : 'comments' }}
            </button>
        @endif

        @foreach ($post->comments as $k => $comment)
            <div wire:key="comment-{{ $comment->id }}" @if ($k < $earlier) x-show="all" x-cloak @endif>
                @include('livewire.community.partials.comment', ['postId' => $post->id, 'groupId' => $post->groupId, 'canInteract' => $post->canInteract, 'focusComment' => $focusComment])
            </div>
        @endforeach

        @if ($post->canInteract)
            <form wire:submit="comment('{{ $post->id }}')" class="space-y-2">
                <x-social.staged :draft="$slot" :files="$staged[$slot] ?? []" compact class="ml-10" />
                <div class="flex items-center gap-2">
                    <x-social.avatar :name="$viewer->name" :photo="$viewer->photo" size="sm" />
                    <div
                        class="relative flex min-w-0 flex-1 items-center gap-1 rounded-full bg-muted pl-4 pr-1.5 focus-within:ring-2 focus-within:ring-ring"
                        x-data="mentionPicker({ audience: @js($post->groupId ?? '') })"
                    >
                        <input
                            data-comment-box
                            type="text"
                            wire:model="drafts.{{ $post->id }}"
                            maxlength="{{ $maxComment }}"
                            data-paste-limit="A comment"
                            placeholder="Write a comment…"
                            aria-label="Write a comment"
                            class="h-9 min-w-0 flex-1 bg-transparent text-sm outline-hidden placeholder:text-muted-foreground"
                        />
                        <x-social.mention-list />
                        <span class="contents" x-data="attachmentPicker({ maxBytes: @js($maxBytes), slot: @js($slot), max: @js($maxAttachments) })">
                            <input x-ref="photos" type="file" accept="image/*" multiple class="sr-only" tabindex="-1" aria-hidden="true" x-on:change="pick($event)" />
                            <input x-ref="files" type="file" accept="{{ $accept }}" multiple class="sr-only" tabindex="-1" aria-hidden="true" x-on:change="pick($event)" />
                            <button type="button" x-on:click="$refs.photos.click()" x-bind:disabled="busy" class="{{ $boxButton }}" aria-label="Add photos" title="Add photos" @disabled($held >= $maxAttachments)>
                                <flux:icon.photo class="size-4" />
                            </button>
                            <button type="button" x-on:click="$refs.files.click()" x-bind:disabled="busy" class="{{ $boxButton }}" aria-label="Add files" title="Add files" @disabled($held >= $maxAttachments)>
                                <flux:icon.paper-clip class="size-4" />
                            </button>
                        </span>
                        <button type="submit" class="inline-flex size-7 shrink-0 cursor-pointer items-center justify-center rounded-full text-primary transition-colors hover:bg-primary-soft" aria-label="Post comment">
                            <flux:icon.paper-airplane class="size-4" />
                        </button>
                    </div>
                </div>
            </form>
        @endif
    </div>
</article>
