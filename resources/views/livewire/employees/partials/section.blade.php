{{--
    One record section, read or edit — the port of `SectionBlock`
    (employee-detail.tsx:400-494).

    Expects: $section, $employee, $editing (bool), $columns (1|2), $display
    (key → read text), $formState, $formErrors, $referenceOptions, $sites,
    $legacy (['address' => …, 'citizenship' => …, 'spouse' => …]: the stored
    one-line address, citizenship text and spouse while nothing structured
    replaces them, else ''), $addressText (['residential' => …,
    'permanent' => …]: the Addresses card's read lines), $canEdit and the
    server-derived $editableSections / $readOnlyFields permissions, and $positionChoices
    (PositionPicker::options(), while Employee Details is being edited).

    The header is partials/section-header; the Declarations card hides its
    title there and leads with its description. The Addresses section is
    drawn by partials/addresses, Parents by partials/parents (one composed
    line per parent) and Declarations by partials/declarations. Elsewhere a
    field that waits on another answer (`when`: Specify, the dual
    citizenship parts) has no read row of its own — its parent's read text
    carries it — and is drawn while editing only while that answer is
    picked. Position draws Department, Unit and Position
    (partials/position-fields). The Spouse card names the stored Spouse's Name and Employment
    under its fields while no spouse part is typed.
--}}
@php
    use App\Livewire\Employees\EmployeeFields;
    $canEdit = ($canEdit ?? false) && ($editableSections[$section['id']] ?? false);
    $editing = $editing && $canEdit;
@endphp
<div class="p-5">
    @include('livewire.employees.partials.section-header', [
        'section' => $section,
        'editing' => $editing,
        'canEdit' => $canEdit ?? false,
        'titleHidden' => $section['id'] === 'declarations',
    ])

    @if ($editing && $section['id'] === 'employment' && $employee->is_active && ! EmployeeFields::asBool($formState['is_active'] ?? true) && ($employmentObligations ?? []) !== [])
        <section class="mt-4 space-y-3 rounded-lg border border-border bg-muted p-4" aria-label="Outstanding issued items">
            <p class="font-semibold">{{ count($employmentObligations) }} issued items remain</p>
            <p class="text-sm">Making this employee inactive keeps every item and MR on record. Account access is managed separately in User Management.</p>
            <ul class="space-y-1 text-sm">
                @foreach ($employmentObligations as $obligation)
                    <li>{{ $obligation['label'] }} · {{ $obligation['reference'] }}</li>
                @endforeach
            </ul>
            <flux:checkbox wire:model="acknowledgeObligations" label="I acknowledge that these items remain issued and require follow-up." />
            <flux:textarea wire:model="obligationReason" label="Reason for continuing" rows="2" maxlength="1000" />
        </section>
    @endif

    @if ($section['id'] === 'addresses')
        @include('livewire.employees.partials.addresses', [
            'fields' => $section['fields'],
            'editing' => $editing,
            'formState' => $formState,
            'fieldErrors' => $editing ? $formErrors : [],
            'addressText' => $addressText,
            'legacyAddress' => $legacy['address'] ?? '',
            'groupRequired' => false,
            'headingTag' => 'h3',
            'referenceOptions' => $referenceOptions,
            'sites' => $sites,
        ])
    @elseif ($section['id'] === 'parents')
        @include('livewire.employees.partials.parents', [
            'fields' => $section['fields'],
            'employee' => $employee,
            'editing' => $editing,
            'formState' => $formState,
            'fieldErrors' => $editing ? $formErrors : [],
            'headingTag' => 'h3',
            'referenceOptions' => $referenceOptions,
            'sites' => $sites,
        ])
    @elseif ($section['id'] === 'declarations')
        @include('livewire.employees.partials.declarations', [
            'section' => $section,
            'employee' => $employee,
            'editing' => $editing,
            'formState' => $formState,
            'fieldErrors' => $editing ? $formErrors : [],
            'headingTag' => 'h3',
            'referenceOptions' => $referenceOptions,
            'sites' => $sites,
        ])
    @else
        <dl class="mt-4 grid gap-4 {{ ($columns ?? 2) === 2 ? 'sm:grid-cols-2' : '' }}">
            @foreach ($section['fields'] as $field)
                @continue(! $editing && isset($field['when']))
                @continue($editing && ! EmployeeFields::shown($field, $formState))
                @php $key = (string) $field['key']; @endphp
                @if (in_array($key, EmployeeFields::NAME_PARTS, true) && ! $editing)
                    {{-- Read view shows one "Full Name" row; editing splits it
                         back into the four parts the record stores (NAME_PARTS:
                         first, middle, last, suffix). --}}
                    @if ($key === 'first_name')
                        <div wire:key="{{ $section['id'] }}-full-name" class="min-w-0">
                            <dt class="text-xs font-medium uppercase tracking-wide text-muted-foreground">Full Name</dt>
                            <dd class="mt-1 break-words text-sm">{{ EmployeeFields::fullName($employee) }}</dd>
                        </div>
                    @endif
                @elseif (isset($field['choice']))
                    {{-- Position: Department | Unit, then Position (partials/position-fields). --}}
                    @include('livewire.employees.partials.position-fields', [
                        'prefix' => $section['id'],
                        'editing' => $editing,
                        'employee' => $employee,
                        'formState' => $formState,
                        'choices' => $positionChoices ?? null,
                        'keep' => $employee->position_unlisted ? (string) $employee->position : '',
                        'error' => $editing ? ($formErrors[$key] ?? null) : null,
                        'required' => false,
                    ])
                @elseif (isset($field['derived']))
                    <div wire:key="{{ $section['id'] }}-{{ $key }}" class="min-w-0">
                        <dt class="text-xs font-medium uppercase tracking-wide text-muted-foreground">{{ $field['label'] }}</dt>
                        <dd class="mt-1 break-words text-sm">{{ $display[$key] ?? '—' }}</dd>
                    </div>
                @else
                    <div wire:key="{{ $section['id'] }}-{{ $key }}" class="contents">
                        @include('livewire.employees.partials.field', [
                            'field' => $field,
                            'editing' => $editing && ! in_array($key, $readOnlyFields[$section['id']] ?? [], true),
                            'display' => $display[$key] ?? '—',
                            'formState' => $formState,
                            'referenceOptions' => $referenceOptions,
                            'sites' => $sites,
                            'error' => $editing ? ($formErrors[$key] ?? null) : null,
                            'required' => false,
                            'legacy' => isset($field['legacy']) ? ($legacy[$field['legacy']] ?? '') : '',
                        ])
                    </div>
                @endif
            @endforeach
        </dl>
        @if ($section['id'] === 'spouse' && ($legacy['spouse'] ?? '') !== '')
            <p class="mt-4 {{ $editing ? 'text-xs' : 'text-sm' }} text-muted-foreground">Entered before: {{ $legacy['spouse'] }} — shown until the spouse is typed in parts.</p>
        @endif
    @endif
</div>
