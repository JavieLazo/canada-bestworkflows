{{--
    Departments & Units: each department, followed by its units, in HR's
    order. ID | Name | Description | Action; a unit's name is indented under
    its department with "└". No Export or Import CSV. The row menu's Delete is
    disabled with what blocks it — units, and positions placed directly in
    the row — and Move up / Move down at the ends of the row's own list (a
    unit among its department's units). The commands refuse whatever the menu
    showed.
--}}
@php
    use App\Modules\Organization\Contracts\OrgLevel;
@endphp
<div class="space-y-6">
    <div>
        <h1 class="text-2xl font-bold tracking-tight">Departments &amp; Units</h1>
        <p class="text-sm text-muted-foreground">Departments, and the units inside each one, in the order you set. Each position is placed in a department or in one of its units on the Position page.</p>
    </div>

    <section class="hris-card overflow-hidden">
        <header class="flex flex-wrap items-center gap-3 border-b border-border px-5 py-4">
            <flux:input aria-label="Search departments and units" placeholder="Search ID, name or description" class="w-full min-w-[12rem] max-w-xs flex-1" wire:model.live.debounce.250ms="search" />

            <div class="ml-auto flex flex-wrap items-center gap-2">
                <flux:button variant="primary" icon="plus" wire:click="openAddDepartment">Add department</flux:button>
            </div>
        </header>

        <div class="hris-table-wrap hris-table-scroll">
            <table class="hris-table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Name</th>
                        <th>Description</th>
                        <th class="text-right">Action</th>
                    </tr>
                </thead>
                <tbody>
                    @if ($paginator->total() === 0)
                        <tr wire:key="empty">
                            <x-kit.empty-state :colspan="4" title="No department or unit matches those filters." />
                        </tr>
                    @endif
                    @foreach ($paginator as $r)
                        @php($isUnit = $r->kind === OrgLevel::UNIT)
                        <tr wire:key="node-{{ $r->id }}">
                            <td class="num whitespace-nowrap align-middle">{{ $r->code }}</td>
                            <td @class(['font-medium' => ! $isUnit])>
                                <span @class(['flex items-center gap-2', 'pl-5' => $isUnit])>
                                    @if ($isUnit)
                                        <span class="text-muted-foreground" aria-hidden="true">└</span>
                                    @endif
                                    <span>
                                        {{ $r->name }}
                                        @if ($r->head !== '')
                                            <span class="block text-xs font-normal text-muted-foreground" data-office-head>Head of office: {{ $r->head }}</span>
                                        @endif
                                    </span>
                                </span>
                            </td>
                            <td class="text-muted-foreground">{{ $r->description }}</td>
                            <td class="text-right">
                                <x-kit.row-actions :label="$r->name">
                                    <flux:menu.item icon="pencil" wire:click="startEdit('{{ $r->id }}')">Edit</flux:menu.item>
                                    @if (! $isUnit)
                                        <flux:menu.item icon="plus" wire:click="openAddUnit('{{ $r->id }}')">Add unit</flux:menu.item>
                                    @endif
                                    <flux:menu.item icon="arrow-up" :disabled="$r->first" wire:click="move('{{ $r->id }}', 'up')">Move up</flux:menu.item>
                                    <flux:menu.item icon="arrow-down" :disabled="$r->last" wire:click="move('{{ $r->id }}', 'down')">Move down</flux:menu.item>
                                    <flux:menu.item
                                        icon="trash"
                                        :variant="$r->blocked_by !== '' ? 'default' : 'danger'"
                                        :disabled="$r->blocked_by !== ''"
                                        wire:click="confirmDelete('{{ $r->id }}')"
                                    >
                                        {{ $r->blocked_by !== '' ? 'Delete · has '.$r->blocked_by : 'Delete' }}
                                    </flux:menu.item>
                                </x-kit.row-actions>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <x-kit.pagination :paginator="$paginator" noun="departments and units" />
    </section>

    <flux:modal wire:model.self="confirmOpen" class="w-full max-w-md">
        <div class="space-y-4">
            <div class="space-y-2">
                <flux:heading size="lg">Delete {{ $deleting?->name }}?</flux:heading>
                <flux:text>
                    {{ $deleting !== null ? OrgLevel::noun($deleting->kind) : '' }} {{ $deleting?->code }} is removed for good.
                    Nothing is placed in it, so no record changes &mdash; but this cannot be undone.
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
        @php($unitDialog = $dialogKind === OrgLevel::UNIT)
        <div class="space-y-4">
            <div class="space-y-2">
                <flux:heading size="lg">
                    {{ ($editing !== null ? 'Edit ' : 'Add ').($unitDialog ? 'unit' : 'department') }}
                    @if ($editing !== null)
                        <span class="num ml-2 text-sm font-normal text-muted-foreground">{{ $editing->code }}</span>
                    @endif
                </flux:heading>
                @if ($unitDialog && $editing !== null)
                    <flux:text>Moving a unit to another department takes its positions, and the people who hold them, along with it. Employment History is not changed.</flux:text>
                @endif
            </div>

            <form class="space-y-4" wire:submit="save">
                <div class="space-y-2">
                    <flux:label>{{ $unitDialog ? 'Unit name' : 'Department name' }}</flux:label>
                    <flux:input
                        wire:model="formName"
                        :aria-label="$unitDialog ? 'Unit name' : 'Department name'"
                        aria-invalid="{{ $formError !== null ? 'true' : 'false' }}"
                        class:input="{{ $formError !== null ? 'border-danger!' : '' }}"
                    />
                    @if ($formError !== null)
                        <p class="text-xs text-danger">{{ $formError }}</p>
                    @endif
                </div>

                @if ($unitDialog)
                    <div class="space-y-2">
                        <flux:label>Department</flux:label>
                        <x-kit.select aria-label="Department" wire:model="formDepartmentId">
                            @foreach ($departments as $department)
                                <flux:select.option :value="$department->id">{{ $department->name }}</flux:select.option>
                            @endforeach
                        </x-kit.select>
                    </div>
                @endif

                <div class="space-y-2">
                    <flux:label>Description</flux:label>
                    <flux:textarea rows="2" aria-label="Description" wire:model="formDescription" />
                </div>

                @if ($headsReady)
                    <div class="space-y-2" data-office-head-fields>
                        <div class="grid gap-3 sm:grid-cols-2">
                            <flux:input label="Head of office" wire:model="formHeadName" maxlength="200" placeholder="Leave blank for none" />
                            <flux:input label="Designation" wire:model="formHeadDesignation" maxlength="200" />
                        </div>
                        <p class="text-xs text-muted-foreground">Named on the Application for Leave as the Authorized official/Head of office. {{ $unitDialog ? 'Left blank, the department\'s head is named instead.' : 'A unit may name its own.' }}</p>
                    </div>
                @endif

                <flux:button type="submit" variant="primary" :icon="$editing !== null ? 'pencil' : 'plus'">
                    {{ $editing !== null ? 'Save changes' : ($unitDialog ? 'Add unit' : 'Add department') }}
                </flux:button>
            </form>
        </div>
    </flux:modal>
</div>
