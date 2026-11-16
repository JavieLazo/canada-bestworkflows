{{--
    The Addresses section's body, read or edit: the residential address in
    parts, then whether the permanent address is the same, then the
    permanent parts while it is not.

    Expects:
        $fields           the section's fields (EmployeeFields::section('addresses'))
        $editing          bool
        $formState        the Livewire form array (edit mode)
        $fieldErrors      field-keyed messages, plus `residential_address` /
                          `permanent_address` for the group rules
        $addressText      ['residential' => …, 'permanent' => …], the read
                          lines ('—' when blank)
        $legacyAddress    the stored one-line address while no residential
                          part is typed, else ''
        $groupRequired    bool — asterisk on the Residential address heading
                          (the hire requires one part)
        $headingTag       'h3' on the record page, 'h4' in the wizard, whose
                          step title is already an h3
        $referenceOptions, $sites — forwarded to partials/field

    Read mode shows each address as one line, from its typed parts alone:
    an untyped residential address reads "—", and the stored one-line
    address appears once, in the "Entered before" note under the card, until
    the address is typed in parts. Edit mode repeats that line in its note,
    because the part boxes start empty. It is never written: nothing here
    edits it. Each part's input carries its group in its accessible name
    ("Residential street", "Permanent ZIP code"), because both groups share
    the visible labels.
--}}
@php
    use App\Livewire\Employees\EmployeeFields;

    $residential = array_values(array_filter($fields, fn (array $field): bool => ($field['address'] ?? null) === 'RESIDENTIAL'));
    $permanent = array_values(array_filter($fields, fn (array $field): bool => ($field['address'] ?? null) === 'PERMANENT'));
    $same = EmployeeFields::field('permanent_address_same');
@endphp
@if (! $editing)
    <dl class="mt-4 grid gap-4 sm:grid-cols-2">
        <div class="min-w-0 sm:col-span-2">
            <dt class="text-xs font-medium uppercase tracking-wide text-muted-foreground">Residential Address</dt>
            <dd class="mt-1 break-words text-sm">{{ $addressText['residential'] }}</dd>
        </div>
        <div class="min-w-0 sm:col-span-2">
            <dt class="text-xs font-medium uppercase tracking-wide text-muted-foreground">Permanent Address</dt>
            <dd class="mt-1 break-words text-sm">{{ $addressText['permanent'] }}</dd>
        </div>
    </dl>
    @if ($legacyAddress !== '')
        <p class="mt-4 text-sm text-muted-foreground">Entered before: {{ $legacyAddress }} — shown until the address is typed in parts.</p>
    @endif
@else
    <div class="mt-4 space-y-5">
        <section class="space-y-3">
            <{{ $headingTag }} class="text-sm font-semibold">
                Residential Address
                @if ($groupRequired)
                    <span aria-hidden="true" class="ml-0.5 text-danger">*</span>
                    <span class="sr-only">(required)</span>
                @endif
            </{{ $headingTag }}>
            @if ($legacyAddress !== '')
                <p class="text-xs text-muted-foreground">Entered before: {{ $legacyAddress }} — shown until the address is typed in parts.</p>
            @endif
            <div class="grid gap-4 sm:grid-cols-2">
                @foreach ($residential as $field)
                    <div wire:key="addr-{{ $field['key'] }}" class="contents">
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
            @if (! empty($fieldErrors['residential_address']))
                <p class="text-xs text-danger">{{ $fieldErrors['residential_address'] }}</p>
            @endif
        </section>

        <section class="space-y-3">
            <{{ $headingTag }} class="text-sm font-semibold">Permanent Address</{{ $headingTag }}>
            <div class="grid gap-4 sm:grid-cols-2">
                @if ($same !== null)
                    <div wire:key="addr-{{ $same['key'] }}" class="contents">
                        @include('livewire.employees.partials.field', [
                            'field' => $same,
                            'editing' => true,
                            'display' => '',
                            'formState' => $formState,
                            'referenceOptions' => $referenceOptions,
                            'sites' => $sites,
                            'error' => $fieldErrors[$same['key']] ?? null,
                            'required' => false,
                            'legacy' => '',
                        ])
                    </div>
                @endif
            </div>
            @if ($permanent !== [] && EmployeeFields::shown($permanent[0], $formState))
                <div class="grid gap-4 sm:grid-cols-2">
                    @foreach ($permanent as $field)
                        <div wire:key="addr-{{ $field['key'] }}" class="contents">
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
                @if (! empty($fieldErrors['permanent_address']))
                    <p class="text-xs text-danger">{{ $fieldErrors['permanent_address'] }}</p>
                @endif
            @endif
        </section>
    </div>
@endif
