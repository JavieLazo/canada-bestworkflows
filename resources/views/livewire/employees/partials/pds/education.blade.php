{{--
    Educational Background rows: every level in sheet order (PdsRows). A
    stored school (`row`) opens its Edit dialog on a click or Enter / Space;
    the click skips the row menu and its buttons. A level with no school
    (`empty`) reads "None recorded · prints as N/A", and the stored College
    or Graduate line (`legacy`) reads as its level's row marked "Entered
    before" — never copied into a school. Both open Add school on their
    level. Only an Employee Records all-data holder ($canEdit) gets the
    clicks and the menus.
--}}
@php
    use App\Livewire\Employees\EmployeeFields;

    $text = static fn (mixed $value): string => $value === null || $value === '' ? '—' : (string) $value;
@endphp
<thead>
    <tr>
        <th>Level</th>
        <th>Name of School</th>
        <th>Degree / Course</th>
        <th>From</th>
        <th>To</th>
        <th>Units Earned</th>
        <th>Year Graduated</th>
        <th>Honors</th>
        <th></th>
    </tr>
</thead>
<tbody>
    @foreach ($rows as $row)
        @php
            $short = EmployeeFields::EDUCATION_LEVEL_SHORT[$row->level] ?? $row->level;
        @endphp
        @if ($row->kind === 'row')
            <tr
                wire:key="pds-education-{{ $row->id }}"
                @if ($canEdit)
                    tabindex="0"
                    aria-label="Edit {{ $row->school }}"
                    class="cursor-pointer focus:outline-none [&:focus-visible>td]:bg-accent"
                    x-on:click="if (! $event.target.closest('button, [data-flux-menu]')) $wire.startEdit('{{ $row->id }}')"
                    x-on:keydown.enter.self="$wire.startEdit('{{ $row->id }}')"
                    x-on:keydown.space.self.prevent="$wire.startEdit('{{ $row->id }}')"
                @endif
            >
                <td class="whitespace-nowrap">{{ $short }}</td>
                <td class="font-medium">{{ $row->school }}</td>
                <td>{{ $text($row->course) }}</td>
                <td class="num whitespace-nowrap">{{ $text($row->attended_from) }}</td>
                <td class="num whitespace-nowrap">{{ $text($row->attended_to) }}</td>
                <td class="num whitespace-nowrap">{{ $text($row->units_earned) }}</td>
                <td class="num whitespace-nowrap">{{ $text($row->year_graduated) }}</td>
                <td class="whitespace-nowrap">{{ $text($row->honors) }}</td>
                <td class="text-right">
                    @if ($canEdit)
                        <x-kit.row-actions :label="(string) $row->school">
                            <flux:menu.item icon="pencil" wire:click="startEdit('{{ $row->id }}')">Edit</flux:menu.item>
                            <flux:menu.item icon="trash" variant="danger" wire:click="confirmDelete('{{ $row->id }}')">Delete</flux:menu.item>
                        </x-kit.row-actions>
                    @endif
                </td>
            </tr>
        @else
            @php
                $article = in_array(strtolower($short[0] ?? ''), ['a', 'e', 'i', 'o', 'u'], true) ? 'an' : 'a';
            @endphp
            <tr
                wire:key="pds-education-{{ $row->kind }}-{{ $row->level }}"
                @if ($canEdit)
                    tabindex="0"
                    aria-label="Add {{ $article }} {{ strtolower($short) }} school"
                    class="text-muted-foreground cursor-pointer focus:outline-none [&:focus-visible>td]:bg-accent"
                    x-on:click="if (! $event.target.closest('button, [data-flux-menu]')) $wire.openAdd('{{ $row->level }}')"
                    x-on:keydown.enter.self="$wire.openAdd('{{ $row->level }}')"
                    x-on:keydown.space.self.prevent="$wire.openAdd('{{ $row->level }}')"
                @else
                    class="text-muted-foreground"
                @endif
            >
                <td class="whitespace-nowrap">{{ $short }}</td>
                @if ($row->kind === 'legacy')
                    <td>
                        <div class="min-w-0">
                            <p class="truncate font-medium">{{ $text($row->school) }}</p>
                            <p class="truncate text-xs text-muted-foreground">Entered before</p>
                        </div>
                    </td>
                    <td>{{ $text($row->course) }}</td>
                    <td class="num whitespace-nowrap">—</td>
                    <td class="num whitespace-nowrap">—</td>
                    <td class="num whitespace-nowrap">—</td>
                    <td class="num whitespace-nowrap">{{ $text($row->year_graduated) }}</td>
                    <td class="whitespace-nowrap">—</td>
                @else
                    <td colspan="7">None recorded · prints as N/A</td>
                @endif
                <td class="text-right">
                    @if ($canEdit)
                        <x-kit.row-actions :label="$short.' schools'">
                            <flux:menu.item icon="plus" wire:click="openAdd('{{ $row->level }}')">Add School</flux:menu.item>
                        </x-kit.row-actions>
                    @endif
                </td>
            </tr>
        @endif
    @endforeach
</tbody>
