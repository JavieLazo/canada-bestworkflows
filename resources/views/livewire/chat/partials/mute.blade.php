{{--
    Muting a group chat asks what should still get through: nothing, or
    mentions (by name or @everyone). With mentions, those still ring the
    bell and count in the Chat badge, and nothing else does. A direct chat
    has no mentions, so it mutes without asking.

    Each choice is one click. Opened again while muted ("Change" in the
    details panel), the current choice is marked.

    Expects: $active (ConversationView, a group).
--}}
@php
    $option = 'flex w-full cursor-pointer items-start gap-3 rounded-lg border p-3 text-left transition-colors';
    $current = $active->muted ? ($active->allowMentions ? 'MENTIONS' : 'ALL') : null;
@endphp

<flux:modal wire:model.self="muteDialog" class="w-full max-w-md">
    <div class="space-y-4">
        <div class="space-y-1.5 pr-6">
            <h2 class="text-lg font-semibold leading-none tracking-tight">Mute {{ $active->title }}?</h2>
            <p class="text-sm text-muted-foreground">Pick what still reaches you. You can unmute any time.</p>
        </div>

        <div class="space-y-2" role="group" aria-label="How to mute">
            @foreach ([
                'ALL' => ['icon' => 'bell-slash', 'title' => 'Mute Everything', 'hint' => 'No notifications, and it stays out of your Chat badge.'],
                'MENTIONS' => ['icon' => 'at-symbol', 'title' => 'Allow Mentions', 'hint' => 'You still get a notification when someone mentions you or @everyone, and only those count in your Chat badge.'],
            ] as $mode => $choice)
                <button
                    type="button"
                    wire:click="mute('{{ $mode }}')"
                    aria-pressed="{{ $current === $mode ? 'true' : 'false' }}"
                    @class([
                        $option,
                        'border-primary bg-primary-soft' => $current === $mode,
                        'border-border hover:bg-accent' => $current !== $mode,
                    ])
                >
                    <flux:icon :name="$choice['icon']" class="mt-0.5 size-5 shrink-0 text-muted-foreground" />
                    <span class="min-w-0">
                        <span class="block text-sm font-semibold">{{ $choice['title'] }}</span>
                        <span class="mt-0.5 block text-sm text-muted-foreground">{{ $choice['hint'] }}</span>
                    </span>
                </button>
            @endforeach
        </div>

        <div class="flex justify-end">
            <flux:modal.close>
                <flux:button variant="ghost">Cancel</flux:button>
            </flux:modal.close>
        </div>
    </div>
</flux:modal>
