{{--
    Civil Service Eligibility rows, latest exam first (PdsRows). Each row
    keeps its own License no. and Valid until. A blank cell reads a muted
    dash. The row menu (Edit, Delete) is drawn only for an Employee Records
    all-data holder ($canEdit); the rows themselves are not clickable.
--}}
@php
    use App\Livewire\Employees\EmployeeFields;

    $blank = '<span class="text-muted-foreground">—</span>';
    $cell = static fn (?string $value): string => $value === null || $value === '' ? $blank : e($value);
@endphp
<thead>
    <tr>
        <th>Eligibility</th>
        <th class="text-right">Rating</th>
        <th>Date of exam</th>
        <th>Place of exam</th>
        <th>License no.</th>
        <th>Valid until</th>
        <th class="w-10"><span class="sr-only">Actions</span></th>
    </tr>
</thead>
<tbody>
    @forelse ($rows as $row)
        <tr wire:key="pds-eligibility-{{ $row->id }}">
            <td class="font-medium">{{ $row->name }}</td>
            <td class="num text-right">{!! $cell($row->rating) !!}</td>
            <td class="num whitespace-nowrap">{!! $cell(EmployeeFields::dateText($row->exam_date)) !!}</td>
            <td class="whitespace-nowrap">{!! $cell($row->exam_place) !!}</td>
            <td class="num whitespace-nowrap">{!! $cell($row->license_no) !!}</td>
            <td class="num whitespace-nowrap">{!! $cell(EmployeeFields::dateText($row->license_valid_until)) !!}</td>
            <td class="text-right">
                @if ($canEdit)
                    <x-kit.row-actions :label="(string) $row->name">
                        <flux:menu.item icon="pencil" wire:click="startEdit('{{ $row->id }}')">Edit</flux:menu.item>
                        <flux:menu.item icon="trash" variant="danger" wire:click="confirmDelete('{{ $row->id }}')">Delete</flux:menu.item>
                    </x-kit.row-actions>
                @endif
            </td>
        </tr>
    @empty
        <tr wire:key="empty"><x-kit.empty-state :colspan="7" title="None recorded · prints as N/A" /></tr>
    @endforelse
</tbody>
