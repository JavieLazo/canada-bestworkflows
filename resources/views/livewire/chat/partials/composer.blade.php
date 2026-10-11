{{--
    The message box on the thread's bottom edge: the reply being written (if
    any), what's waiting to go with it (photos and files), then the photo and
    file buttons, a box that grows with its text, and Send. Enter sends;
    Shift+Enter starts a new line; an IME composition (Japanese, Chinese
    input) is never cut short by its own Enter. In a group, typing @ opens
    the mention list, above the box.

    Expects: $active (ConversationView), $replyTo (MessageView|null), $names,
    $maxAttachments, $maxBytes, $maxBody; reads $staged.
--}}
@use('App\Modules\Social\Application\Attachments')
@use('App\Support\SocialFormat')
@php
    $draftKey = 'drafts.'.$active->id;
    $placeholder = $active->isGroup()
        ? 'Message '.$active->title.'…'
        : 'Message '.explode(' ', $active->title)[0].'…';
    $held = count($staged['chat'] ?? []);
    $accept = '.'.implode(',.', Attachments::FILE_EXTENSIONS);
@endphp

<form
    wire:submit="send"
    class="shrink-0 border-t border-border p-3"
    x-data="{
        grow(el) { if (! el) return; el.style.height = 'auto'; el.style.height = el.scrollHeight + 'px' },
    }"
    x-on:chat-compose.window="$nextTick(() => $root.querySelector('textarea')?.focus())"
    x-on:chat-sent.window="requestAnimationFrame(() => grow($root.querySelector('textarea')))"
>
    <div class="mx-auto max-w-3xl">
        @if ($replyTo !== null)
            <div class="mb-2 flex items-start gap-2 rounded-md border-l-2 border-primary bg-muted px-3 py-2">
                <flux:icon.arrow-uturn-left class="mt-0.5 size-3.5 shrink-0 text-primary" />
                <div class="min-w-0 flex-1 text-xs">
                    <p class="font-semibold">
                        Replying to {{ $replyTo->mine ? 'yourself' : ($replyTo->sender->name ?? 'Someone') }}
                    </p>
                    <p class="truncate text-muted-foreground">
                        {{ $replyTo->body !== '' ? SocialFormat::plain($replyTo->body, $names) : ($replyTo->photos !== [] ? 'Photo' : 'Attachment') }}
                    </p>
                </div>
                <button
                    type="button"
                    wire:click="cancelReply"
                    class="inline-flex size-6 cursor-pointer items-center justify-center rounded-full text-muted-foreground transition-colors hover:bg-accent hover:text-accent-foreground"
                    aria-label="Cancel reply"
                >
                    <flux:icon.x-mark class="size-3.5" />
                </button>
            </div>
        @endif

        <x-social.staged draft="chat" :files="$staged['chat'] ?? []" compact class="mb-2" />

        <div class="flex items-end gap-1.5">
            {{-- attachmentPicker (resources/js/attachment-picker.js) checks what was picked and shrinks photos before they upload. --}}
            <div class="flex shrink-0 items-center" x-data="attachmentPicker({ maxBytes: @js($maxBytes), slot: 'chat', max: @js($maxAttachments) })">
                <input x-ref="photos" type="file" accept="image/*" multiple class="sr-only" tabindex="-1" aria-hidden="true" x-on:change="pick($event)" />
                <input x-ref="files" type="file" accept="{{ $accept }}" multiple class="sr-only" tabindex="-1" aria-hidden="true" x-on:change="pick($event)" />
                <flux:tooltip content="Add photos">
                    <flux:button type="button" variant="ghost" icon="photo" icon:variant="outline" aria-label="Add photos" x-on:click="$refs.photos.click()" x-bind:disabled="busy" :disabled="$held >= $maxAttachments" />
                </flux:tooltip>
                <flux:tooltip content="Add files">
                    <flux:button type="button" variant="ghost" icon="paper-clip" icon:variant="outline" aria-label="Add files" x-on:click="$refs.files.click()" x-bind:disabled="busy" :disabled="$held >= $maxAttachments" />
                </flux:tooltip>
            </div>

            <div
                class="relative flex min-w-0 flex-1 items-end rounded-2xl bg-muted px-3.5 focus-within:ring-2 focus-within:ring-ring"
                @if ($active->isGroup()) x-data="mentionPicker({ audience: @js($active->id) })" @endif
            >
                <label class="sr-only" for="chat-box-{{ $active->id }}">{{ $placeholder }}</label>
                <textarea
                    id="chat-box-{{ $active->id }}"
                    x-init="grow($el)"
                    x-on:input="grow($el)"
                    x-on:keydown.enter="if (! $event.shiftKey && ! $event.isComposing) { $event.preventDefault(); $wire.send() }"
                    wire:model="{{ $draftKey }}"
                    rows="1"
                    maxlength="{{ $maxBody }}"
                    data-paste-limit="A message"
                    placeholder="{{ $placeholder }}"
                    class="block max-h-32 min-h-10 w-full resize-none bg-transparent py-2.5 text-sm leading-snug outline-hidden placeholder:text-muted-foreground"
                ></textarea>
                @if ($active->isGroup())
                    <x-social.mention-list above />
                @endif
            </div>

            <flux:button type="submit" variant="primary" icon="paper-airplane" aria-label="Send" wire:loading.attr="disabled" wire:target="send" />
        </div>
    </div>
</form>
