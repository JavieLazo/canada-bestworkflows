{{--
    The review dialog of a schedule change, shared by Shift Roster and
    Shifting Settings.

    Expects the component to hold `$review` (ScheduleReview::lines) and a
    `confirmReview` action that saves with the token of what is shown here.
    Optional `$backAction`: the action Go back calls, for a screen that
    returns to its editor instead of only closing this dialog.
--}}
<flux:modal
    name="schedule-review"
    class="w-full max-w-lg"
    x-init="$el.querySelector('dialog')?.setAttribute('aria-labelledby', 'schedule-review-heading')"
>
    <div class="space-y-4">
        <div>
            <flux:heading size="lg" id="schedule-review-heading">Review This Change</flux:heading>
            <flux:text class="mt-1">Nothing has been saved yet. Check what this change affects, then confirm it.</flux:text>
        </div>

        @if ($review['takes_effect'] !== '')
            <div>
                <p class="text-xs font-medium uppercase tracking-wide text-muted-foreground">Takes Effect</p>
                <p class="num mt-1 text-sm font-medium">{{ $review['takes_effect'] }}</p>
                @if ($review['moved'] !== '')
                    <p class="mt-1 text-sm text-warning">{{ $review['moved'] }}</p>
                @endif
            </div>
        @endif

        @if ($review['conflicts'] !== [])
            <div>
                <p class="text-xs font-medium uppercase tracking-wide text-muted-foreground">Overlaps a saved temporary schedule</p>
                <ul class="mt-1 space-y-1 text-sm">
                    @foreach ($review['conflicts'] as $line)
                        <li class="rounded-lg border border-border px-3 py-2">{{ $line }}</li>
                    @endforeach
                </ul>
                <p class="mt-1 text-sm text-warning">Confirming replaces these on the shared dates only. Each keeps its other dates. To keep them as they are, go back and choose other dates.</p>
            </div>
        @endif

        @if ($review['rest_days'] !== [])
            <div>
                <p class="text-xs font-medium uppercase tracking-wide text-muted-foreground">Dates inside a temporary schedule that change</p>
                <ul class="mt-1 list-inside list-disc text-sm">
                    @foreach ($review['rest_days'] as $line)
                        <li>{{ $line }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        @if ($review['drafts'] !== [])
            <div>
                <p class="text-xs font-medium uppercase tracking-wide text-muted-foreground">Payroll drafts that need a new preview</p>
                <ul class="mt-1 list-inside list-disc text-sm">
                    @foreach ($review['drafts'] as $line)
                        <li>{{ $line }}</li>
                    @endforeach
                </ul>
                <p class="mt-1 text-sm text-muted-foreground">A monthly rate is divided by the whole month’s working days, so amounts in these drafts can change. Finalized payroll is not affected.</p>
            </div>
        @endif

        <div class="flex items-center justify-end gap-2">
            @if (($backAction ?? null) !== null)
                <flux:button variant="outline" wire:click="{{ $backAction }}">Go Back</flux:button>
            @else
                <flux:modal.close>
                    <flux:button variant="outline">Go Back</flux:button>
                </flux:modal.close>
            @endif
            <flux:button variant="primary" wire:click="confirmReview" wire:loading.attr="disabled" wire:target="confirmReview">Confirm and Save</flux:button>
        </div>
    </div>
</flux:modal>
