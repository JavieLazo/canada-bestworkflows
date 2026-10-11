{{--
    Two dialogs that pick people.

    New chat: tick one person for a private chat (which reopens the one you
    already have with them, rather than starting a second), or several to
    start a group, which may take a name.

    Add people: adds whoever is ticked to the open group chat. They see what
    was already said, and nothing counts as unread for them.

    Plain checkboxes rather than <flux:checkbox>: several boxes bound to one
    array property is Livewire's own checkbox contract, and it needs nothing
    between the input and wire:model.

    Expects: $directory (list of PersonView), $active; reads $newChatOpen,
    $addPeopleOpen, $pickedIds.
--}}
@foreach (['newChatOpen' => 'new', 'addPeopleOpen' => 'add'] as $flag => $mode)
    <flux:modal wire:model.self="{{ $flag }}" class="w-full max-w-md">
        <div class="space-y-4">
            <div class="space-y-1.5">
                <h2 class="text-lg font-semibold leading-none tracking-tight">
                    {{ $mode === 'new' ? 'New chat' : 'Add people to '.($active->title ?? 'the chat') }}
                </h2>
                <p class="text-sm text-muted-foreground">
                    {{ $mode === 'new' ? 'Pick one person for a private chat, or several to start a group.' : 'They see what was already said here, and everyone is told who joined.' }}
                </p>
            </div>

            <flux:input
                icon="magnifying-glass"
                placeholder="Search by name or position"
                aria-label="Search people"
                wire:model.live.debounce.200ms="peopleSearch"
            />

            <ul class="-mx-2 max-h-72 space-y-0.5 overflow-y-auto">
                @forelse ($directory as $person)
                    <li wire:key="pick-{{ $mode }}-{{ $person->id }}">
                        <label class="flex cursor-pointer items-center gap-3 rounded-md px-2 py-2 transition-colors hover:bg-accent">
                            <x-social.avatar :name="$person->name" :photo="$person->photo" size="sm" />
                            <span class="min-w-0 flex-1 text-sm">
                                <span class="block truncate font-medium">{{ $person->name }}</span>
                                @if ($person->position !== null)
                                    <span class="block truncate text-xs text-muted-foreground">{{ $person->position }}</span>
                                @endif
                            </span>
                            <input type="checkbox" value="{{ $person->id }}" wire:model.live="pickedIds" class="size-4 cursor-pointer accent-primary" />
                        </label>
                    </li>
                @empty
                    <li><x-kit.empty-state :title="trim($peopleSearch) !== '' ? 'Nobody matches that search.' : ($mode === 'add' ? 'Everyone who can use Chat is already here.' : 'Nobody else can use Chat yet.')" /></li>
                @endforelse
            </ul>

            @if ($mode === 'new' && count($pickedIds) > 1)
                <div class="space-y-2">
                    <flux:label for="chat-group-name">Group name</flux:label>
                    <flux:input id="chat-group-name" wire:model="groupName" maxlength="60" placeholder="Optional. Uses first names if left blank." />
                </div>
            @endif

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">Cancel</flux:button>
                </flux:modal.close>
                @if ($mode === 'new')
                    <flux:button variant="primary" wire:click="startChat" :disabled="$pickedIds === []">
                        {{ count($pickedIds) > 1 ? 'Start group' : 'Start chat' }}
                    </flux:button>
                @else
                    <flux:button variant="primary" wire:click="addPeople" :disabled="$pickedIds === []">
                        {{ count($pickedIds) > 1 ? 'Add '.count($pickedIds).' people' : 'Add' }}
                    </flux:button>
                @endif
            </div>
        </div>
    </flux:modal>
@endforeach
