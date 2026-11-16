{{--
    The Parents card's body, read or edit: the father, then the mother under
    her maiden name.

    Expects:
        $fields           the section's fields (EmployeeFields::section('parents'))
        $employee         the record (read mode)
        $editing          bool
        $formState        the Livewire form array (edit mode)
        $fieldErrors      field-keyed messages
        $headingTag       'h3' on the record page, 'h4' in the wizard, whose
                          step title is already an h3
        $referenceOptions, $sites — forwarded to partials/field

    Read mode shows each parent as one line, surname first
    (EmployeeFields::fatherText / motherText), or "—" while no part is typed.
    Edit mode groups the parts under a heading per parent; every input
    carries its group in its accessible name ("Father's surname", "Mother's
    maiden surname"), because both groups share the visible labels.
--}}
@php
    use App\Livewire\Employees\EmployeeFields;

    $groups = [
        'father' => 'Father',
        'mother' => "Mother's Maiden Name",
    ];
@endphp
@if (! $editing)
    <dl class="mt-4 grid gap-4 sm:grid-cols-2">
        <div class="min-w-0">
            <dt class="text-xs font-medium uppercase tracking-wide text-muted-foreground">Father</dt>
            <dd class="mt-1 break-words text-sm">{{ EmployeeFields::fatherText($employee) ?: '—' }}</dd>
        </div>
        <div class="min-w-0">
            <dt class="text-xs font-medium uppercase tracking-wide text-muted-foreground">Mother's Maiden Name</dt>
            <dd class="mt-1 break-words text-sm">{{ EmployeeFields::motherText($employee) ?: '—' }}</dd>
        </div>
    </dl>
@else
    <div class="mt-4 space-y-5">
        @foreach ($groups as $group => $heading)
            <section class="space-y-3">
                <{{ $headingTag }} class="text-sm font-semibold">{{ $heading }}</{{ $headingTag }}>
                <div class="grid gap-4 sm:grid-cols-2">
                    @foreach ($fields as $field)
                        @continue(($field['group'] ?? null) !== $group)
                        <div wire:key="parent-{{ $field['key'] }}" class="contents">
                            @include('livewire.employees.partials.field', [
                                'field' => $field,
                                'editing' => true,
                                'display' => '',
                                'formState' => $formState,
                                'referenceOptions' => $referenceOptions,
                                'sites' => $sites,
                                'error' => $fieldErrors[$field['key']] ?? null,
                                'required' => false,
                                'legacy' => '',
                            ])
                        </div>
                    @endforeach
                </div>
            </section>
        @endforeach
    </div>
@endif
