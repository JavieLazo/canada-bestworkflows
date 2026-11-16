{{--
    Community's dialogs: the group form (new or edit, with a photo, and for a
    new group the people to start with), adding people to a group, every
    group in one searchable list, "Coming up" on a phone, the tag manager,
    and a post's edit history. The commands behind them check who's allowed
    again, whatever this shows.

    The tag manager is one dialog with two pages: the list of tags, each with
    an Edit button, and the form for one tag, reached from Edit or New tag.

    Reads: $groupDialog, $editingGroupId, $groupForm, $staged, $peopleDialog,
    $directory, $pickedPeople, $activeGroup, $groupsDialog, $groupsTab,
    $groupsSearch, $joinedGroups, $otherGroups, $comingUpDialog,
    $celebrations, $tagsDialog, $tagPage, $editingTagId, $tagForm, $tags,
    $canModerate, $maxBytes, $historyDialog, $history, $groupNames, $tagsByCode.
--}}
@use('App\Livewire\Community\Support\CommunityLabels')
@use('App\Modules\Social\Application\SocialRules')
@use('App\Support\SocialFormat')
@php
    $avatarStaged = $staged['avatar'][0] ?? null;
    $avatarPreview = null;
    if ($avatarStaged !== null) {
        try {
            $avatarPreview = $avatarStaged->temporaryUrl();
        } catch (\Throwable) {
            $avatarPreview = null;
        }
    }
    $editing = $editingGroupId !== null ? collect([...$joinedGroups, ...$otherGroups])->firstWhere('id', $editingGroupId) : null;
    $currentPhoto = $editing?->photo;
    $photoRemoved = ($groupForm['removePhoto'] ?? false) === true;
    $needle = mb_strtolower(trim($groupsSearch));
    $listed = array_values(array_filter(
        $groupsTab === 'discover' ? $otherGroups : $joinedGroups,
        static fn ($g) => $needle === '' || str_contains(mb_strtolower($g->name.' '.$g->description), $needle),
    ));
    $editingTag = $editingTagId !== null ? collect($tags)->firstWhere('id', $editingTagId) : null;
    $formTone = is_string($tagForm['tone'] ?? null) ? $tagForm['tone'] : 'muted';
    $formName = is_string($tagForm['name'] ?? null) ? $tagForm['name'] : '';
@endphp

@if ($canModerate || ($activeGroup?->canManage ?? false))
    <flux:modal wire:model.self="groupDialog" class="w-full max-w-lg">
        <form wire:submit="saveGroup" class="space-y-5">
            <div class="space-y-1.5">
                <h2 class="text-lg font-semibold leading-none tracking-tight">{{ $editingGroupId === null ? 'New Group' : 'Edit Group' }}</h2>
                <p class="text-sm text-muted-foreground">
                    {{ $editingGroupId === null ? 'You will be its owner and its first member.' : 'Changes show for everyone straight away.' }}
                </p>
            </div>

            <div class="flex items-center gap-4" x-data="attachmentPicker({ maxBytes: @js($maxBytes), slot: 'avatar', photosOnly: true })">
                <x-social.avatar
                    :name="is_string($groupForm['name'] ?? null) && trim($groupForm['name']) !== '' ? $groupForm['name'] : 'New group'"
                    :photo="$avatarPreview ?? ($photoRemoved ? null : $currentPhoto)"
                    icon="user-group"
                    size="lg"
                />
                <input x-ref="photo" type="file" accept="image/*" class="sr-only" tabindex="-1" aria-hidden="true" x-on:change="pick($event)" />
                <div class="flex flex-wrap gap-2">
                    <flux:button type="button" size="sm" icon="camera" x-on:click="$refs.photo.click()" x-bind:disabled="busy">
                        <span x-show="! busy">{{ $avatarPreview !== null || ($currentPhoto !== null && ! $photoRemoved) ? 'Change Photo' : 'Add a Photo' }}</span>
                        <span x-show="busy" x-cloak>Uploading…</span>
                    </flux:button>
                    @if ($avatarPreview !== null)
                        <flux:button type="button" size="sm" variant="ghost" wire:click="unstage('avatar', 0)">Undo</flux:button>
                    @elseif ($currentPhoto !== null && ! $photoRemoved)
                        <flux:button type="button" size="sm" variant="ghost" wire:click="$set('groupForm.removePhoto', true)">Remove</flux:button>
                    @endif
                </div>
            </div>

            <div class="space-y-2">
                <flux:label for="group-name">Name</flux:label>
                <flux:input id="group-name" wire:model.live.debounce.400ms="groupForm.name" maxlength="{{ SocialRules::MAX_GROUP_NAME }}" required />
            </div>

            <div class="space-y-2">
                <flux:label for="group-about">Description</flux:label>
                <flux:textarea id="group-about" rows="3" wire:model="groupForm.about" maxlength="{{ SocialRules::MAX_GROUP_ABOUT }}" />
            </div>

            <div class="flex items-start justify-between gap-4">
                <div class="min-w-0">
                    <flux:label for="group-private">Private Group</flux:label>
                    <p class="mt-1 text-sm text-muted-foreground">Only members see its posts, members and files, and joining takes a yes from its owner or a moderator. Making it public again lets in everyone who's waiting.</p>
                </div>
                <flux:switch id="group-private" wire:model="groupForm.private" aria-label="Private group" />
            </div>

            @if ($editingGroupId === null)
                {{-- Folded until wanted, so the form stays short. Anyone can be added later too. --}}
                <div class="space-y-2" x-data="{ adding: @js($pickedPeople !== []) }">
                    <flux:button type="button" size="sm" variant="ghost" icon="user-plus" x-show="! adding" x-on:click="adding = true">Add People Now</flux:button>
                    <div x-show="adding" x-cloak class="space-y-2">
                        <flux:label>People to Start With <span class="ml-1 font-normal text-muted-foreground">(optional)</span></flux:label>
                        @include('livewire.community.partials.people-picker')
                    </div>
                </div>
            @endif

            <div class="flex justify-end gap-2">
                <flux:button type="button" variant="outline" x-on:click="$wire.groupDialog = false">Cancel</flux:button>
                <flux:button type="submit" variant="primary">{{ $editingGroupId === null ? 'Create Group' : 'Save Group' }}</flux:button>
            </div>
        </form>
    </flux:modal>
@endif

@if ($activeGroup !== null && $activeGroup->joined())
    <flux:modal wire:model.self="peopleDialog" class="w-full max-w-md">
        <div class="space-y-4">
            <div class="space-y-1.5">
                <h2 class="text-lg font-semibold leading-none tracking-tight">Add People to {{ $activeGroup->name }}</h2>
                <p class="text-sm text-muted-foreground">
                    @if ($activeGroup->private && ! $activeGroup->canManage)
                        It's a private group, so the owner or a moderator will let them in.
                    @else
                        They're in straight away, and they're told.
                    @endif
                </p>
            </div>

            @include('livewire.community.partials.people-picker')

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">Cancel</flux:button>
                </flux:modal.close>
                <flux:button variant="primary" wire:click="addPeople" :disabled="$pickedPeople === []">
                    {{ count($pickedPeople) > 1 ? 'Add '.count($pickedPeople).' people' : 'Add' }}
                </flux:button>
            </div>
        </div>
    </flux:modal>
@endif

<flux:modal wire:model.self="groupsDialog" class="w-full max-w-md">
    <div class="space-y-4">
        <h2 class="text-lg font-semibold leading-none tracking-tight">Groups</h2>

        <div class="inline-flex h-9 w-full items-center rounded-lg bg-muted p-1 text-muted-foreground" role="tablist">
            @foreach (['yours' => 'Your Groups ('.count($joinedGroups).')', 'discover' => 'Discover ('.count($otherGroups).')'] as $key => $label)
                <button
                    type="button"
                    role="tab"
                    aria-selected="{{ $groupsTab === $key ? 'true' : 'false' }}"
                    wire:click="$set('groupsTab', '{{ $key }}')"
                    @class([
                        'inline-flex flex-1 cursor-pointer items-center justify-center rounded-md px-3 py-1 text-sm font-medium transition-all',
                        'bg-card text-foreground shadow-sm' => $groupsTab === $key,
                        'hover:text-foreground' => $groupsTab !== $key,
                    ])
                >{{ $label }}</button>
            @endforeach
        </div>

        <flux:input icon="magnifying-glass" placeholder="Search groups" aria-label="Search groups" wire:model.live.debounce.200ms="groupsSearch" />

        <ul class="-mx-2 max-h-96 space-y-0.5 overflow-y-auto">
            @if ($groupsTab === 'yours' && $needle === '')
                <li>
                    <button type="button" wire:click="showFeed" class="flex w-full cursor-pointer items-center gap-3 rounded-md p-2 text-left text-sm transition-colors hover:bg-accent">
                        <span class="flex size-8 shrink-0 items-center justify-center rounded-full bg-primary text-primary-foreground">
                            @include('layouts.app.module-icon', ['icon' => 'newspaper', 'class' => 'size-4'])
                        </span>
                        <span class="min-w-0 flex-1 truncate font-medium">All Posts</span>
                    </button>
                </li>
            @endif
            @forelse ($listed as $g)
                @include('livewire.community.partials.group-row', ['group' => $g, 'groupId' => $group, 'key' => 'all-groups'])
            @empty
                <li class="px-2 py-6 text-center text-sm text-muted-foreground">
                    {{ $needle !== '' ? 'No group matches that search.' : ($groupsTab === 'discover' ? "You're in every group there is." : 'You have not joined a group yet.') }}
                </li>
            @endforelse
        </ul>
    </div>
</flux:modal>

@if ($celebrations !== [])
    <flux:modal wire:model.self="comingUpDialog" class="w-full max-w-md">
        <div class="space-y-2">
            <h2 class="text-lg font-semibold leading-none tracking-tight">Coming Up</h2>
            <p class="text-sm text-muted-foreground">The next 30 days, for people who chose to share it.</p>
            @include('livewire.community.partials.celebrations')
        </div>
    </flux:modal>
@endif

@if ($canModerate)
    <flux:modal wire:model.self="tagsDialog" class="w-full max-w-md">
        @if ($tagPage === 'form')
            <form wire:submit="saveTag" class="space-y-5">
                <button type="button" wire:click="backToTags" class="inline-flex cursor-pointer items-center gap-1 text-sm font-medium text-muted-foreground transition-colors hover:text-foreground">
                    <flux:icon.arrow-left class="size-4" />
                    All Tags
                </button>

                <h2 class="text-lg font-semibold leading-none tracking-tight">{{ $editingTag !== null ? 'Edit '.$editingTag->name : 'New Tag' }}</h2>

                <div class="space-y-2">
                    <flux:label for="tag-name">Name</flux:label>
                    <flux:input id="tag-name" wire:model.live.debounce.300ms="tagForm.name" maxlength="{{ SocialRules::MAX_TAG_NAME }}" placeholder="Safety tips" required autofocus />
                </div>

                <fieldset class="space-y-2">
                    <legend class="text-sm font-medium">Colour</legend>
                    <div class="grid grid-cols-2 gap-2 sm:grid-cols-3">
                        @foreach (CommunityLabels::TONES as $tone => $look)
                            <label @class([
                                'flex cursor-pointer items-center gap-2 rounded-lg border px-3 py-2 text-sm transition-colors',
                                'border-primary bg-primary-soft/40' => $formTone === $tone,
                                'border-border hover:bg-accent' => $formTone !== $tone,
                            ])>
                                <input type="radio" wire:model.live="tagForm.tone" value="{{ $tone }}" class="sr-only" />
                                <span class="size-3 shrink-0 rounded-full {{ $look['dot'] }}" aria-hidden="true"></span>
                                {{ $look['label'] }}
                            </label>
                        @endforeach
                    </div>
                </fieldset>

                <div class="flex items-center gap-2 text-sm text-muted-foreground">
                    Looks like
                    <span class="pill {{ CommunityLabels::pill($formTone) }} max-w-[12rem] truncate">{{ $formName !== '' ? $formName : 'Tag' }}</span>
                </div>

                <div class="flex flex-wrap items-center justify-between gap-2 border-t border-border pt-4">
                    @if ($editingTag !== null)
                        @php
                            $askRemoveTag = [
                                'title' => 'Remove '.$editingTag->name.'?',
                                'body' => match ($editingTag->posts) {
                                    0 => 'No posts carry it.',
                                    1 => 'The one post that carries it stays, untagged.',
                                    default => 'The '.$editingTag->posts.' posts that carry it stay, untagged.',
                                },
                                'confirm' => 'Remove Tag',
                                'danger' => true,
                                'call' => ['deleteTag', $editingTag->id],
                            ];
                        @endphp
                        <flux:button
                            type="button"
                            size="sm"
                            variant="ghost"
                            icon="trash"
                            class="text-danger!"
                            x-on:click="$dispatch('social-confirm', @js($askRemoveTag))"
                        >Remove Tag</flux:button>
                    @else
                        <span></span>
                    @endif
                    <div class="flex gap-2">
                        <flux:button type="button" variant="outline" wire:click="backToTags">Cancel</flux:button>
                        <flux:button type="submit" variant="primary">{{ $editingTag !== null ? 'Save' : 'Add Tag' }}</flux:button>
                    </div>
                </div>
            </form>
        @else
            <div class="space-y-4">
                <div class="flex items-start justify-between gap-3 pr-8">
                    <div class="space-y-1.5">
                        <h2 class="text-lg font-semibold leading-none tracking-tight">Tags</h2>
                        <p class="text-sm text-muted-foreground">Tags help people find posts. A change shows on every post that has the tag.</p>
                    </div>
                    <flux:button size="sm" variant="primary" icon="plus" wire:click="newTag">New Tag</flux:button>
                </div>

                <ul class="-mx-2 max-h-96 divide-y divide-border overflow-y-auto">
                    @forelse ($tags as $t)
                        <li wire:key="tag-{{ $t->id }}" class="flex items-center gap-3 px-2 py-2.5">
                            <span class="pill {{ CommunityLabels::pill($t->tone) }} min-w-0 max-w-[10rem] truncate">{{ $t->name }}</span>
                            <span class="flex-1 text-xs text-muted-foreground"><span class="num">{{ $t->posts }}</span> {{ $t->posts === 1 ? 'post' : 'posts' }}</span>
                            <flux:button size="sm" variant="ghost" icon="pencil" wire:click="editTag('{{ $t->id }}')" aria-label="Edit {{ $t->name }}">Edit</flux:button>
                        </li>
                    @empty
                        <li class="px-2 py-6 text-center text-sm text-muted-foreground">No tags yet. Add the first one.</li>
                    @endforelse
                </ul>
            </div>
        @endif
    </flux:modal>
@endif

{{--
    Newest first: the post as it is now, then as it was before each edit,
    each with its tag, photos and files. A photo opens in the photo viewer,
    which sits above this dialog.
--}}
<flux:modal wire:model.self="historyDialog" class="w-full max-w-2xl">
    <div class="space-y-4">
        <h2 class="text-lg font-semibold leading-none tracking-tight">Edit History</h2>
        @if ($history !== null)
            <ol class="max-h-[70vh] space-y-3 overflow-y-auto">
                @foreach ($history['versions'] as $i => $version)
                    @php
                        $versionTag = $version['tag'] !== null ? ($tagsByCode[$version['tag']] ?? null) : null;
                    @endphp
                    <li class="space-y-2 rounded-lg border border-border p-3">
                        <p class="flex items-center gap-1.5 text-xs text-muted-foreground">
                            <span>{{ SocialFormat::stamp($version['at']) }}</span>
                            @if ($i === 0)
                                <span aria-hidden="true">&middot;</span>
                                <span>Current</span>
                            @endif
                            @if ($versionTag !== null)
                                <span class="pill {{ CommunityLabels::pill($versionTag->tone) }} ml-auto max-w-[9rem] truncate">{{ $versionTag->name }}</span>
                            @endif
                        </p>
                        @if (trim($version['body']) !== '')
                            <x-social.text :body="$version['body']" :names="$history['names']" :groups="$groupNames" class="block text-sm" />
                        @endif
                        @if ($version['photos'] !== [])
                            @php
                                $versionUrls = array_map(static fn (string $id): string => route('community.photos', ['photo' => $id]), $version['photos']);
                            @endphp
                            <div class="flex flex-wrap gap-2">
                                @foreach ($versionUrls as $k => $url)
                                    <button type="button" x-on:click="$dispatch('lightbox', { images: @js($versionUrls), index: {{ $k }} })" class="block size-20 shrink-0 cursor-pointer overflow-hidden rounded-md border border-border bg-muted" aria-label="Look at photo {{ $loop->iteration }}">
                                        <img src="{{ $url }}" alt="" loading="lazy" class="size-full object-cover" />
                                    </button>
                                @endforeach
                            </div>
                        @endif
                        <x-social.files :files="$version['files']" route="community.files" compact />
                    </li>
                @endforeach
            </ol>
        @elseif ($historyDialog)
            <p class="text-sm text-muted-foreground">That post isn't here anymore.</p>
        @endif
    </div>
</flux:modal>
