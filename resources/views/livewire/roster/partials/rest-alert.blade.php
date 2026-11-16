{{--
    The short-rest warning at a day's top right: a red alert icon that opens
    a note saying which two shifts leave less than eight hours between them
    and what to do. A warning only — the schedule may still be saved as it
    is.

    The note is a Flux dropdown with a plain panel, the mechanism Flux's own
    toggleable tooltip uses: a native popover in the top layer, so it
    escapes the table's scrollport and the card's overflow, and Escape or a
    click outside closes it. A click on the icon never opens the row or picks
    the date under it: both handlers skip buttons and popovers.

    Expects $note (RosterDayText::restNote) and $name (the employee's name).
--}}
<flux:dropdown position="bottom" align="end" class="absolute right-1.5 top-1.5">
    <button
        type="button"
        class="inline-flex cursor-pointer rounded p-0.5 text-danger hover:bg-danger-soft focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
        aria-label="Why is this flagged?"
        title="Why is this flagged?"
        data-rest-alert
    >
        <flux:icon.exclamation-triangle variant="micro" />
    </button>
    <div
        popover="manual"
        role="dialog"
        aria-label="Rest warning"
        class="m-0 w-80 max-w-[calc(100vw-2rem)] space-y-2 whitespace-normal rounded-lg border border-border bg-popover p-3 text-left text-sm font-normal text-popover-foreground shadow-lift"
    >
        <p class="flex items-center gap-1.5 font-semibold text-danger">
            <flux:icon.exclamation-triangle variant="micro" />
            Less than 8 hours of rest
        </p>
        <p>
            {{ $name }} works <b>{{ $note['previous'] }}</b> on {{ $note['previous_date'] }}, which ends at {{ $note['ends'] }},
            then <b>{{ $note['label'] }}</b> on {{ $note['date'] }}, which starts at {{ $note['starts'] }}:
            <b>{{ $note['rest'] }}</b> of rest between them.
        </p>
        <p class="text-muted-foreground">Move one of the two shifts, or give a day off in between. You can still save it as it is.</p>
        <flux:button size="sm" variant="outline" x-on:click="$el.closest('[popover]').hidePopover()">Got It</flux:button>
    </div>
</flux:dropdown>
