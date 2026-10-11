{{--
    One group in a list: its photo, name and size, and the one thing the
    viewer can do from here (open it, join it, or ask to). Used by the rail
    and by the full list of groups.

    Expects: $group (GroupView), $groupId (the open one, or '').
--}}
@php
    $current = $groupId === $group->id;
@endphp

<li wire:key="{{ $key ?? 'group' }}-{{ $group->id }}" @class(['flex items-center gap-2 rounded-md p-2 transition-colors', 'bg-accent text-accent-foreground' => $current, 'hover:bg-accent/60' => ! $current])>
    <button type="button" wire:click="openGroup('{{ $group->id }}')" class="flex min-w-0 flex-1 cursor-pointer items-center gap-3 text-left text-sm">
        <x-social.avatar :name="$group->name" :photo="$group->photo" icon="user-group" size="sm" />
        <span class="min-w-0 flex-1">
            <span @class(['block truncate', 'font-medium' => $current || ! $group->joined()])>{{ $group->name }}</span>
            <span class="flex items-center gap-1 text-xs text-muted-foreground">
                @if ($group->private)
                    <flux:icon.lock-closed class="size-3" />
                @endif
                <span class="num">{{ $group->members }}</span> {{ $group->members === 1 ? 'member' : 'members' }}
                @if ($group->canManage && $group->requests > 0)
                    <span aria-hidden="true">&middot;</span>
                    <span class="font-medium text-primary">{{ $group->requests }} waiting</span>
                @endif
            </span>
        </span>
    </button>
    @if (! $group->joined())
        @if ($group->requested())
            <span class="pill pill-muted">Requested</span>
        @else
            <flux:button size="xs" wire:click="join('{{ $group->id }}')">{{ $group->private ? 'Ask' : 'Join' }}</flux:button>
        @endif
    @endif
</li>
