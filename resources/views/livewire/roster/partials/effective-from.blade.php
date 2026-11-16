{{--
    The Effective from field of a schedule dialog.

    Expects:
        $model    the Livewire property holding the draft date ('' = earliest)
        $fieldId  the input's id
    Optional:
        $hint     false to leave out the note under the field (the Shift
                  Roster's Default schedule); Shifting Settings keeps it

    A draft, like every date on this screen: the command refuses a past date
    and decides whether today is still possible.
--}}
<div class="space-y-1 sm:col-span-2">
    <label for="{{ $fieldId }}" class="text-sm font-medium">Effective From</label>
    <x-kit.date-field :id="$fieldId" class="max-w-[12rem]" wire:model="{{ $model }}" aria-label="Effective from" />
    @if ($hint ?? true)
        <p class="text-xs text-muted-foreground">
            Today or a later date. Leave blank for the earliest date: today, or the next date once today’s shift has started.
        </p>
    @endif
</div>
