{{--
    A post, comment or message body: its own line breaks kept, and every
    @mention token drawn as a name: a person's, a group's, or @everyone. Each
    run prints through {{ }}, so a body can never inject markup
    (SocialFormat::segments()).

    Pass `groups` (group id => name) where groups can be mentioned. With
    `openGroup`, a group's name is a button that opens the group.

    The loop sits on one line on purpose: under `whitespace-pre-line`, a line
    break between two runs in this file would print as a line break in the
    body.

    Usage:
        <x-social.text :body="$post->body" :names="$names" :groups="$groupNames" open-group />
        <x-social.text :body="$message->body" :names="$names" mention="font-semibold underline" />
--}}
@props([
    'body',
    'names',
    'groups' => [],
    'openGroup' => false,
    'mention' => 'font-semibold text-primary',
])
<span {{ $attributes->class('whitespace-pre-line break-words') }}>@foreach (\App\Support\SocialFormat::segments($body, $names, $groups) as $segment)@if ($segment['kind'] === 'group' && $openGroup && isset($groups[$segment['key'] ?? '']))<button type="button" wire:click="openGroup('{{ $segment['key'] }}')" class="{{ $mention }} cursor-pointer hover:underline">{{ $segment['text'] }}</button>@else<span @class([$mention => $segment['kind'] !== 'text'])>{{ $segment['text'] }}</span>@endif{{-- --}}@endforeach</span>
