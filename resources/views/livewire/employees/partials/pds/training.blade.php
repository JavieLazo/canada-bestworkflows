{{--
    Learning and Development rows, latest first (PdsRows). Hours print
    without trailing zeros (24, 7.5). A blank cell reads a muted dash. The
    row menu (Edit, Delete) is drawn only for an Employee Records all-data
    holder ($canEdit); the rows themselves are not clickable.
--}}
@php
    use App\Livewire\Employees\EmployeeFields;

    $blank = '<span class="text-muted-foreground">—</span>';
    $cell = static fn (?string $value): string => $value === null || $value === '' ? $blank : e($value);
@endphp
<thead>
    <tr>
        <th class="min-w-[14rem]">Title</th>
        <th>From</th>
        <th>To</th>
        <th class="text-right">Hours</th>
        <th>Type of L&amp;D</th>
        <th>Conducted / Sponsored By</th>
        <th></th>
    </tr>
</thead>
<tbody>
    @forelse ($rows as $row)
        <tr wire:key="pds-training-{{ $row->id }}">
            <td class="font-medium">{{ $row->title }}</td>
            <td class="num whitespace-nowrap">{!! $cell(EmployeeFields::dateText($row->date_from)) !!}</td>
            <td class="num whitespace-nowrap">{!! $cell(EmployeeFields::dateText($row->date_to)) !!}</td>
            <td class="num text-right">{!! $row->hours === null ? $blank : e(EmployeeFields::measure($row->hours)) !!}</td>
            <td class="whitespace-nowrap">{!! $cell($row->ld_type) !!}</td>
            <td>{!! $cell($row->sponsor) !!}</td>
            <td class="text-right">
                @if ($canEdit)
                    <x-kit.row-actions :label="(string) $row->title">
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
