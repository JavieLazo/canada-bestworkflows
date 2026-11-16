{{--
    The leave application's Details of leave and Commutation
    (LeaveApplication), for Leave Management's "File for an employee" dialog.
    My Portal draws the same choices on the paper-style form
    (leaves/partials/application-form). Drawn only for a leave, once the
    request keeps them.

    `$section` is the picked type's section (NONE asks no details; Special
    Leave Benefits for Women has its one choice, so only its Specify box
    shows); `$detail` is the current pick, which decides whether a Specify
    box shows; `$detailModel`, `$noteModel` and `$commutationModel` are the
    wire:model paths; `$idPrefix` keeps the ids of the two forms apart.
    FileAbsence checks every value again against the type.
--}}
@php
    use App\Modules\Timekeeping\Domain\Absence\LeaveApplication;

    $choices = LeaveApplication::CHOICES[$section] ?? [];
    $single = count($choices) === 1;
    $picked = $single ? array_key_first($choices) : ($detail === '' ? null : $detail);
    $specify = $picked === null ? null : (LeaveApplication::SPECIFY[$picked] ?? LeaveApplication::SPECIFY_OPTIONAL[$picked] ?? null);
@endphp
@if ($choices !== [])
    <fieldset class="space-y-3" data-leave-details="{{ $section }}">
        <legend class="text-sm font-medium">
            Details of Leave
            <span class="ml-1 text-xs font-normal text-muted-foreground">In Case of {{ LeaveApplication::SECTION_LABELS[$section] }}</span>
        </legend>
        @unless ($single)
            <flux:radio.group wire:model.live="{{ $detailModel }}" aria-label="Details of leave">
                @foreach ($choices as $value => $label)
                    <flux:radio value="{{ $value }}" :label="$label" />
                @endforeach
            </flux:radio.group>
        @endunless
        @if ($specify !== null)
            <flux:input id="{{ $idPrefix }}-leave-detail-note" wire:model="{{ $noteModel }}" :label="$specify" maxlength="{{ LeaveApplication::NOTE_MAX }}" data-leave-detail-note />
        @endif
    </fieldset>
@endif
<flux:radio.group wire:model="{{ $commutationModel }}" label="Commutation" data-commutation>
    @foreach (LeaveApplication::COMMUTATION_LABELS as $value => $label)
        <flux:radio value="{{ $value }}" :label="$label" />
    @endforeach
</flux:radio.group>
