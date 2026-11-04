{{--
    One message in a thread, laid out by ChatThread::rows(): a quiet time
    label after a pause, the sender's name over the first bubble of a run
    (groups only), their avatar beside the last, and the corner nearest the
    sender squared off inside a run so a burst reads as one turn.

    A reply says who replied to whom above a quote of the original, and that
    line already names the sender, so the name isn't repeated over it. The
    reply keeps its space above, but what comes right under it sits closer
    (12px instead of 20px): someone else's answer, or the same person's next
    reply. The label and quote already set a reply apart.

    Hovering (or focusing) a message shows its time with react, reply and, on
    your own, edit and remove. A phone can't hover, so a tap on the bubble
    shows them instead. Reactions ride a badge on the bubble's lower edge, with room
    left under it, and a click on it lists who reacted. A removed message
    stays as a placeholder.

    The message a notification points at ($focusMessageId) sits on a soft
    brand background, so it stands out from the ones around it.

    Editing turns the bubble into a box with the words as they show, with no
    time limit. Enter saves and Shift+Enter starts a new line, like the
    composer, and Escape or Cancel leaves it as it was. An edited message
    says so above it, in blue: after a dot beside the sender's name or the
    reply line, or on a line of its own when there's neither.

    Expects: $row, $active (ConversationView), $viewer, $names, $people,
    $focusMessageId; reads $maxBody.
--}}
@use('App\Support\SocialFormat')
@php
    $m = $row['message'];
    $mine = $m->mine;
    $sender = $m->sender;
    $firstName = $mine ? 'You' : explode(' ', $sender->name ?? 'Someone')[0];
    $reactionCount = $m->reactionTotal();
    $photoUrls = array_map(static fn (string $id): string => route('chat.photos', ['photo' => $id]), $m->photos);
    $photoCount = count($photoUrls);
    $toolButton = 'inline-flex size-7 cursor-pointer items-center justify-center rounded-full text-muted-foreground transition-colors hover:bg-accent hover:text-accent-foreground';
    $found = $focusMessageId !== null && $m->id === $focusMessageId;
    $editText = $mine && ! $m->removed ? SocialFormat::plain($m->body, $names) : '';
    $edited = $m->editedAt !== null && ! $m->removed;
    // The space above: 20px when the sender changes or a reply starts, 12px
    // right under a reply, 2px inside one person's run.
    $gap = match (true) {
        $m->quote !== null => $row['afterReply'] && ! $row['first'] ? 'mt-3' : 'mt-5',
        $row['first'] => $row['afterReply'] ? 'mt-3' : 'mt-5',
        default => 'mt-0.5',
    };
@endphp

@if ($row['stamp'] !== null)
    <p class="pb-2 pt-6 text-center text-xs font-medium text-muted-foreground first:pt-2" role="separator">{{ $row['stamp'] }}</p>
@endif

@if ($m->system)
    {{-- The time only shows on hover (a tap on a phone), in a small box a
         little to the right of the line: printed on every one of these, it's
         clutter. When a long line leaves no room on the right, the box flips
         to the left instead of running off the screen. --}}
    <p wire:key="message-{{ $m->id }}" class="my-4 text-center text-xs text-muted-foreground">
        <span
            x-data="{ time: false }"
            x-ref="line"
            x-on:pointerenter="if ($event.pointerType === 'mouse') time = true"
            x-on:pointerleave="if ($event.pointerType === 'mouse') time = false"
            x-on:pointerup="if ($event.pointerType !== 'mouse') time = ! time"
            x-on:click.outside="time = false"
        >
            {{ $m->body }}<span class="sr-only">, {{ SocialFormat::clock($m->createdAt) }}</span>
            <template x-teleport="body">
                <time
                    x-show="time"
                    x-cloak
                    x-anchor.right.offset.8="$refs.line"
                    x-transition:enter="transition-[opacity,scale] duration-150 ease-[cubic-bezier(0.23,1,0.32,1)]"
                    x-transition:enter-start="scale-[0.97] opacity-0 motion-reduce:scale-100"
                    x-transition:enter-end="scale-100 opacity-100"
                    x-transition:leave="transition-opacity duration-100 ease-out"
                    x-transition:leave-start="opacity-100"
                    x-transition:leave-end="opacity-0"
                    datetime="{{ $m->createdAt->toIso8601String() }}"
                    aria-hidden="true"
                    class="pointer-events-none z-50 origin-left whitespace-nowrap rounded-md border border-border bg-popover px-2 py-1 text-[0.7rem] font-medium text-popover-foreground shadow-lift"
                >{{ SocialFormat::clock($m->createdAt) }}</time>
            </template>
        </span>
    </p>
@else
    {{-- The reaction badge hangs below the bubble, so a bottom margin clears it. --}}
    <div
        wire:key="message-{{ $m->id }}"
        x-data="{ tools: false, editing: false }"
        x-on:chat-edited.window="if ($event.detail.id === @js($m->id)) editing = false"
        x-on:message-edit="
            editing = true;
            tools = false;
            $nextTick(() => {
                const box = $el.querySelector('[data-message-edit]');
                box.value = @js($editText);
                box.dispatchEvent(new Event('input', { bubbles: true }));
                box.focus();
                box.setSelectionRange(box.value.length, box.value.length);
            })
        "
        @if ($found) data-found="true" @endif
        @class([
            'group/message flex items-end gap-2',
            'relative isolate before:absolute before:-inset-x-3 before:-inset-y-1.5 before:-z-10 before:rounded-2xl before:bg-primary-soft' => $found,
            'flex-row-reverse' => $mine,
            $gap,
            'mb-4' => $reactionCount > 0,
        ])
    >
        @unless ($mine)
            <div class="w-8 shrink-0">
                @if ($row['last'] && $sender !== null)
                    <x-social.avatar :name="$sender->name" :photo="$sender->photo" size="sm" />
                @endif
            </div>
        @endunless

        {{-- Room is left beside the bubble for the hover tools, so they never squeeze it. --}}
        <div @class(['flex min-w-0 max-w-[80%] flex-col sm:max-w-[min(68%,calc(100%-9.5rem))]', 'items-end' => $mine, 'items-start' => ! $mine])>
            @if ($m->quote !== null)
                <p class="flex items-center gap-1 px-3 text-[0.7rem] text-muted-foreground">
                    <flux:icon.arrow-uturn-left class="size-3" />
                    {{ $firstName }} replied to {{ $m->quote->name }}
                    @if ($edited)
                        <span aria-hidden="true">&middot;</span>
                        <span class="font-normal text-primary" title="Edited {{ SocialFormat::stamp($m->editedAt) }}">Edited</span>
                    @endif
                </p>
                <div class="mb-1.5 mt-1 max-w-full rounded-2xl border border-border px-3 py-1.5 text-xs text-muted-foreground">
                    <span @class(['line-clamp-2', 'italic' => $m->quote->removed])>{{ $m->quote->text !== '' ? $m->quote->text : ($m->quote->photo ? 'Photo' : 'Attachment') }}</span>
                </div>
            @elseif ($row['first'] && ! $mine && $active->isGroup() && $sender !== null)
                <p class="mb-1 flex items-center gap-1 px-3 text-xs font-medium text-muted-foreground">
                    {{ $sender->name }}
                    @if ($edited)
                        <span aria-hidden="true">&middot;</span>
                        <span class="font-normal text-primary" title="Edited {{ SocialFormat::stamp($m->editedAt) }}">Edited</span>
                    @endif
                </p>
            @elseif ($edited)
                <p class="mb-1 px-3 text-xs text-primary" title="Edited {{ SocialFormat::stamp($m->editedAt) }}">Edited</p>
            @endif

            <div @class(['relative flex max-w-full items-center gap-1', 'flex-row-reverse' => $mine])>
                @if ($m->removed)
                    <div class="rounded-2xl border border-dashed border-border px-3.5 py-2 text-sm italic text-muted-foreground">
                        {{ $firstName }} removed a message
                    </div>
                @else
                    <div @class(['flex min-w-0 flex-col gap-1', 'items-end' => $mine, 'items-start' => ! $mine])>
                        @if ($photoCount > 0)
                            <div @class([
                                'grid max-w-xs gap-1 overflow-hidden rounded-2xl',
                                'grid-cols-2' => $photoCount === 2 || $photoCount === 4,
                                'grid-cols-3' => $photoCount === 3 || $photoCount > 4,
                            ])>
                                @foreach ($photoUrls as $k => $src)
                                    <button
                                        type="button"
                                        x-on:click="$dispatch('lightbox', { images: @js($photoUrls), index: {{ $k }} })"
                                        class="block cursor-pointer overflow-hidden bg-muted"
                                        aria-label="Open photo {{ $k + 1 }} of {{ $photoCount }}"
                                    >
                                        <img src="{{ $src }}" alt="" loading="lazy" @class(['w-full object-cover', 'max-h-64' => $photoCount === 1, 'aspect-square' => $photoCount > 1]) />
                                    </button>
                                @endforeach
                            </div>
                        @endif

                        <x-social.files :files="$m->files" route="chat.files" compact @class(['justify-end' => $mine]) />

                        @if ($m->body !== '')
                            <div
                                title="{{ SocialFormat::stamp($m->createdAt) }}"
                                x-show="! editing"
                                x-on:click="tools = ! tools"
                                @class([
                                    'max-w-full rounded-2xl px-3.5 py-2 text-sm leading-relaxed',
                                    'bg-primary text-primary-foreground' => $mine,
                                    'bg-muted text-foreground' => ! $mine,
                                    'rounded-tr-md' => $mine && ! $row['first'],
                                    'rounded-br-md' => $mine && ! $row['last'],
                                    'rounded-tl-md' => ! $mine && ! $row['first'],
                                    'rounded-bl-md' => ! $mine && ! $row['last'],
                                ])
                            >
                                <x-social.text
                                    :body="$m->body"
                                    :names="$names"
                                    :mention="$mine ? 'font-semibold underline underline-offset-2' : 'font-semibold text-primary'"
                                />
                            </div>
                        @endif

                        @if ($mine)
                            <form x-show="editing" x-cloak wire:submit="saveEdit('{{ $m->id }}')" class="w-72 max-w-full space-y-1">
                                <div class="relative" @if ($active->isGroup()) x-data="mentionPicker({ audience: @js($active->id) })" @endif>
                                    {{-- wire:ignore.self keeps the height the box grew to when the thread redraws. --}}
                                    <textarea
                                        data-message-edit
                                        wire:ignore.self
                                        wire:model="edits.{{ $m->id }}"
                                        x-on:input="$el.style.height = 'auto'; $el.style.height = $el.scrollHeight + 'px'"
                                        x-on:keydown.enter="if (! $event.shiftKey && ! $event.isComposing) { $event.preventDefault(); $wire.saveEdit(@js($m->id)) }"
                                        x-on:keydown.escape="editing = false"
                                        rows="1"
                                        maxlength="{{ $maxBody }}"
                                        data-paste-limit="A message"
                                        aria-label="Edit your message"
                                        class="block max-h-48 w-full resize-none rounded-2xl bg-muted px-3.5 py-2 text-sm leading-relaxed outline-hidden focus:ring-2 focus:ring-ring"
                                    ></textarea>
                                    @if ($active->isGroup())
                                        <x-social.mention-list />
                                    @endif
                                </div>
                                <div class="flex justify-end gap-2 px-2 text-xs">
                                    <button type="button" x-on:click="editing = false" class="cursor-pointer font-semibold text-muted-foreground hover:underline">Cancel</button>
                                    <button type="submit" class="cursor-pointer font-semibold text-primary hover:underline">Save</button>
                                </div>
                            </form>
                        @endif
                    </div>

                    {{-- Floating, so they take no room: beside the bubble, or above it on a phone. --}}
                    <div
                        @class([
                            'pointer-events-none absolute bottom-full z-20 mb-1 flex items-center rounded-full border border-border bg-popover px-1 opacity-0 shadow-lift transition-opacity',
                            'group-hover/message:pointer-events-auto group-hover/message:opacity-100 focus-within:pointer-events-auto focus-within:opacity-100 data-tools:pointer-events-auto data-tools:opacity-100',
                            'sm:bottom-auto sm:top-1/2 sm:mb-0 sm:-translate-y-1/2 sm:rounded-none sm:border-0 sm:bg-transparent sm:px-0 sm:shadow-none',
                            'right-0 sm:right-full sm:mr-1' => $mine,
                            'left-0 sm:left-full sm:ml-1' => ! $mine,
                        ])
                        x-bind:data-tools="tools"
                        x-show="! editing"
                        x-data="{ pick: false }"
                        x-on:keydown.escape="pick = false"
                    >
                        <time
                            datetime="{{ $m->createdAt->toIso8601String() }}"
                            title="{{ SocialFormat::stamp($m->createdAt) }}"
                            @class(['whitespace-nowrap px-1.5 text-[0.7rem] text-muted-foreground', 'order-last' => $mine])
                        >{{ SocialFormat::clock($m->createdAt) }}</time>
                        <button type="button" x-on:click="pick = ! pick" class="{{ $toolButton }}" aria-label="React" title="React">
                            <flux:icon.face-smile class="size-4" />
                        </button>
                        <button type="button" wire:click="startReply('{{ $m->id }}')" class="{{ $toolButton }}" aria-label="Reply" title="Reply">
                            <flux:icon.arrow-uturn-left class="size-4" />
                        </button>
                        @if ($mine)
                            @php
                                $askRemove = [
                                    'title' => 'Remove this message for everyone?',
                                    'body' => 'Everyone sees a note in its place saying you removed a message.'.($photoCount > 0 || $m->files !== [] ? ' Its photos and files go too.' : ''),
                                    'confirm' => 'Remove',
                                    'danger' => true,
                                    'call' => ['removeMessage', $m->id],
                                ];
                            @endphp
                            <button type="button" x-on:click="$dispatch('message-edit')" class="{{ $toolButton }}" aria-label="Edit" title="Edit">
                                <flux:icon.pencil-square class="size-4" />
                            </button>
                            <button
                                type="button"
                                x-on:click="$dispatch('social-confirm', @js($askRemove))"
                                class="{{ $toolButton }}"
                                aria-label="Remove"
                                title="Remove"
                            >
                                <flux:icon.trash class="size-4" />
                            </button>
                        @endif

                        {{-- Grows out of the React button, the same way as on a post (components/social/react-button). --}}
                        <div
                            x-show="pick"
                            x-cloak
                            x-on:click.outside="pick = false"
                            x-transition:enter="transition-[opacity,translate,scale] duration-200 ease-[cubic-bezier(0.23,1,0.32,1)]"
                            x-transition:enter-start="translate-y-1 scale-95 opacity-0 motion-reduce:translate-y-0 motion-reduce:scale-100"
                            x-transition:enter-end="translate-y-0 scale-100 opacity-100"
                            x-transition:leave="transition-[opacity,translate,scale] duration-150 ease-[cubic-bezier(0.23,1,0.32,1)]"
                            x-transition:leave-start="translate-y-0 scale-100 opacity-100"
                            x-transition:leave-end="translate-y-1 scale-95 opacity-0 motion-reduce:translate-y-0 motion-reduce:scale-100"
                            @class([
                                'absolute bottom-full z-20 mb-1 flex gap-0.5 rounded-full border border-border bg-popover p-1 shadow-lift',
                                'right-0 origin-bottom-right' => $mine,
                                'left-0 origin-bottom-left' => ! $mine,
                            ])
                            role="group"
                            aria-label="Choose a reaction"
                        >
                            @foreach (SocialFormat::REACTIONS as $type => $reaction)
                                <span
                                    class="flex transition-[opacity,translate] duration-150 ease-[cubic-bezier(0.23,1,0.32,1)] starting:translate-y-1.5 starting:opacity-0 motion-reduce:starting:translate-y-0"
                                    style="transition-delay: {{ $loop->index * 30 }}ms"
                                >
                                    <button
                                        type="button"
                                        wire:click="react('{{ $m->id }}', '{{ $type }}')"
                                        x-on:click="pick = false"
                                        title="{{ $reaction['label'] }}"
                                        aria-label="{{ $reaction['label'] }}"
                                        @class([
                                            'flex size-8 cursor-pointer items-center justify-center rounded-full text-lg transition-transform duration-150 hover:-translate-y-0.5 hover:scale-125',
                                            'bg-primary-soft' => $m->reactedWith($viewer->id, $type),
                                        ])
                                    >{{ $reaction['emoji'] }}</button>
                                </span>
                            @endforeach
                        </div>
                    </div>

                    @if ($reactionCount > 0)
                        {{-- Out of the way while editing: it would sit on the Save button. --}}
                        <span @class(['absolute -bottom-3.5 z-10', 'right-3' => $mine, 'left-3' => ! $mine]) x-show="! editing">
                            <x-social.who
                                :reactions="$m->reactions"
                                :people="$people"
                                :me="$viewer->id"
                                :align="$mine ? 'end' : 'start'"
                                class="flex items-center gap-0.5 rounded-full border border-border bg-card px-1.5 py-0.5 text-xs shadow-sm transition-colors hover:bg-accent"
                                aria-label="{{ $reactionCount }} {{ $reactionCount === 1 ? 'reaction' : 'reactions' }}. Show who reacted"
                            >
                                @foreach (SocialFormat::topReactions(array_map('count', $m->reactions)) as $type)
                                    <span aria-hidden="true">{{ SocialFormat::REACTIONS[$type]['emoji'] ?? '' }}</span>
                                @endforeach
                                @if ($reactionCount > 1)
                                    <span class="num text-muted-foreground">{{ $reactionCount }}</span>
                                @endif
                            </x-social.who>
                        </span>
                    @endif
                @endif
            </div>

        </div>
    </div>
@endif
