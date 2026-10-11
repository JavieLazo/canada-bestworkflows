{{--
    Holiday Settings — the port of
    src/app/(protected)/records/holidays/holidays-page.tsx. Same columns in the
    same order (ID, Name, Date, Schedule, Type, Percent, Action), same search +
    type filter, same default sort (date, ascending), same schedule/type badges
    and colors, same dialog fields, validation messages and copy.
--}}
@php
    use App\Livewire\Records\HolidaysPage;
    use App\Livewire\Records\Support\Hris;
@endphp
<div class="space-y-6">
    <div>
        <h1 class="text-2xl font-bold tracking-tight">Holiday Settings</h1>
        <p class="text-sm text-muted-foreground">
            The holidays payroll pays for, and at what rate.
        </p>
    </div>

    <section class="hris-card overflow-hidden">
        <header class="flex flex-wrap items-center gap-3 border-b border-border px-5 py-4">
            <flux:input
                aria-label="Search holidays"
                placeholder="Search ID or name"
                class="w-full min-w-[12rem] max-w-xs flex-1"
                wire:model.live.debounce.250ms="search"
            />
            <x-kit.filter-select label="Filter by type" class="w-[17rem]" wire:model.live="type">
                <flux:select.option value="ALL">All types</flux:select.option>
                @foreach (HolidaysPage::TYPES as $value => $label)
                    <flux:select.option value="{{ $value }}">{{ $label }}</flux:select.option>
                @endforeach
            </x-kit.filter-select>
            <div class="ml-auto flex flex-wrap items-center gap-2">
                <x-kit.export-button :href="route('records.holidays.export', array_filter(['q' => $search, 'type' => $type, 'sort' => $sortKey, 'dir' => $sortDir]))" />
                <livewire:kit.csv-import table="records.holidays" :template="route('records.holidays.export', ['template' => 1])" key="import-holidays" />
                <flux:button variant="primary" icon="plus" wire:click="openAdd">
                    Add holiday
                </flux:button>
            </div>
        </header>

        <div class="hris-table-wrap hris-table-scroll">
            <table class="hris-table">
                <thead>
                    <tr>
                        <x-kit.sort-header field="id" label="ID" />
                        <x-kit.sort-header field="name" label="Name" />
                        <x-kit.sort-header field="date" label="Date" />
                        <th>Schedule</th>
                        <x-kit.sort-header field="type" label="Type" />
                        <th class="text-right">Percent</th>
                        <th class="text-right">Action</th>
                    </tr>
                </thead>
                <tbody>
                    @if ($rows->total() === 0)
                        <tr wire:key="empty">
                            <x-kit.empty-state :colspan="7" title="No holiday matches those filters." />
                        </tr>
                    @endif
                    @foreach ($rows as $row)
                        <tr wire:key="row-{{ $row->getKey() }}">
                            <td class="num whitespace-nowrap">{{ $row->code }}</td>
                            <td class="font-medium">{{ $row->name }}</td>
                            <td class="num whitespace-nowrap">{{ Hris::fmtDate($row->date) }}</td>
                            <td class="whitespace-nowrap text-muted-foreground">
                                {{ HolidaysPage::SCHEDULES[$row->schedule] ?? $row->schedule }}
                            </td>
                            <td class="whitespace-nowrap">
                                <span class="{{ $row->type === 'REGULAR' ? 'pill pill-info' : 'pill pill-muted' }}">
                                    {{ HolidaysPage::TYPES[$row->type] ?? $row->type }}
                                </span>
                            </td>
                            <td class="num text-right">{{ $row->percent + 0 }}%</td>
                            <td class="text-right">
                                <x-kit.row-actions :label="$row->name">
                                    <flux:menu.item icon="pencil" wire:click="startEdit('{{ $row->getKey() }}')">
                                        Edit
                                    </flux:menu.item>
                                    <flux:menu.item
                                        icon="trash"
                                        variant="danger"
                                        wire:click="confirmDelete('{{ $row->getKey() }}')"
                                    >
                                        Delete
                                    </flux:menu.item>
                                </x-kit.row-actions>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <x-kit.pagination :paginator="$rows" noun="holidays" />
    </section>

    {{-- The AlertDialog (holidays-page.tsx:266-292). --}}
    <flux:modal wire:model.self="confirmOpen" class="w-full max-w-md">
        <div class="space-y-4">
            <div class="space-y-2">
                <flux:heading size="lg">Delete {{ $deleting?->name }}?</flux:heading>
                <flux:text>
                    {{ $deleting?->code }} is removed from the holiday calendar for good. This
                    cannot be undone.
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

    {{-- The HolidayDialog (holidays-page.tsx:295-473). --}}
    <flux:modal wire:model.self="dialogOpen" class="w-full max-w-lg">
        <div class="space-y-4">
            <div class="space-y-2">
                <flux:heading size="lg">
                    {{ $editing !== null ? 'Edit holiday' : 'Add holiday' }}
                    @if ($editing !== null)
                        <span class="num ml-2 text-sm font-normal text-muted-foreground">{{ $editing->code }}</span>
                    @endif
                </flux:heading>
                <flux:text>
                    Regular holidays are paid in full; special non-working days are paid at a
                    premium only when worked.
                </flux:text>
            </div>

            <form class="grid gap-4 sm:grid-cols-2" wire:submit="save">
                <div class="space-y-2 sm:col-span-2">
                    <flux:label>Name</flux:label>
                    <flux:input
                        wire:model="formName"
                        aria-invalid="{{ $formError !== null ? 'true' : 'false' }}"
                        class:input="{{ $formError !== null ? 'border-danger!' : '' }}"
                    />
                </div>

                <div class="space-y-2">
                    <flux:label>Date</flux:label>
                    <x-kit.date-field class="num" wire:model="formDate" aria-label="Date" />
                </div>

                <div class="space-y-2">
                    <flux:label>Schedule</flux:label>
                    <x-kit.select aria-label="Schedule" wire:model="formSchedule">
                        @foreach (HolidaysPage::SCHEDULES as $value => $label)
                            <flux:select.option value="{{ $value }}">{{ $label }}</flux:select.option>
                        @endforeach
                    </x-kit.select>
                </div>

                <div class="space-y-2">
                    <flux:label>Type</flux:label>
                    <x-kit.select aria-label="Type" wire:model="formType">
                        @foreach (HolidaysPage::TYPES as $value => $label)
                            <flux:select.option value="{{ $value }}">{{ $label }}</flux:select.option>
                        @endforeach
                    </x-kit.select>
                </div>

                <div class="space-y-2">
                    <flux:label>Percent</flux:label>
                    <flux:input type="number" min="0" step="1" class:input="num" wire:model="formPercent" />
                    <p class="text-xs text-muted-foreground">
                        The whole day's rate for working it, as a percent of the daily rate &mdash;
                        200 pays double, 130 is the usual special non-working rate. A holiday
                        nobody works pays nothing.
                    </p>
                </div>

                @if ($formError !== null)
                    <p class="text-xs text-danger sm:col-span-2">{{ $formError }}</p>
                @endif

                <div class="sm:col-span-2">
                    <flux:button
                        type="submit"
                        variant="primary"
                        :icon="$editing !== null ? 'pencil' : 'plus'"
                    >
                        {{ $editing !== null ? 'Save changes' : 'Add holiday' }}
                    </flux:button>
                </div>
            </form>
        </div>
    </flux:modal>
</div>
