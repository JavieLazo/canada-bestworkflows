{{--
    Position: ID | Position | Department | Description | Action.
    The Department cell is the department's name with the unit on a small
    muted second line, or the "Not placed" pill. Rows come in the
    structure's order (PositionRecords). Move up / Move down walk one
    department's or unit's own positions and are not offered for a Not
    placed one; Delete is disabled while ANYONE holds the position, inactive
    included. No Export or Import CSV.
--}}
<div class="space-y-6">
    <div>
        <h1 class="text-2xl font-bold tracking-tight">Position</h1>
        <p class="text-sm text-muted-foreground">Job positions offered when hiring or editing an employee record. Place each one in a department or in one of its units; the same title may be entered in more than one.</p>
    </div>

    <section class="hris-card overflow-hidden">
        <header class="flex flex-wrap items-center gap-3 border-b border-border px-5 py-4">
            <flux:input aria-label="Search positions" placeholder="Search ID, name or description" class="w-full min-w-[12rem] max-w-xs flex-1" wire:model.live.debounce.250ms="search" />

            <x-kit.filter-select label="Filter by department" class="w-[16rem]" wire:model.live="department">
                <flux:select.option value="ALL">All departments</flux:select.option>
                <flux:select.option value="NONE">Not placed</flux:select.option>
                @foreach ($placeOptions as $opt)
                    <flux:select.option :value="$opt->id">{!! $opt->unit ? '&#160;&#160;&#160;' : '' !!}{{ $opt->name }}</flux:select.option>
                @endforeach
            </x-kit.filter-select>

            <div class="ml-auto flex flex-wrap items-center gap-2">
                <flux:button variant="primary" icon="plus" wire:click="openAdd">Add position</flux:button>
            </div>
        </header>

        <div class="hris-table-wrap hris-table-scroll">
            <table class="hris-table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Position</th>
                        <th>Department</th>
                        <th>Description</th>
                        <th class="text-right">Action</th>
                    </tr>
                </thead>
                <tbody>
                    @if ($positions->total() === 0)
                        <tr wire:key="empty">
                            <x-kit.empty-state :colspan="5" title="No position matches those filters." />
                        </tr>
                    @endif
                    @foreach ($positions as $p)
                        @php($active = $holders[$p->id]['active'] ?? 0)
                        @php($inactive = $holders[$p->id]['inactive'] ?? 0)
                        <tr wire:key="position-{{ $p->id }}">
                            <td class="num whitespace-nowrap align-middle">{{ $p->code }}</td>
                            <td class="font-medium">{{ $p->name }}</td>
                            <td>
                                @if ($p->department_name === null)
                                    <span class="pill pill-muted">Not placed</span>
                                @else
                                    <span class="block">{{ $p->department_name }}</span>
                                    @if ($p->unit_name !== null)
                                        <span class="block text-xs text-muted-foreground">{{ $p->unit_name }}</span>
                                    @endif
                                @endif
                            </td>
                            <td class="text-muted-foreground">{{ $p->description }}</td>
                            <td class="text-right">
                                <x-kit.row-actions :label="$p->name">
                                    <flux:menu.item icon="pencil" wire:click="startEdit('{{ $p->id }}')">Edit</flux:menu.item>
                                    @if ($p->department_name !== null)
                                        <flux:menu.item icon="arrow-up" :disabled="$p->first" wire:click="move('{{ $p->id }}', 'up')">Move up</flux:menu.item>
                                        <flux:menu.item icon="arrow-down" :disabled="$p->last" wire:click="move('{{ $p->id }}', 'down')">Move down</flux:menu.item>
                                    @endif
                                    <flux:menu.item
                                        icon="trash"
                                        :variant="($active + $inactive) > 0 ? 'default' : 'danger'"
                                        :disabled="($active + $inactive) > 0"
                                        wire:click="confirmDelete('{{ $p->id }}')"
                                    >
                                        {{ ($active + $inactive) > 0 ? 'Delete · held by '.($active + $inactive) : 'Delete' }}
                                    </flux:menu.item>
                                </x-kit.row-actions>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <x-kit.pagination :paginator="$positions" noun="positions" />
    </section>

    <flux:modal wire:model.self="confirmOpen" class="w-full max-w-md">
        <div class="space-y-4">
            <div class="space-y-2">
                <flux:heading size="lg">Delete {{ $deleting?->name }}?</flux:heading>
                <flux:text>
                    Position {{ $deleting?->code }} is removed from the list for good. Nobody holds it,
                    so no record changes &mdash; but this cannot be undone.
                </flux:text>
            </div>
            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="outline">Cancel</flux:button>
                </flux:modal.close>
                <flux:button variant="primary" wire:click="deleteConfirmed">Delete</flux:button>
            </div>
        </div>
    </flux:modal>

    <flux:modal wire:model.self="dialogOpen" class="w-full max-w-lg">
        <div class="space-y-4">
            <div class="space-y-2">
                <flux:heading size="lg">
                    {{ $editing !== null ? 'Edit position' : 'Add position' }}
                    @if ($editing !== null)
                        <span class="num ml-2 text-sm font-normal text-muted-foreground">{{ $editing->code }}</span>
                    @endif
                </flux:heading>
                @if ($editing !== null)
                    {{-- One sentence, singular for one holder; the first part only while someone holds it. --}}
                    @php($one = $editingHolders === 1)
                    <flux:text>{{ ($editingHolders > 0 ? $editingHolders.($one ? ' employee holds' : ' employees hold').' this position. ' : '').'A new name shows on '.($one ? 'their record' : 'their records').' at once; Employment History and posted payroll keep the old name. Moving it changes the department and unit '.($one ? 'its holder shows' : 'its holders show').'.' }}</flux:text>
                @endif
            </div>

            <form class="space-y-4" wire:submit="save">
                <div class="space-y-2">
                    <flux:label>Position</flux:label>
                    <flux:input
                        wire:model="formName"
                        aria-label="Position"
                        maxlength="120"
                        aria-invalid="{{ $formError !== null ? 'true' : 'false' }}"
                        class:input="{{ $formError !== null ? 'border-danger!' : '' }}"
                    />
                    @if ($formError !== null)
                        <p class="text-xs text-danger">{{ $formError }}</p>
                    @endif
                </div>

                <div class="space-y-2">
                    <flux:label>Department</flux:label>
                    <x-kit.select aria-label="Department" wire:model.live="formDepartmentId">
                        <flux:select.option value="">Not placed</flux:select.option>
                        @foreach ($departments as $department)
                            <flux:select.option :value="$department->id">{{ $department->name }}</flux:select.option>
                        @endforeach
                    </x-kit.select>
                </div>

                @if ($formDepartmentId !== '')
                    <div class="space-y-2" wire:key="unit-for-{{ $formDepartmentId }}">
                        <flux:label>Unit</flux:label>
                        <x-kit.select aria-label="Unit" wire:model="formUnitId">
                            <flux:select.option value="">No unit — in the department itself</flux:select.option>
                            @foreach ($units as $unit)
                                <flux:select.option :value="$unit['id']">{{ $unit['name'] }}</flux:select.option>
                            @endforeach
                        </x-kit.select>
                        <p class="text-xs text-muted-foreground">Only the units of the chosen department are listed.</p>
                    </div>
                @endif

                <div class="space-y-2">
                    <flux:label>Description</flux:label>
                    <flux:textarea rows="2" aria-label="Description" wire:model="formDescription" />
                </div>

                <flux:button type="submit" variant="primary" :icon="$editing !== null ? 'pencil' : 'plus'">
                    {{ $editing !== null ? 'Save changes' : 'Add position' }}
                </flux:button>
            </form>
        </div>
    </flux:modal>
</div>
