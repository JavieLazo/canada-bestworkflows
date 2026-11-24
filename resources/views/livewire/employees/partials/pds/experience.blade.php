{{--
    Work Experience rows, most recent first (PdsRows). The first row is the
    current job, read from Employee Details and never stored (`derived`):
    its company carries "From Employee Details", an open end reads
    "Present", and its menu's one item opens Employee Details on the
    Profile tab ($parent.startEdit). Previous jobs (`row`) have Edit and
    Delete. A blank cell reads a muted dash. Current-job editing is only for
    management; My Profile previous-job edits become proposals.
    The rows are not clickable.
--}}
@php
    use App\Livewire\Employees\EmployeeFields;

    $blank = '<span class="text-muted-foreground">—</span>';
    $cell = static fn (?string $value): string => $value === null || $value === '' ? $blank : e($value);
    $service = static fn (?bool $value): string => $value === null ? $blank : ($value ? 'Y' : 'N');
@endphp
<thead>
    <tr>
        <th>Position Title</th>
        <th>Company / Agency</th>
        <th>From</th>
        <th>To</th>
        <th class="text-right">Monthly Salary</th>
        <th>Pay Grade</th>
        <th>Appointment Status</th>
        <th>Gov't Service</th>
        <th class="w-10"><span class="sr-only">Actions</span></th>
    </tr>
</thead>
<tbody>
    @foreach ($rows as $row)
        @php
            $label = implode(', ', array_filter([(string) $row->position_title, (string) $row->company], static fn (string $part): bool => $part !== ''));
        @endphp
        <tr wire:key="pds-experience-{{ $row->id ?? $row->kind }}">
            <td class="font-medium">{!! $cell($row->position_title) !!}</td>
            @if ($row->kind === 'derived')
                <td>
                    <div class="min-w-0">
                        <p class="break-words">{{ $row->company }}</p>
                        <p class="truncate text-xs text-muted-foreground">From Employee Details</p>
                    </div>
                </td>
            @else
                <td>{{ $row->company }}</td>
            @endif
            <td class="num whitespace-nowrap">{!! $cell(EmployeeFields::dateText($row->date_from)) !!}</td>
            @if ($row->kind === 'derived' && $row->date_to === null)
                <td class="whitespace-nowrap">Present</td>
            @else
                <td class="num whitespace-nowrap">{!! $cell(EmployeeFields::dateText($row->date_to)) !!}</td>
            @endif
            <td class="num whitespace-nowrap text-right">{!! $row->monthly_salary === null ? $blank : e(EmployeeFields::money($row->monthly_salary)) !!}</td>
            <td class="whitespace-nowrap">{!! $cell($row->pay_grade) !!}</td>
            <td>{!! $cell($row->appointment_status) !!}</td>
            <td class="whitespace-nowrap">{!! $service($row->government_service) !!}</td>
            <td class="text-right">
                @if ($canEdit && ($row->kind !== 'derived' || ! $requiresApproval))
                    <x-kit.row-actions :label="$label">
                        @if ($row->kind === 'derived')
                            <flux:menu.item icon="pencil" wire:click="$parent.startEdit('employment')">Edit in Employee Details</flux:menu.item>
                        @else
                            <flux:menu.item icon="pencil" wire:click="startEdit('{{ $row->id }}')">Edit</flux:menu.item>
                            <flux:menu.item icon="trash" variant="danger" wire:click="confirmDelete('{{ $row->id }}')">Delete</flux:menu.item>
                        @endif
                    </x-kit.row-actions>
                @endif
            </td>
        </tr>
    @endforeach
</tbody>
