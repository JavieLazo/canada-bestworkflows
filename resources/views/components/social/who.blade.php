{{--
    A button that opens a short list of people. It answers "who reacted?"
    (with the emoji each person picked) and "who has seen this?", the same way
    on posts, comments and chat, so there's one thing to learn.

    It opens on click, not hover, so it works on a phone too. The list is
    teleported to the body and anchored to the button, so a scrolling chat
    thread can't clip it.

    Pass either `reactions` (reaction type => account ids) or `ids` (account
    ids), plus `people` (account id => PersonView) to draw them with. Pass
    `me` (the viewer's account id) and the viewer shows as "You", first.

    Usage:
        <x-social.who :reactions="$post->reactors" :people="$people" :me="$viewer->id" title="Reactions">
            <span>3</span>
        </x-social.who>
        <x-social.who :ids="$thread->seenBy" :people="$thread->people" title="Seen by">Seen by 3 people</x-social.who>
--}}
@use('App\Modules\Social\Contracts\Views\PersonView')
@use('App\Support\SocialFormat')
@props([
    'reactions' => null,
    'ids' => [],
    'people' => [],
    'title' => 'Reactions',
    'align' => 'start',
    'me' => null,
])
@php
    // One row per person. With reactions, each row carries that person's emoji.
    $rows = [];
    if ($reactions !== null) {
        foreach ($reactions as $type => $who) {
            foreach ($who as $id) {
                $rows[] = ['person' => $people[$id] ?? PersonView::missing($id), 'emoji' => SocialFormat::REACTIONS[$type]['emoji'] ?? null, 'label' => SocialFormat::REACTIONS[$type]['label'] ?? ''];
            }
        }
    } else {
        foreach ($ids as $id) {
            $rows[] = ['person' => $people[$id] ?? PersonView::missing($id), 'emoji' => null, 'label' => ''];
        }
    }
    // You first, then everyone else by name, whatever the letter case.
    usort($rows, static fn (array $a, array $b): int => [$a['person']->id !== $me, mb_strtolower($a['person']->name)] <=> [$b['person']->id !== $me, mb_strtolower($b['person']->name)]);
@endphp

<span class="relative inline-flex" x-data="{ open: false }" x-on:keydown.escape.window="open = false">
    <button
        type="button"
        x-ref="trigger"
        x-on:click="open = ! open"
        x-bind:aria-expanded="open"
        aria-haspopup="dialog"
        {{ $attributes->class('group/who cursor-pointer') }}
    >{{ $slot }}</button>

    <template x-teleport="body">
        <div
            x-show="open"
            x-cloak
            x-anchor.bottom-{{ $align }}.offset.6="$refs.trigger"
            x-on:click.outside="if (! $refs.trigger.contains($event.target)) open = false"
            x-transition.opacity.duration.100ms
            class="z-50 w-64 rounded-lg border border-border bg-popover p-1 text-popover-foreground shadow-lift"
            role="dialog"
            aria-label="{{ $title }}"
        >
            <p class="px-2 pb-1 pt-1.5 text-xs font-semibold text-muted-foreground">{{ $title }}</p>
            <ul class="max-h-64 overflow-y-auto">
                @foreach ($rows as $row)
                    <li class="flex items-center gap-2.5 rounded-md px-2 py-1.5 text-sm">
                        <x-social.avatar :name="$row['person']->name" :photo="$row['person']->photo" size="xs" />
                        <span class="min-w-0 flex-1 truncate">{{ $row['person']->id === $me ? 'You' : $row['person']->name }}</span>
                        @if ($row['emoji'] !== null)
                            <span class="text-base leading-none" aria-hidden="true">{{ $row['emoji'] }}</span>
                            <span class="sr-only">{{ $row['label'] }}</span>
                        @endif
                    </li>
                @endforeach
            </ul>
        </div>
    </template>
</span>
