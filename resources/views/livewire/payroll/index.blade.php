{{--
    Payroll — the run list, the port of payroll/payroll-page.tsx.

    Same search + status filter, same default sort (Period from, descending), same
    status pills, same empty-state copy, same delete confirmation. The
    AlertDialog is <flux:modal>; its header and footer carry shadcn's classes by
    hand.

    DIVERGENCE — columns. The reference has six (Payroll name, Date from, Date
    to, No. of employee, Status, Action) and prints the minted run code as a
    second line under the payroll name. This list gives the code its own
    sortable ID column in front, the way every other list in the app labels and
    sorts its row id, so the name cell carries only the name.

    DIVERGENCE (mandated): a FINALIZED run's delete button is disabled and the
    action refuses it — the reference hard-deletes posted payslips, which is a
    documented bug. Only the reference's DRAFT dialog copy is ported, since
    only drafts can reach the dialog.
--}}
@php
    use App\Livewire\Payroll\Index as PayrollIndex;
    use App\Livewire\Payroll\Support\PayrollData;
@endphp

<div class="space-y-6">
    <div>
        <h1 class="text-2xl font-bold tracking-tight">{{ $heading }}</h1>
        <p class="text-sm text-muted-foreground">
            {{ $yearly
                ? 'Every 13th month run, draft or finalized. Each pays one twelfth of the basic pay earned in its year.'
                : 'Every payroll, draft or finalized. Open one to work on it, or create a new cut-off.' }}
        </p>
    </div>

    <section class="hris-card overflow-hidden">
        <header class="flex flex-wrap items-center gap-3 border-b border-border px-5 py-4">
            <h2 class="mr-auto text-base font-bold">Payroll List</h2>

            <flux:input
                aria-label="Search payroll"
                placeholder="Search ID or payroll name"
                class="w-full min-w-[12rem] max-w-xs"
                wire:model.live="search"
            />

            <x-kit.filter-select label="Filter by Status" class="w-[11rem]" wire:model.live="status">
                <flux:select.option value="ALL">All statuses</flux:select.option>
                @foreach (PayrollIndex::STATUSES as $value => $label)
                    <flux:select.option value="{{ $value }}">{{ $label }}</flux:select.option>
                @endforeach
            </x-kit.filter-select>

            <x-kit.export-button :href="route($yearly ? 'payroll.thirteenth.export' : 'payroll.export', array_filter(['q' => $search, 'status' => $status, 'sort' => $sortKey, 'dir' => $sortDir]))" />

            <flux:button variant="primary" icon="plus" :href="route($createRoute)" wire:navigate>
                {{ $yearly ? 'Create 13th Month Payroll' : 'Create Payroll' }}
            </flux:button>
        </header>

        <div class="hris-table-wrap hris-table-scroll">
            <table class="hris-table">
                <thead>
                    <tr>
                        <x-kit.sort-header field="id" label="ID" />
                        <x-kit.sort-header field="name" label="Payroll Name" />
                        <x-kit.sort-header field="from" label="Period From" />
                        <x-kit.sort-header field="to" label="Period To" />
                        <x-kit.sort-header field="employees" label="No. of Employees" />
                        <x-kit.sort-header field="status" label="Status" />
                        <th class="text-right">Action</th>
                    </tr>
                </thead>
                <tbody>
                    @if ($total === 0)
                        <tr wire:key="empty">
                            <x-kit.empty-state :colspan="7" title="No payroll yet. Create one to get started." />
                        </tr>
                    @endif
                    @foreach ($rows as $r)
                        <tr
                            wire:key="run-{{ $r->id }}"
                            tabindex="0"
                            aria-label="Open {{ $r->name }}"
                            class="cursor-pointer focus:outline-none focus-visible:bg-accent"
                            x-on:click="Livewire.navigate('{{ route($showRoute, ['id' => $r->id]) }}')"
                            x-on:keydown.enter.prevent="Livewire.navigate('{{ route($showRoute, ['id' => $r->id]) }}')"
                            x-on:keydown.space.prevent="Livewire.navigate('{{ route($showRoute, ['id' => $r->id]) }}')"
                        >
                            <td class="num whitespace-nowrap align-middle">{{ $r->code }}</td>
                            <td class="font-medium">{{ $r->name }}</td>
                            <td class="num whitespace-nowrap">{{ PayrollData::fmtDate($r->period_start) }}</td>
                            <td class="num whitespace-nowrap">{{ PayrollData::fmtDate($r->period_end) }}</td>
                            <td class="num">{{ $r->members_count }}</td>
                            <td class="whitespace-nowrap">
                                <span class="{{ $r->status === 'FINALIZED' ? 'pill pill-success' : 'pill pill-warning' }}">
                                    {{ $r->status }}
                                </span>
                            </td>
                            <td class="text-right">
                                {{-- Divergence: disabled on FINALIZED — posted payslips must stay. --}}
                                <flux:button
                                    variant="ghost"
                                    size="sm"
                                    square
                                    icon="trash"
                                    class="text-danger hover:text-danger"
                                    aria-label="Delete {{ $r->name }}"
                                    :disabled="$r->status === 'FINALIZED'"
                                    title="{{ $r->status === 'FINALIZED' ? 'A finalized payroll is immutable and cannot be deleted.' : 'Delete '.$r->name }}"
                                    x-on:click.stop="$wire.confirmDelete('{{ $r->id }}')"
                                />
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <x-kit.pagination :paginator="$rows" noun="payrolls" />
    </section>

    <flux:modal wire:model.self="confirmingDelete" class="w-full max-w-md">
        @if ($deleting)
            <div class="space-y-4">
                <div class="space-y-1.5">
                    <h2 class="text-lg font-semibold leading-none tracking-tight">Delete {{ $deleting->name }}?</h2>
                    <p class="text-sm text-muted-foreground">
                        This draft and everything on it will be removed.
                    </p>
                </div>
                <div class="flex justify-end gap-2 pt-2">
                    <flux:button variant="outline" wire:click="$set('confirmingDelete', false)" wire:loading.attr="disabled" wire:target="deleteConfirmed">
                        Cancel
                    </flux:button>
                    <flux:button
                        variant="danger"
                        wire:click="deleteConfirmed"
                        wire:loading.attr="disabled"
                        wire:target="deleteConfirmed"
                    >
                        Delete
                    </flux:button>
                </div>
            </div>
        @endif
    </flux:modal>
</div>
