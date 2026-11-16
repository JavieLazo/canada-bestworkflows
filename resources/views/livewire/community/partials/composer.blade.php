{{--
    The post box: the viewer's avatar beside a borderless box that grows as
    it is typed into, what's waiting to go with it (photos and files), and
    one action bar: add photos, add files, the tag, who sees it, and Post.

    The tag and the audience are small pills that open a menu, so they read
    as settings on the post rather than a form, and a long group name ends
    in an ellipsis instead of being cut off. Typing @ opens the mention list
    (resources/js/mention-picker.js). Cmd/Ctrl+Enter posts.

    Reads: $viewer, $audience, $tag, $tags, $tagsByCode, $joinedGroups,
    $groupNames, $staged, $maxAttachments, $maxBytes, $maxBody.
--}}
@use('App\Livewire\Community\Support\CommunityLabels')
@use('App\Modules\Social\Application\Attachments')
@php
    $audienceName = $audience === 'everyone' ? 'Everyone' : ($groupNames[$audience] ?? 'Everyone');
    $picked = $tag !== '' ? ($tagsByCode[$tag] ?? null) : null;
    $held = count($staged['post'] ?? []);
    $pill = 'inline-flex h-8 min-w-0 max-w-[11rem] cursor-pointer items-center gap-1.5 rounded-full border border-border px-3 text-xs font-medium transition-colors hover:bg-accent';
    $accept = '.'.implode(',.', Attachments::FILE_EXTENSIONS);
@endphp

<form
    wire:submit="publish"
    class="hris-card p-4"
    x-data="{
        grow(el) { if (! el) return; el.style.height = 'auto'; el.style.height = el.scrollHeight + 'px' },
    }"
    x-on:community-compose.window="$nextTick(() => { const box = $root.querySelector('textarea'); grow(box); box?.focus(); box?.scrollIntoView({ block: 'center', behavior: 'smooth' }) })"
>
    <div class="flex gap-3">
        <x-social.avatar :name="$viewer->name" :photo="$viewer->photo" />
        <div class="relative min-w-0 flex-1" x-data="mentionPicker({ audience: () => $wire.audience })">
            <label class="sr-only" for="community-box">Write a Post</label>
            <textarea
                id="community-box"
                x-on:input="grow($el)"
                x-on:keydown.meta.enter.prevent="$wire.publish()"
                x-on:keydown.ctrl.enter.prevent="$wire.publish()"
                wire:model="draft"
                rows="2"
                maxlength="{{ $maxBody }}"
                data-paste-limit="A post"
                placeholder="Share something with {{ $audienceName }}…"
                class="block max-h-72 min-h-11 w-full resize-none bg-transparent py-2 text-sm leading-relaxed outline-hidden placeholder:text-muted-foreground"
            ></textarea>
            <x-social.mention-list />
        </div>
    </div>

    <x-social.staged draft="post" :files="$staged['post'] ?? []" class="mt-3 sm:ml-13" />

    <div class="mt-3 flex flex-wrap items-center gap-1.5 border-t border-border pt-3">
        {{-- attachmentPicker (resources/js/attachment-picker.js) checks what was picked and shrinks photos before they upload. --}}
        <div class="flex items-center" x-data="attachmentPicker({ maxBytes: @js($maxBytes), slot: 'post', max: @js($maxAttachments) })">
            <input x-ref="photos" type="file" accept="image/*" multiple class="sr-only" tabindex="-1" aria-hidden="true" x-on:change="pick($event)" />
            <input x-ref="files" type="file" accept="{{ $accept }}" multiple class="sr-only" tabindex="-1" aria-hidden="true" x-on:change="pick($event)" />
            <flux:tooltip content="Add photos">
                <flux:button type="button" size="sm" variant="ghost" icon="photo" icon:variant="outline" x-on:click="$refs.photos.click()" x-bind:disabled="busy" :disabled="$held >= $maxAttachments" aria-label="Add photos" />
            </flux:tooltip>
            <flux:tooltip content="Add files">
                <flux:button type="button" size="sm" variant="ghost" icon="paper-clip" icon:variant="outline" x-on:click="$refs.files.click()" x-bind:disabled="busy" :disabled="$held >= $maxAttachments" aria-label="Add files" />
            </flux:tooltip>
            <span x-show="busy" x-cloak class="px-1 text-xs text-muted-foreground">Uploading…</span>
        </div>

        <flux:dropdown position="top" align="start">
            <button type="button" class="{{ $pill }} {{ $picked !== null ? '' : 'text-muted-foreground' }}" aria-label="Tag: {{ $picked->name ?? 'none' }}">
                @if ($picked !== null)
                    <span class="size-2 shrink-0 rounded-full {{ CommunityLabels::dot($picked->tone) }}" aria-hidden="true"></span>
                    <span class="truncate">{{ $picked->name }}</span>
                @else
                    <flux:icon.tag class="size-3.5 shrink-0" />
                    <span class="truncate">Add a Tag</span>
                @endif
                <flux:icon.chevron-down class="size-3 shrink-0 text-muted-foreground" />
            </button>
            <flux:menu class="max-h-72 overflow-y-auto">
                <flux:menu.item wire:click="$set('tag', '')" icon="x-mark">No Tag</flux:menu.item>
                <flux:menu.separator />
                @foreach ($tags as $t)
                    <flux:menu.item wire:click="$set('tag', '{{ $t->code }}')">
                        <span class="flex min-w-0 items-center gap-2">
                            <span class="size-2 shrink-0 rounded-full {{ CommunityLabels::dot($t->tone) }}" aria-hidden="true"></span>
                            <span class="truncate">{{ $t->name }}</span>
                        </span>
                    </flux:menu.item>
                @endforeach
            </flux:menu>
        </flux:dropdown>

        <flux:dropdown position="top" align="start">
            <button type="button" class="{{ $pill }}" aria-label="Who can see this: {{ $audienceName }}">
                @if ($audience === 'everyone')
                    <flux:icon.globe-asia-australia class="size-3.5 shrink-0 text-muted-foreground" />
                @else
                    <flux:icon.user-group class="size-3.5 shrink-0 text-muted-foreground" />
                @endif
                <span class="truncate">{{ $audienceName }}</span>
                <flux:icon.chevron-down class="size-3 shrink-0 text-muted-foreground" />
            </button>
            <flux:menu class="max-h-72 max-w-72 overflow-y-auto">
                <flux:menu.heading>Who Can See This</flux:menu.heading>
                <flux:menu.item wire:click="$set('audience', 'everyone')" icon="globe-asia-australia">Everyone at the Company</flux:menu.item>
                @foreach ($joinedGroups as $group)
                    <flux:menu.item wire:click="$set('audience', '{{ $group->id }}')" icon="user-group">
                        <span class="truncate">{{ $group->name }}</span>
                    </flux:menu.item>
                @endforeach
            </flux:menu>
        </flux:dropdown>

        <flux:button type="submit" size="sm" variant="primary" icon="paper-airplane" class="ml-auto" wire:loading.attr="disabled" wire:target="publish">
            Post
        </flux:button>
    </div>
</form>
