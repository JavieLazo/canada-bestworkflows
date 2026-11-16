{{--
    One post: who, when and to whom; the body with its mentions; the photos
    and files; the reaction and comment tally; the action bar; and the
    thread.

    React: a click toggles Like, or takes back whatever the viewer already
    chose, and resting on the button offers all six. A click on the tally
    lists who reacted and with what. The thread shows its last two comments
    until asked for the rest; a post with none keeps it closed until
    "Comment".
    Inside a group the viewer has not joined, reacting, commenting and
    editing give way to a note that joining is how to take part.

    The lines between the parts are all inset the same way, so the card reads
    as one piece.

    Opened from a notification about one of its comments, the thread opens
    all the way down to that comment, which is highlighted and scrolled to.

    The author, while they can take part in its group, edits the post in
    place, from the ⋮ menu, each part where it already shows: the text in a box that starts with it, the tag in a menu
    where the tag sits, and the photos and files in a row that opens each
    one, with an × to take it off and a tile to add more. Escape or Cancel
    puts the post back as it was. Once edited, a post says so beside
    its time, and "Edited" opens every earlier version.

    Expects: $post (PostView), $names, $people, $groupNames, $tagsByCode,
    $viewer (PersonView), $now, $inGroup (true inside one group's feed, where
    naming the group is noise), and optionally $focusComment (a comment id);
    reads $staged, $maxAttachments, $maxBytes, $maxBody.
--}}
@use('App\Livewire\Community\Support\CommunityLabels')
@use('App\Modules\Social\Application\Attachments')
@use('App\Support\HrisFormat')
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
    $editText = $post->canEdit ? SocialFormat::plain($post->body, $names, $groupNames) : '';
    $slot = 'c-'.$post->id;
    $held = count($staged[$slot] ?? []);
    $accept = '.'.implode(',.', Attachments::FILE_EXTENSIONS);
    $actionClasses = 'flex h-9 w-full cursor-pointer items-center justify-center gap-2 rounded-md text-sm font-medium transition-colors hover:bg-accent hover:text-accent-foreground';
    $boxButton = 'inline-flex size-7 shrink-0 cursor-pointer items-center justify-center rounded-full text-muted-foreground transition-colors hover:bg-accent hover:text-accent-foreground disabled:cursor-default disabled:opacity-40';
    $editSlot = 'e-'.$post->id;
    $editHeld = count($staged[$editSlot] ?? []);
    $onPost = count($post->photos) + count($post->files);
@endphp

<article
    id="{{ $post->id }}"
    wire:key="post-{{ $post->id }}"
    class="hris-card scroll-mt-20"
    x-data="{ thread: @js($post->comments !== []), all: @js($revealEarlier), editing: false, editTag: '', editRemoved: [] }"
    x-on:community-edited.window="if ($event.detail.id === @js($post->id)) editing = false"
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
                @if ($post->editedAt !== null)
                    <span aria-hidden="true">&middot;</span>
                    <button type="button" wire:click="openHistory('{{ $post->id }}')" title="Edited {{ SocialFormat::stamp($post->editedAt) }}" aria-label="Edited. See the edit history" class="cursor-pointer hover:underline">Edited</button>
                @endif
                <span aria-hidden="true">&middot;</span>
                @if ($post->groupId === null)
                    <span title="Everyone at the company"><flux:icon.globe-asia-australia class="size-3.5" /><span class="sr-only">Everyone</span></span>
                @else
                    <span title="Members of {{ $post->groupName }}"><flux:icon.user-group class="size-3.5" /><span class="sr-only">Group Members</span></span>
                @endif
            </p>
        </div>

        <div class="flex shrink-0 items-center gap-1">
            @if ($tag !== null)
                <span class="pill {{ CommunityLabels::pill($tag->tone) }} max-w-[9rem] truncate" x-show="! editing">{{ $tag->name }}</span>
            @endif
            @if ($post->canEdit)
                {{-- While editing, the tag changes right where it shows. --}}
                @php
                    $tagChoices = [];
                    foreach ($tagsByCode as $code => $choice) {
                        $tagChoices[$code] = ['name' => $choice->name, 'pill' => CommunityLabels::pill($choice->tone)];
                    }
                @endphp
                <div x-show="editing" x-cloak x-data="{ tags: @js($tagChoices) }">
                    <flux:dropdown position="bottom" align="end">
                        <button
                            type="button"
                            class="pill inline-flex max-w-[11rem] cursor-pointer items-center gap-1"
                            x-bind:class="tags[editTag] ? tags[editTag].pill : 'pill-muted border border-dashed border-border'"
                            x-bind:aria-label="'Tag: ' + (tags[editTag]?.name ?? 'none') + '. Change it'"
                        >
                            <span class="truncate" x-text="tags[editTag]?.name ?? 'Add a Tag'"></span>
                            <flux:icon.chevron-down class="size-3 shrink-0" />
                        </button>
                        <flux:menu class="max-h-72 overflow-y-auto">
                            <flux:menu.item x-on:click="editTag = ''" icon="x-mark">No Tag</flux:menu.item>
                            <flux:menu.separator />
                            @foreach ($tagsByCode as $choice)
                                <flux:menu.item x-on:click="editTag = @js($choice->code)">
                                    <span class="flex min-w-0 items-center gap-2">
                                        <span class="size-2 shrink-0 rounded-full {{ CommunityLabels::dot($choice->tone) }}" aria-hidden="true"></span>
                                        <span class="truncate">{{ $choice->name }}</span>
                                    </span>
                                </flux:menu.item>
                            @endforeach
                        </flux:menu>
                    </flux:dropdown>
                </div>
            @endif
            @if ($post->canDelete || $post->canPin)
                <x-kit.row-actions :label="$post->author->name.'’s post'">
                    @if ($post->canEdit)
                        <flux:menu.item
                            icon="pencil-square"
                            x-on:click="
                                editing = true;
                                editTag = @js($post->tag ?? '');
                                editRemoved = [];
                                $nextTick(() => {
                                    const box = $el.closest('article').querySelector('[data-edit-box]');
                                    box.value = @js($editText);
                                    box.dispatchEvent(new Event('input', { bubbles: true }));
                                    box.focus();
                                    box.setSelectionRange(box.value.length, box.value.length);
                                })
                            "
                        >
                            Edit Post
                        </flux:menu.item>
                    @endif
                    @if ($post->canPin)
                        <flux:menu.item icon="bookmark" wire:click="togglePin('{{ $post->id }}', {{ $post->pinned ? 'false' : 'true' }})">
                            {{ $post->pinned ? 'Unpin from the Feed' : 'Pin to the Top of the Feed' }}
                        </flux:menu.item>
                    @endif
                    {{-- The author's alone: nobody else is offered a post's delete, and RemovePost checks again. --}}
                    @if ($post->canDelete)
                        @php
                            $askDelete = ['title' => 'Delete this post?', 'body' => 'Its comments, photos and files go with it.', 'confirm' => 'Delete Post', 'danger' => true, 'call' => ['deletePost', $post->id]];
                        @endphp
                        <flux:menu.item icon="trash" variant="danger" x-on:click="$dispatch('social-confirm', @js($askDelete))">
                            Delete Post
                        </flux:menu.item>
                    @endif
                </x-kit.row-actions>
            @endif
        </div>
    </header>

    @if (trim($post->body) !== '')
        <p class="px-5 pt-3 text-sm leading-relaxed" x-show="! editing">
            <x-social.text :body="$post->body" :names="$names" :groups="$groupNames" open-group />
        </p>
    @endif

    @if ($post->canEdit)
        @php
            // What's waiting to be added, split the way the card shows it.
            $newPhotos = [];
            $newFiles = [];
            foreach ($staged[$editSlot] ?? [] as $i => $file) {
                if ((Attachments::identify($file)[0] ?? null) === 'PHOTO') {
                    try {
                        $newPhotos[$i] = $file->temporaryUrl();
                    } catch (\Throwable) {
                        $newPhotos[$i] = null;
                    }
                } else {
                    $newFiles[$i] = $file;
                }
            }
            $newPreviews = array_values(array_filter($newPhotos));
            $takeOff = 'absolute right-1 top-1 inline-flex size-5 cursor-pointer items-center justify-center rounded-full bg-black/60 text-white transition-colors hover:bg-black/80';
            $fileCard = 'flex min-w-0 max-w-64 items-center gap-2 rounded-lg border border-border bg-card py-1.5 pl-2 pr-1';
            $fileOff = 'inline-flex size-6 shrink-0 cursor-pointer items-center justify-center rounded-full text-muted-foreground transition-colors hover:bg-accent hover:text-accent-foreground';
        @endphp
        <form x-show="editing" x-cloak x-on:submit.prevent="$wire.saveEdit(@js($post->id), editTag, editRemoved)" class="px-5 pb-5 pt-3">
            <div class="relative" x-data="mentionPicker({ audience: @js($post->groupId ?? '') })">
                {{-- wire:ignore.self keeps the height the box grew to when an upload redraws the card. --}}
                <textarea
                    data-edit-box
                    wire:ignore.self
                    wire:model="edits.{{ $post->id }}"
                    x-on:input="$el.style.height = 'auto'; $el.style.height = $el.scrollHeight + 'px'"
                    x-on:keydown.escape="editing = false; $wire.cancelEdit(@js($post->id))"
                    x-on:keydown.meta.enter.prevent="$wire.saveEdit(@js($post->id), editTag, editRemoved)"
                    x-on:keydown.ctrl.enter.prevent="$wire.saveEdit(@js($post->id), editTag, editRemoved)"
                    rows="2"
                    maxlength="{{ $maxBody }}"
                    data-paste-limit="A post"
                    aria-label="Edit your post"
                    class="block max-h-72 min-h-11 w-full resize-none rounded-lg bg-muted px-3 py-2 text-sm leading-relaxed outline-hidden focus:ring-2 focus:ring-ring"
                ></textarea>
                <x-social.mention-list />
            </div>

            {{-- The post's photos, then the ones being added, then a tile to add more. A click opens one; an × takes it off, and Cancel puts it back. --}}
            <div class="mt-2 flex flex-wrap gap-2">
                @foreach ($post->photos as $i => $photo)
                    <div wire:key="edit-{{ $photo }}" x-show="! editRemoved.includes(@js($photo))" class="relative size-20 shrink-0 overflow-hidden rounded-md border border-border bg-muted">
                        <button type="button" x-on:click="$dispatch('lightbox', { images: @js($photoUrls), index: {{ $i }} })" class="block size-full cursor-pointer" aria-label="Look at photo {{ $loop->iteration }}">
                            <img src="{{ $photoUrls[$i] }}" alt="" class="size-full object-cover" />
                        </button>
                        <button type="button" x-on:click="editRemoved.push(@js($photo))" class="{{ $takeOff }}" aria-label="Take photo {{ $loop->iteration }} off the post">
                            <flux:icon.x-mark class="size-3" />
                        </button>
                    </div>
                @endforeach
                @foreach ($newPhotos as $i => $url)
                    <div wire:key="edit-new-{{ $i }}-{{ $staged[$editSlot][$i]->getFilename() }}" class="relative size-20 shrink-0 overflow-hidden rounded-md border border-border bg-muted">
                        @if ($url !== null)
                            <button type="button" x-on:click="$dispatch('lightbox', { images: @js($newPreviews), index: {{ max(0, (int) array_search($url, $newPreviews, true)) }}, download: false })" class="block size-full cursor-pointer" aria-label="Look at new photo {{ $loop->iteration }}">
                                <img src="{{ $url }}" alt="" class="size-full object-cover" />
                            </button>
                        @endif
                        <button type="button" wire:click="unstage('{{ $editSlot }}', {{ $i }})" class="{{ $takeOff }}" aria-label="Remove new photo {{ $loop->iteration }}">
                            <flux:icon.x-mark class="size-3" />
                        </button>
                    </div>
                @endforeach
                {{-- The picker counts what the post keeps as well as what's staged, so one pick of several files can't add more than the post has room for. --}}
                <span class="contents" x-data="{ ...attachmentPicker({ maxBytes: @js($maxBytes), slot: @js($editSlot), max: @js($maxAttachments) }), held() { return ($wire.staged?.[@js($editSlot)] ?? []).length + {{ $onPost }} - editRemoved.length } }">
                    <input x-ref="pick" type="file" accept="image/*,{{ $accept }}" multiple class="sr-only" tabindex="-1" aria-hidden="true" x-on:change="pick($event)" />
                    <button
                        type="button"
                        x-on:click="$refs.pick.click()"
                        x-bind:disabled="busy || {{ $onPost + $editHeld }} - editRemoved.length >= {{ $maxAttachments }}"
                        class="flex size-20 shrink-0 cursor-pointer flex-col items-center justify-center gap-1 rounded-md border border-dashed border-border text-xs font-medium text-muted-foreground transition-colors hover:bg-accent hover:text-foreground disabled:cursor-default disabled:opacity-40"
                        aria-label="Add photos or files"
                        title="Add photos or files"
                    >
                        <flux:icon.plus class="size-5" x-show="! busy" />
                        <span x-text="busy ? 'Uploading…' : 'Add'">Add</span>
                    </button>
                </span>
            </div>

            @if ($post->files !== [] || $newFiles !== [])
                <ul class="mt-2 flex flex-wrap gap-2">
                    @foreach ($post->files as $file)
                        <li wire:key="edit-{{ $file->id }}" x-show="! editRemoved.includes(@js($file->id))" class="{{ $fileCard }}">
                            <a href="{{ route('community.files', ['file' => $file->id]) }}" download class="flex min-w-0 flex-1 items-center gap-2" title="Download {{ $file->name }}">
                                @include('components.social.file-icon', ['extension' => $file->extension()])
                                <span class="min-w-0 flex-1">
                                    <span class="block truncate text-xs font-medium">{{ $file->name }}</span>
                                    <span class="block text-[0.7rem] text-muted-foreground">{{ HrisFormat::bytes($file->size) }}</span>
                                </span>
                            </a>
                            <button type="button" x-on:click="editRemoved.push(@js($file->id))" class="{{ $fileOff }}" aria-label="Take {{ $file->name }} off the post">
                                <flux:icon.x-mark class="size-3.5" />
                            </button>
                        </li>
                    @endforeach
                    @foreach ($newFiles as $i => $file)
                        <li wire:key="edit-new-{{ $i }}-{{ $file->getFilename() }}" class="{{ $fileCard }}">
                            @include('components.social.file-icon', ['extension' => strtolower((string) pathinfo($file->getClientOriginalName(), PATHINFO_EXTENSION))])
                            <span class="min-w-0 flex-1">
                                <span class="block truncate text-xs font-medium">{{ $file->getClientOriginalName() }}</span>
                                <span class="block text-[0.7rem] text-muted-foreground">{{ HrisFormat::bytes((int) $file->getSize()) }}</span>
                            </span>
                            <button type="button" wire:click="unstage('{{ $editSlot }}', {{ $i }})" class="{{ $fileOff }}" aria-label="Remove {{ $file->getClientOriginalName() }}">
                                <flux:icon.x-mark class="size-3.5" />
                            </button>
                        </li>
                    @endforeach
                </ul>
            @endif
            <p x-show="editRemoved.length > 0" x-cloak class="mt-1.5 text-xs text-muted-foreground">What you take off stays in the post's edit history until the post is deleted.</p>

            <div class="mt-3 flex justify-end gap-2">
                <flux:button type="button" size="sm" variant="ghost" x-on:click="editing = false; $wire.cancelEdit(@js($post->id))">Cancel</flux:button>
                <flux:button type="submit" size="sm" variant="primary">Save</flux:button>
            </div>
        </form>
    @endif

    <x-social.photo-grid :urls="$photoUrls" :key="'post-'.$post->id.'-img'" class="mt-3" x-show="! editing" />

    <x-social.files :files="$post->files" route="community.files" class="px-5 pt-3" x-show="! editing" />

    {{-- While editing, the card is only the post being edited: its counts, its buttons and its thread step aside. --}}
    @if ($reactionTotal > 0 || $post->commentCount > 0)
        <div class="flex items-center justify-between gap-3 px-5 pt-3 text-sm text-muted-foreground" x-show="! editing">
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
        <div class="mx-5 mt-3 grid grid-cols-2 gap-1 border-t border-border py-1" x-show="! editing">
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
        <p class="mx-5 mt-3 border-t border-border py-2.5 text-center text-xs text-muted-foreground" x-show="! editing">
            Join {{ $post->groupName }} to react and comment.
        </p>
    @endif

    <div x-show="thread && ! editing" x-cloak class="mx-5 space-y-3 border-t border-border pb-4 pt-4">
        @if ($earlier > 0)
            <button
                type="button"
                x-show="! all"
                x-on:click="all = true"
                class="cursor-pointer text-xs font-semibold text-muted-foreground hover:underline"
            >
                View {{ $earlier }} Earlier {{ $earlier === 1 ? 'Comment' : 'Comments' }}
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
