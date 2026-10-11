{{--
    Voluntary Work rows, latest first (PdsRows): the organization over its
    address in one cell (the address line left out when blank). Hours print
    without trailing zeros. A blank cell reads a muted dash. The row menu
    (Edit, Delete) is drawn only for an Employee Records all-data holder
    ($canEdit); the rows themselves are not clickable.
--}}
@php
    use App\Livewire\Employees\EmployeeFields;

    $blank = '<span class="text-muted-foreground">—</span>';
    $cell = static fn (?string $value): string => $value === null || $value === '' ? $blank : e($value);
@endphp
<thead>
    <tr>
        <th>Organization and address</th>
        <th>From</th>
        <th>To</th>
        <th class="text-right">Hours</th>
        <th>Position / nature of work</th>
        <th></th>
    </tr>
</thead>
<tbody>
    @forelse ($rows as $row)
        <tr wire:key="pds-voluntary-{{ $row->id }}">
            <td>
                <div class="min-w-0">
                    <p class="truncate font-medium">{{ $row->organization }}</p>
                    @if ($row->organization_address !== null)
                        <p class="truncate text-xs text-muted-foreground">{{ $row->organization_address }}</p>
                    @endif
                </div>
            </td>
            <td class="num whitespace-nowrap">{!! $cell(EmployeeFields::dateText($row->date_from)) !!}</td>
            <td class="num whitespace-nowrap">{!! $cell(EmployeeFields::dateText($row->date_to)) !!}</td>
            <td class="num text-right">{!! $row->hours === null ? $blank : e(EmployeeFields::measure($row->hours)) !!}</td>
            <td>{!! $cell($row->position) !!}</td>
            <td class="text-right">
                @if ($canEdit)
                    <x-kit.row-actions :label="(string) $row->organization">
                        <flux:menu.item icon="pencil" wire:click="startEdit('{{ $row->id }}')">Edit</flux:menu.item>
                        <flux:menu.item icon="trash" variant="danger" wire:click="confirmDelete('{{ $row->id }}')">Delete</flux:menu.item>
                    </x-kit.row-actions>
                @endif
            </td>
        </tr>
    @empty
        <tr wire:key="empty"><x-kit.empty-state :colspan="6" title="None recorded · prints as N/A" /></tr>
    @endforelse
</tbody>
