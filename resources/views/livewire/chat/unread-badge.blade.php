{{--
    The unread count beside Chat in the sidebar. It checks every 60 seconds,
    the standard poll (App\Livewire\Chat\UnreadBadge says why). Hidden when the
    sidebar collapses to icons, where there is no room for a number.
--}}
<span class="ml-auto shrink-0 sidebar-rail:hidden" wire:poll.visible.60s="pollForChanges">
    @if ($unread > 0)
        <span class="num flex h-5 min-w-5 items-center justify-center rounded-full bg-primary px-1.5 text-[0.65rem] font-semibold text-primary-foreground" aria-label="{{ $unread }} unread {{ $unread === 1 ? 'message' : 'messages' }}">
            {{ $unread > 99 ? '99+' : $unread }}
        </span>
    @endif
</span>
