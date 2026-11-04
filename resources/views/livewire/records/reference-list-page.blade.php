{{--
    One managed option list — the port of
    src/app/(protected)/records/reference-list.tsx, shared by Document,
    Employment, Leave Settings and Payroll Deductions. Same columns
    in the same order (ID, {name}, Description?, Action), same search, same
    default sort (name, ascending), same dialog fields and copy, same
    delete-blocked-while-in-use menu item. Every Payroll Deductions row is a
    column of the Basic Payroll grid: deleting a row payroll computes itself
    switches that deduction off on every draft, and a row typed on draft
    payrolls warns that its amounts leave with it; only a row a finalized
    payroll charged stays.

    Export CSV and Import CSV sit beside the Add button only on the lists
    whose config says `csv` — Leave Settings and Payroll Deductions. THE
    TWO GENERAL RECORDS LISTS HERE (Document, Employment) HAVE NEITHER
    BUTTON, and no export route to link to.
--}}
@php
    use App\Modules\Payroll\Domain\DeductionCalculation;
@endphp
<div class="space-y-6">
    <div>
        <h1 class="text-2xl font-bold tracking-tight">{{ $config['title'] }}</h1>
        <p class="text-sm text-muted-foreground">{{ $config['description'] }}</p>
    </div>

    @if ($slug === 'payroll-deductions')
        @include('livewire.payroll.partials.settings-switch', ['activeView' => 'deductions'])
    @endif

    <section class="hris-card overflow-hidden">
        <header class="flex flex-wrap items-center gap-3 border-b border-border px-5 py-4">
            @unless ($canManage)
                <p class="w-full text-xs text-muted-foreground">Your role can view this list. Adding, editing and deleting require {{ $slug === 'payroll-deductions' ? 'Payroll' : 'Records Management' }} access to all data.</p>
            @endunless
            @if ($slug === 'payroll-deductions' && ! $percentReady)
                <p class="w-full text-xs text-muted-foreground">Percentage deductions can be set up once this installation's upgrade finishes; every row is typed per employee until then.</p>
            @endif
            <flux:input
                aria-label="Search {{ $config['plural'] }}"
                placeholder="Search ID, name or description"
                class="w-full min-w-[12rem] max-w-xs flex-1"
                wire:model.live.debounce.250ms="search"
            />

            <div class="ml-auto flex flex-wrap items-center gap-2">
                {{-- The credit rules of these types live on their own page. --}}
                @if ($slug === 'leave-types')
                    <flux:button variant="ghost" icon="adjustments-horizontal" :href="route('records.leave-credits')" wire:navigate>Leave Credits</flux:button>
                @endif
                @if ($config['csv'])
                    <x-kit.export-button :href="route('records.'.$slug.'.export', array_filter(['q' => $search, 'sort' => $sortKey, 'dir' => $sortDir]))" />
                    <livewire:kit.csv-import
                        :table="'records.'.$slug"
                        :template="route('records.'.$slug.'.export', ['template' => 1])"
                        :key="'import-'.$slug"
                    />
                @endif
                @if ($canManage)
                <flux:button variant="primary" icon="plus" wire:click="openAdd">
                    Add {{ $config['noun'] }}
                </flux:button>
                @endif
            </div>
        </header>

        <div class="hris-table-wrap hris-table-scroll">
            <table class="hris-table">
                <thead>
                    <tr>
                        <x-kit.sort-header field="id" label="ID" />
                        <x-kit.sort-header field="name" :label="$config['nameLabel']" />
                        @if ($config['showDescription'])
                            <th>Description</th>
                        @endif
                        @if ($slug === 'payroll-deductions')
                            <th>Computation</th>
                        @endif
                        @if ($canManage)
                            <th class="text-right">Action</th>
                        @endif
                    </tr>
                </thead>
                <tbody>
                    @if ($rows->total() === 0)
                        <tr wire:key="empty">
                            <x-kit.empty-state
                                :colspan="($config['showDescription'] ? 3 : 2) + ($slug === 'payroll-deductions' ? 1 : 0) + ($canManage ? 1 : 0)"
                                title="No {{ $config['noun'] }} matches those filters."
                            />
                        </tr>
                    @endif
                    @foreach ($rows as $row)
                        @php
                            $usedBy = $usage[$row->getKey()] ?? 0;
                            // Payroll Deductions only: a row payroll computes itself —
                            // deletable, and deleting it switches that deduction off on
                            // every draft.
                            $automatic = ($row->calculation ?? null) !== null;
                            $onDrafts = $draftUsage[$row->getKey()] ?? 0;
                        @endphp
                        <tr wire:key="row-{{ $row->getKey() }}">
                            <td class="num whitespace-nowrap align-middle">{{ $row->code }}</td>
                            <td class="font-medium">{{ $row->name }}</td>
                            @if ($config['showDescription'])
                                <td class="text-muted-foreground">{{ $row->description ?? '—' }}</td>
                            @endif
                            @if ($slug === 'payroll-deductions')
                                <td>
                                    <span class="{{ \App\Livewire\Records\Support\DeductionText::pill($row) }}">{{ \App\Livewire\Records\Support\DeductionText::computation($row) }}</span>
                                    @if (is_array($row->percent ?? null) && \App\Livewire\Records\Support\DeductionText::details($row->percent) !== '')
                                        <span class="mt-1 block text-xs text-muted-foreground">{{ \App\Livewire\Records\Support\DeductionText::details($row->percent) }}</span>
                                    @endif
                                </td>
                            @endif
                            @if ($canManage)
                            <td class="text-right">
                                <x-kit.row-actions :label="$row->name">
                                    <flux:menu.item icon="pencil" wire:click="startEdit('{{ $row->getKey() }}')">
                                        Edit
                                    </flux:menu.item>
                                    {{-- RowActions.tsx:42-49 — blocked (and not painted red)
                                         while records still carry the option; for Payroll
                                         Deductions, while a finalized payroll charged the row.
                                         A draft only warns: its typed amounts leave with the
                                         row, and a computed row switches its deduction off. --}}
                                    <flux:menu.item
                                        icon="trash"
                                        :variant="$usedBy > 0 ? 'default' : 'danger'"
                                        :disabled="$usedBy > 0"
                                        wire:click="confirmDelete('{{ $row->getKey() }}')"
                                    >
                                        @if ($automatic)
                                            Delete · switches the deduction off
                                        @elseif (is_array($row->percent ?? null))
                                            Delete · stops it on every draft
                                        @elseif ($usedBy > 0)
                                            {{ $slug === 'payroll-deductions' ? 'Delete · on a finalized payroll' : "Delete · in use by {$usedBy}" }}
                                        @elseif ($onDrafts > 0)
                                            Delete · on {{ $onDrafts }} {{ $onDrafts === 1 ? 'draft' : 'drafts' }}
                                        @else
                                            Delete
                                        @endif
                                    </flux:menu.item>
                                </x-kit.row-actions>
                            </td>
                            @endif
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <x-kit.pagination :paginator="$rows" :noun="$config['plural']" />
    </section>

    {{-- The AlertDialog (reference-list.tsx:280-306). --}}
    <flux:modal wire:model.self="confirmOpen" class="w-full max-w-md">
        <div class="space-y-4">
            <div class="space-y-2">
                <flux:heading size="lg">Delete {{ $deleting?->name }}?</flux:heading>
                <flux:text>
                    @php
                        $deletingDrafts = $deleting !== null ? ($draftUsage[$deleting->getKey()] ?? 0) : 0;
                        $deletingComputed = $deleting !== null && ($deleting->calculation ?? null) !== null;
                    @endphp
                    @if ($deletingComputed)
                        {{ $deleting?->code }} is a deduction payroll works out itself. Deleting it
                        switches the {{ $deleting?->name }} deduction off on every draft payroll —
                        nothing is taken for it and withholding tax is re-derived — and removes its
                        column. Finalized payrolls keep their posted payslips. To switch it back on,
                        add a deduction named exactly "{{ DeductionCalculation::defaultLabel((string) $deleting?->calculation) }}".
                        This cannot be undone.
                    @elseif (is_array($deleting?->percent ?? null))
                        {{ $deleting?->code }} is a percentage deduction payroll works out itself. Deleting
                        it removes the {{ $deleting?->name }} line and its column from every draft payroll
                        (withholding tax is re-derived where it came before tax). Finalized payrolls keep
                        their posted payslips. This cannot be undone.
                    @elseif ($deletingDrafts > 0)
                        {{ $deleting?->code }} is removed from the list for good, and so are the
                        amounts typed under it on {{ $deletingDrafts }} draft
                        {{ $deletingDrafts === 1 ? 'payroll' : 'payrolls' }}. Finalized payrolls
                        keep their posted payslips. This cannot be undone.
                    @else
                        {{ $deleting?->code }} is removed from the list for good. Nothing currently
                        uses it, so no record changes &mdash; but this cannot be undone.
                    @endif
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

    {{-- The ReferenceDialog (reference-list.tsx:326-414). --}}
    <flux:modal wire:model.self="dialogOpen" class="w-full max-w-lg">
        <div class="space-y-4">
            <div class="space-y-2">
                <flux:heading size="lg">
                    {{ $editing !== null ? "Edit {$config['noun']}" : "Add {$config['noun']}" }}
                    @if ($editing !== null)
                        <span class="num ml-2 text-sm font-normal text-muted-foreground">{{ $editing->code }}</span>
                    @endif
                </flux:heading>
                <flux:text>
                    @if ($slug === 'payroll-deductions')
                        {{ $editing !== null ? 'Saving changes every draft payroll on its next preview; finalized payrolls keep their posted payslips.' : 'A deduction is a column of the Basic Payroll grid, on every draft payroll from its next preview.' }}
                    @else
                        {{ $editing !== null
                            ? 'Renaming updates the option everywhere it is offered.'
                            : "The new {$config['noun']} becomes selectable across the system." }}
                    @endif
                </flux:text>
            </div>

            <form class="space-y-4" wire:submit="save">
                <div class="space-y-2">
                    <flux:label>{{ $config['nameLabel'] }}</flux:label>
                    <flux:input
                        wire:model="formName"
                        aria-invalid="{{ $formError !== null ? 'true' : 'false' }}"
                        class:input="{{ $formError !== null ? 'border-danger!' : '' }}"
                    />
                    @if ($formError !== null)
                        <p class="text-xs text-danger">{{ $formError }}</p>
                    @endif
                </div>

                <div @class(['space-y-2' => $config['showDescription'], 'hidden' => ! $config['showDescription']])>
                    <flux:label>Description</flux:label>
                    <flux:textarea rows="2" wire:model="formDescription" />
                </div>

                @if ($percentReady && ($editing === null || ($editing->calculation ?? null) === null))
                    {{-- Payroll Deductions: typed per employee, or a percentage of salary. --}}
                    <div class="space-y-3 border-t border-border pt-4">
                        <x-kit.select label="Computation" wire:model.live="formComputation">
                            <flux:select.option value="TYPED">Typed per employee on each payroll</flux:select.option>
                            <flux:select.option value="PERCENT">Percentage of salary</flux:select.option>
                        </x-kit.select>
                        @if ($editing !== null && is_array($editing->percent ?? null))
                            <p class="text-xs text-muted-foreground">Changes apply to every draft payroll on its next preview; finalized payrolls keep their posted payslips.</p>
                        @endif
                        @if ($formComputation === 'PERCENT')
                            <div class="grid gap-4 sm:grid-cols-2">
                                <div class="space-y-2">
                                    <flux:label for="deduction-employee">Employee share (%)</flux:label>
                                    <flux:input id="deduction-employee" type="number" min="0" max="100" step="0.01" class:input="num" wire:model.live.debounce.400ms="formPercentEmployee" aria-label="Employee share" aria-invalid="{{ $ruleError !== null ? 'true' : 'false' }}" />
                                </div>
                                <div class="space-y-2">
                                    <flux:label for="deduction-employer">Employer share (%)</flux:label>
                                    <flux:input id="deduction-employer" type="number" min="0" max="100" step="0.01" class:input="num" wire:model.live.debounce.400ms="formPercentEmployer" aria-label="Employer share" aria-invalid="{{ $ruleError !== null ? 'true' : 'false' }}" />
                                    <p class="text-xs text-muted-foreground">Reported on the payslip; never deducted from the employee's pay.</p>
                                </div>
                                <x-kit.select label="Based on" wire:model.live="formBase">
                                    @foreach (\App\Livewire\Records\Support\DeductionText::BASES as $value => $label)
                                        <flux:select.option value="{{ $value }}">{{ $label }}</flux:select.option>
                                    @endforeach
                                </x-kit.select>
                                <x-kit.select label="How often" wire:model.live="formFrequency">
                                    @foreach (\App\Livewire\Records\Support\DeductionText::FREQUENCIES as $value => $label)
                                        <flux:select.option value="{{ $value }}">{{ $label }}</flux:select.option>
                                    @endforeach
                                </x-kit.select>
                            </div>
                            <div class="space-y-2">
                                <flux:label for="deduction-cap">Monthly cap per share (optional)</flux:label>
                                <x-kit.money-input id="deduction-cap" wire:model.live="formCap" aria-label="Monthly cap per share" />
                            </div>
                            <div class="flex items-center gap-3 rounded-md border border-border bg-muted/40 p-4">
                                <flux:switch id="deduction-pretax" wire:model.live="formBeforeTax" />
                                <div>
                                    <flux:label for="deduction-pretax">Deducted before tax</flux:label>
                                    <p class="text-xs text-muted-foreground">Turn on when the amount is not part of taxable pay.</p>
                                </div>
                            </div>
                            @if ($ruleError !== null)
                                <p role="alert" class="text-xs text-danger">{{ $ruleError }}</p>
                            @elseif ($percentSample !== null)
                                <p class="rounded-md border border-border bg-muted px-4 py-3 text-sm {{ $percentSample['valid'] ? '' : 'text-muted-foreground' }}">{{ $percentSample['text'] }}</p>
                            @endif
                        @endif
                    </div>
                @endif

                <flux:button
                    type="submit"
                    variant="primary"
                    :icon="$editing !== null ? 'pencil' : 'plus'"
                >
                    {{ $editing !== null ? 'Save changes' : "Add {$config['noun']}" }}
                </flux:button>
            </form>
        </div>
    </flux:modal>
</div>
