{{--
    The Children card, read or edit: the header, then a table of the
    children (Full name, Date of birth) in sheet order, or — while editing —
    the rows of names and dates of birth through partials/field.

    Expects: the section partial's variables ($section, $employee, $editing,
    $formState, $formErrors, $referenceOptions, $sites, $canEdit). The
    caller wraps it in the card's <section wire:key="section-children">.

    The table sits outside the padded header so it runs edge to edge, like
    every table in a card. A child with no date of birth reads "—".
--}}
@php
    use App\Livewire\Employees\EmployeeFields;

    $childrenField = EmployeeFields::field('children');
@endphp
<div class="p-5">
    @include('livewire.employees.partials.section-header', [
        'section' => $section,
        'editing' => $editing,
        'canEdit' => $canEdit ?? false,
        'titleHidden' => false,
    ])

    @if ($editing && $childrenField !== null)
        <div class="mt-4">
            @include('livewire.employees.partials.field', [
                'field' => $childrenField,
                'editing' => true,
                'display' => '',
                'formState' => $formState,
                'referenceOptions' => $referenceOptions,
                'sites' => $sites,
                'error' => $formErrors['children'] ?? null,
                'required' => false,
                'legacy' => '',
            ])
        </div>
    @endif
</div>

@if (! $editing)
    <div class="hris-table-wrap hris-table-scroll">
        <table class="hris-table">
            <thead>
                <tr>
                    <th>Full Name</th>
                    <th>Date of Birth</th>
                </tr>
            </thead>
            <tbody>
                @forelse (EmployeeFields::childRows($employee) as $i => $child)
                    <tr wire:key="child-{{ $i }}">
                        <td class="font-medium">{{ $child['name'] }}</td>
                        <td class="num whitespace-nowrap">{{ EmployeeFields::dateText($child['birth_date']) ?: '—' }}</td>
                    </tr>
                @empty
                    <tr wire:key="empty"><x-kit.empty-state :colspan="2" title="No children listed." /></tr>
                @endforelse
            </tbody>
        </table>
    </div>
@endif
