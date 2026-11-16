{{--
    Incentives & Bonuses — the benefits catalog: search, sortable ID / Name
    columns, what each benefit pays, how often, how it is taxed and who
    qualifies, an Add/Edit dialog for the rule and a delete confirmation.
    Writes need all-data RECORDS or PAYROLL; the buttons show only then,
    and the commands check again. Nothing here prices a payroll: the
    dialog's sample is the rule's own arithmetic at a sample salary.
--}}
@php
    use App\Livewire\Records\Support\BenefitText;
    use App\Modules\Payroll\Domain\Benefits\BenefitRule;

    // The refusal under the control it names; the dialog's foot keeps the rest.
    $errorAt = static fn (string $field): ?string => $formError !== null && $formErrorField === $field ? $formError : null;
@endphp
<div class="space-y-6">
    @include('livewire.payroll.partials.settings-header', [
        'activeView' => 'incentives',
        'title' => 'Incentives & Bonuses',
        'description' => 'The incentives and bonuses a payroll can pay, and how each is taxed.',
    ])

    {{-- The yearly tax-exempt ceiling: ONE figure, replaced in place. What it was before is in the audit trail.
         Above the list, because it bounds every tax-exempt type in it and a long list would bury it. --}}
    <section class="hris-card overflow-hidden" data-benefit-ceiling>
        <header class="flex flex-wrap items-center gap-3 px-5 py-4">
            <div class="min-w-0 flex-1">
                <h2 class="text-base font-bold">Tax-Exempt Ceiling</h2>
                <p class="mt-1 text-sm text-muted-foreground">How much of an employee's tax-exempt incentives stays untaxed in a year. Anything above it is taxed with the pay.</p>
            </div>
            @unless ($ceilingReady)
                <p class="text-sm text-muted-foreground">The built-in figure applies until this installation's upgrade finishes.</p>
            @else
                <p class="num text-lg font-semibold" data-ceiling-amount>{{ $ceiling === null ? 'Not set' : \App\Livewire\Records\Support\Hris::peso($ceiling->ceiling) }}</p>
                @if ($canEdit)
                    <flux:button variant="outline" icon="pencil" wire:click="openCeiling">Change</flux:button>
                @endif
            @endunless
        </header>
    </section>

    <section class="hris-card overflow-hidden">
        <header class="flex flex-wrap items-center gap-3 border-b border-border px-5 py-4">
            @unless ($rulesReady)
                <p class="w-full text-xs text-muted-foreground">Incentive rules can be set up once this installation's upgrade finishes; every type is typed per payroll until then.</p>
            @endunless
            @unless ($canEdit)
                <p class="w-full text-xs text-muted-foreground">Your role can view this catalog. Adding, editing and deleting require Payroll access to all data.</p>
            @endunless
            <flux:input
                aria-label="Search incentive types"
                placeholder="Search ID or name"
                class="w-full min-w-[12rem] max-w-xs flex-1"
                wire:model.live.debounce.250ms="search"
            />
            <div class="ml-auto flex flex-wrap items-center gap-2">
                <x-kit.export-button :href="route('records.incentive-types.export', array_filter(['q' => $search, 'sort' => $sortKey, 'dir' => $sortDir]))" />
                <livewire:kit.csv-import table="records.incentive-types" :template="route('records.incentive-types.export', ['template' => 1])" key="import-incentive-types" />
                @if ($canEdit)
                    <flux:button variant="primary" icon="plus" wire:click="openAdd">
                        Add Incentive Type
                    </flux:button>
                @endif
            </div>
        </header>

        <div class="hris-table-wrap hris-table-scroll">
            <table class="hris-table">
                <thead>
                    <tr>
                        <x-kit.sort-header field="id" label="ID" />
                        <x-kit.sort-header field="name" label="Name" />
                        <th>Amount</th>
                        <th>How Often</th>
                        <x-kit.sort-header field="taxable" label="Tax" />
                        <th>Who Qualifies</th>
                        @if ($canEdit)
                            <th class="text-right">Action</th>
                        @endif
                    </tr>
                </thead>
                <tbody>
                    @if ($rows->total() === 0)
                        <tr wire:key="empty">
                            <x-kit.empty-state :colspan="$canEdit ? 7 : 6" title="No incentive type matches that search." />
                        </tr>
                    @endif
                    @foreach ($rows as $row)
                        @php
                            $usedBy = $usage[$row->getKey()] ?? 0;
                            $tax = BenefitText::tax($row);
                        @endphp
                        <tr wire:key="row-{{ $row->getKey() }}">
                            <td class="num whitespace-nowrap">{{ $row->code }}</td>
                            <td>
                                <p class="font-medium">{{ $row->name }}</p>
                                @if ($row->description)
                                    <p class="text-xs text-muted-foreground">{{ $row->description }}</p>
                                @endif
                            </td>
                            <td class="num whitespace-nowrap">{{ BenefitText::amount($row) }}</td>
                            <td class="whitespace-nowrap">{{ BenefitText::frequency($row) }}</td>
                            <td><span class="{{ BenefitText::TAX_PILLS[$tax] }}">{{ BenefitText::TAXES[$tax] }}</span></td>
                            <td class="text-muted-foreground">{{ BenefitText::who($row, $statusNames) }}</td>
                            @if ($canEdit)
                                <td class="text-right">
                                    <x-kit.row-actions :label="$row->name">
                                        <flux:menu.item icon="pencil" wire:click="startEdit('{{ $row->getKey() }}')">
                                            Edit
                                        </flux:menu.item>
                                        <flux:menu.item
                                            icon="trash"
                                            :variant="$usedBy > 0 ? 'default' : 'danger'"
                                            :disabled="$usedBy > 0"
                                            wire:click="confirmDelete('{{ $row->getKey() }}')"
                                        >
                                            {{ $usedBy > 0 ? "Delete · in use by {$usedBy}" : 'Delete' }}
                                        </flux:menu.item>
                                    </x-kit.row-actions>
                                </td>
                            @endif
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <x-kit.pagination :paginator="$rows" noun="incentive types" />
    </section>

    {{-- The dialogs exist only for a viewer who may write. --}}
    @if ($canEdit)
    <flux:modal wire:model.self="ceilingOpen" class="w-full max-w-md" x-init="$el.querySelector('dialog')?.setAttribute('aria-labelledby', 'ceiling-heading')">
        <form wire:submit="saveCeiling" class="space-y-4">
            <flux:heading size="lg" level="2" id="ceiling-heading">Tax-Exempt Ceiling</flux:heading>
            <div class="space-y-2">
                <flux:label for="ceiling-amount">Ceiling for the Year</flux:label>
                <x-kit.money-input id="ceiling-amount" wire:model="ceilingForm.amount" aria-label="Ceiling for the year" />
                @include('livewire.payroll.partials.settings-applies-note')
            </div>
            @if ($ceilingError !== null)
                <p role="alert" class="text-sm text-danger">{{ $ceilingError }}</p>
            @endif
            <div class="flex justify-end gap-2">
                <flux:button type="button" variant="outline" wire:click="$set('ceilingOpen', false)">Cancel</flux:button>
                <flux:button type="submit" variant="primary">Save</flux:button>
            </div>
        </form>
    </flux:modal>
    <flux:modal
        wire:model.self="confirmOpen"
        class="w-full max-w-md"
        x-init="$el.querySelector('dialog')?.setAttribute('aria-labelledby', 'incentive-delete-heading')"
    >
        <div class="space-y-4">
            <div class="space-y-2">
                <flux:heading size="lg" id="incentive-delete-heading">Delete {{ $deleting?->name }}?</flux:heading>
                <flux:text>
                    Incentive type {{ $deleting?->code }} is removed for good. Payslips already posted keep the lines
                    they paid under it.
                </flux:text>
            </div>
            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="outline">Cancel</flux:button>
                </flux:modal.close>
                <flux:button variant="danger" wire:click="deleteConfirmed">Delete</flux:button>
            </div>
        </div>
    </flux:modal>

    {{-- Wide rather than long: what the benefit pays and who gets it, beside how it is taxed. The footer stays in view (bg-inherit down the chain from the <dialog>). --}}
    <flux:modal
        wire:model.self="dialogOpen"
        class="w-full max-w-5xl scroll-pb-32"
        x-init="$el.querySelector('dialog')?.setAttribute('aria-labelledby', 'incentive-type-heading')"
    >
        <form class="space-y-4 bg-inherit" wire:submit="save">
            <div class="space-y-2">
                <flux:heading size="lg" id="incentive-type-heading">
                    {{ $editing !== null ? 'Edit Incentive Type' : 'Add Incentive Type' }}
                    @if ($editing !== null)
                        <span class="num ml-2 text-sm font-normal text-muted-foreground">{{ $editing->code }}</span>
                    @endif
                </flux:heading>
                @if ($editing !== null)
                    @include('livewire.payroll.partials.settings-applies-note', ['class' => 'text-sm text-muted-foreground'])
                @endif
            </div>

            <div class="grid gap-6 lg:grid-cols-2 lg:items-start">
                <div class="grid min-w-0 gap-4">
                    <div class="space-y-2">
                        <flux:label>Name</flux:label>
                        <flux:input
                            wire:model="formName"
                            maxlength="100"
                            aria-invalid="{{ $errorAt('name') !== null ? 'true' : 'false' }}"
                            class:input="{{ $errorAt('name') !== null ? 'border-danger!' : '' }}"
                        />
                        @if ($errorAt('name') !== null)
                            <p class="text-xs text-danger">{{ $errorAt('name') }}</p>
                        @endif
                    </div>
                    <div class="space-y-2">
                        <flux:label>Description (optional)</flux:label>
                        <flux:textarea wire:model="formDescription" rows="2" maxlength="500" />
                    </div>

                    @if ($rulesReady)
                        <div class="grid gap-4 sm:grid-cols-2">
                            <x-kit.select label="Amount Type" wire:model.live="formAmountType">
                                @foreach (BenefitText::AMOUNT_TYPES as $value => $label)
                                    <flux:select.option value="{{ $value }}">{{ $label }}</flux:select.option>
                                @endforeach
                            </x-kit.select>
                            <div class="space-y-2">
                                @if (! in_array($formAmountType, [BenefitRule::AMOUNT_TYPED, BenefitRule::AMOUNT_MONTH], true))
                                    <flux:label for="benefit-amount">{{ $formAmountType === BenefitRule::AMOUNT_PERCENT ? 'Percent of the Monthly Salary' : ($formAmountType === BenefitRule::AMOUNT_DAILY ? 'Amount per Day' : 'Amount') }}</flux:label>
                                    @if ($formAmountType === BenefitRule::AMOUNT_PERCENT)
                                        <flux:input id="benefit-amount" type="number" min="0" max="1000" step="0.01" class:input="num" wire:model.live.debounce.400ms="formAmount" aria-label="Percent of the monthly salary" aria-invalid="{{ $errorAt('amount') !== null ? 'true' : 'false' }}" />
                                    @else
                                        <x-kit.money-input id="benefit-amount" wire:model.live="formAmount" aria-label="Amount" :aria-invalid="$errorAt('amount') !== null" />
                                    @endif
                                    @if ($errorAt('amount') !== null)
                                        <p class="text-xs text-danger">{{ $errorAt('amount') }}</p>
                                    @endif
                                @else
                                    <flux:label>Amount</flux:label>
                                    <p class="rounded-md border border-dashed border-border px-3 py-2 text-sm text-muted-foreground">{{ $formAmountType === BenefitRule::AMOUNT_MONTH ? "The employee's monthly rate" : 'Typed on each payroll' }}</p>
                                @endif
                            </div>
                        </div>
                        <div class="grid gap-4 sm:grid-cols-2">
                            <div class="space-y-2">
                                {{-- A typed amount has no schedule, so its frequency reads as the list words it. --}}
                                @if ($formAmountType === BenefitRule::AMOUNT_TYPED)
                                    <flux:label>How Often</flux:label>
                                    <p class="rounded-md border border-dashed border-border px-3 py-2 text-sm text-muted-foreground">When typed</p>
                                @else
                                    <x-kit.select label="How Often" wire:model.live="formFrequency">
                                        @foreach (BenefitText::FREQUENCIES as $value => $label)
                                            <flux:select.option value="{{ $value }}">{{ $label }}</flux:select.option>
                                        @endforeach
                                    </x-kit.select>
                                @endif
                                @if ($errorAt('frequency') !== null)
                                    <p class="text-xs text-danger">{{ $errorAt('frequency') }}</p>
                                @endif
                            </div>
                            <div class="space-y-2">
                                @if ($formFrequency === BenefitRule::FREQUENCY_YEARLY)
                                    <x-kit.select label="Month" wire:model.live="formMonth">
                                        <flux:select.option value="">Choose a month</flux:select.option>
                                        @foreach (BenefitText::MONTHS as $number => $month)
                                            <flux:select.option value="{{ $number }}">{{ $month }}</flux:select.option>
                                        @endforeach
                                    </x-kit.select>
                                    @if ($errorAt('month') !== null)
                                        <p class="text-xs text-danger">{{ $errorAt('month') }}</p>
                                    @endif
                                @endif
                            </div>
                        </div>
                        @if ($formAmountType === BenefitRule::AMOUNT_TYPED)
                            <p class="text-xs text-muted-foreground">Typed on each payroll that pays it. Never added automatically.</p>
                        @elseif ($formFrequency === BenefitRule::FREQUENCY_ONCE)
                            <p class="text-xs text-muted-foreground">Typed on the payroll that pays it. Never added automatically.</p>
                        @endif

                        <div class="rounded-md border border-border bg-muted/40 p-4">
                            <flux:label>Who Qualifies</flux:label>
                            <div class="mt-3 grid gap-3 sm:grid-cols-2">
                                <x-kit.select label="Employment Status" wire:model.live="formStatusMode">
                                    <flux:select.option value="ALL">All employees</flux:select.option>
                                    <flux:select.option value="SOME">Only these statuses</flux:select.option>
                                </x-kit.select>
                                <div class="space-y-2">
                                    <flux:label for="benefit-service">Minimum Months of Service</flux:label>
                                    <flux:input id="benefit-service" type="number" min="0" max="600" step="1" class:input="num" wire:model.live.debounce.400ms="formMinServiceMonths" aria-label="Minimum months of service" aria-invalid="{{ $errorAt('service') !== null ? 'true' : 'false' }}" />
                                    @if ($errorAt('service') !== null)
                                        <p class="text-xs text-danger">{{ $errorAt('service') }}</p>
                                    @endif
                                </div>
                            </div>
                            @if ($formStatusMode === 'SOME')
                                <div class="mt-3 grid gap-2 sm:grid-cols-2">
                                    @forelse ($statuses as $status)
                                        <flux:checkbox wire:model.live="formStatuses.{{ $status['id'] }}" :label="$status['name']" wire:key="status-{{ $status['id'] }}" />
                                    @empty
                                        <p class="text-xs text-muted-foreground">The Employment list is empty.</p>
                                    @endforelse
                                </div>
                                @if ($removedStatuses > 0)
                                    <p class="mt-2 text-xs text-muted-foreground">{{ $removedStatuses }} {{ $removedStatuses === 1 ? 'status this benefit named is' : 'statuses this benefit named are' }} no longer on the Employment list; saving drops {{ $removedStatuses === 1 ? 'it' : 'them' }}.</p>
                                @endif
                            @endif
                            @if ($errorAt('statuses') !== null)
                                <p class="mt-2 text-xs text-danger">{{ $errorAt('statuses') }}</p>
                            @endif
                            <div class="mt-3">
                                <x-kit.select label="Proration" wire:model.live="formProration">
                                    @foreach (BenefitText::PRORATIONS as $value => $label)
                                        <flux:select.option value="{{ $value }}">{{ $label }}</flux:select.option>
                                    @endforeach
                                </x-kit.select>
                            </div>
                        </div>
                    @endif
                </div>

                <div class="grid min-w-0 gap-4">
                    @if ($rulesReady)
                        <div class="space-y-2">
                            <div class="grid gap-4 sm:grid-cols-2">
                                <x-kit.select label="Tax" wire:model.live="formTax">
                                    <flux:select.option value="{{ BenefitRule::TAX_TAXABLE }}">Taxable with the pay</flux:select.option>
                                    <flux:select.option value="{{ BenefitRule::TAX_EXEMPT }}">Tax-exempt, under the yearly ceiling</flux:select.option>
                                    <flux:select.option value="{{ BenefitRule::TAX_DE_MINIMIS }}">De minimis (own cap)</flux:select.option>
                                    <flux:select.option value="{{ BenefitRule::TAX_NOT_INCOME }}">Not compensation (e.g. reimbursement)</flux:select.option>
                                </x-kit.select>
                                <div class="space-y-2">
                                    @if ($formTax === BenefitRule::TAX_DE_MINIMIS)
                                        <flux:label for="benefit-cap">Tax-Exempt Up To</flux:label>
                                        <x-kit.money-input id="benefit-cap" wire:model.live="formCap" aria-label="Tax-exempt up to" :aria-invalid="$errorAt('cap') !== null" />
                                        @if ($errorAt('cap') !== null)
                                            <p class="text-xs text-danger">{{ $errorAt('cap') }}</p>
                                        @endif
                                    @endif
                                </div>
                            </div>
                            <p class="text-xs text-muted-foreground" data-tax-note>{{ match ($formTax) {
                                BenefitRule::TAX_EXEMPT => 'Added after tax, up to the yearly ceiling. Any excess is taxed.',
                                BenefitRule::TAX_DE_MINIMIS => 'Tax-exempt up to its own cap. The rest counts toward the yearly ceiling.',
                                BenefitRule::TAX_NOT_INCOME => 'Never taxed, and never counted toward the ceiling.',
                                default => 'Taxed with the pay.',
                            } }}</p>
                        </div>
                        <div class="space-y-2">
                            <flux:label for="benefit-reference">Approval Reference (optional)</flux:label>
                            <flux:input id="benefit-reference" wire:model="formApprovalReference" maxlength="160" placeholder="The resolution or memo it rests on" />
                            @if ($errorAt('reference') !== null)
                                <p class="text-xs text-danger">{{ $errorAt('reference') }}</p>
                            @endif
                        </div>

                        <div class="flex items-center gap-3 rounded-md border border-border bg-muted/40 p-4">
                            <flux:switch id="benefit-13th" wire:model.live="formCountsToward13th" />
                            <div>
                                <flux:label for="benefit-13th">Counts toward the 13th month</flux:label>
                                <p class="text-xs text-muted-foreground">Off for bonuses and allowances; on only for pay that is part of basic salary.</p>
                            </div>
                        </div>

                        @if (\App\Modules\Payroll\Application\PayrollData::cashOutTypeReady())
                            <div class="space-y-3 rounded-md border border-border bg-muted/40 p-4" data-cash-out-flag>
                                <div class="flex items-center gap-3">
                                    <flux:switch id="benefit-cash-out" wire:model.live="formPaysLeaveCashOut" />
                                    <div>
                                        <flux:label for="benefit-cash-out">Pays leave credit cash conversions</flux:label>
                                        <p class="text-xs text-muted-foreground">Cash conversions and terminal leave are paid as this type. Only one type can do this.</p>
                                    </div>
                                </div>
                                {{-- What one converted day pays; terminal leave is priced the same way from the highest salary on record. --}}
                                @if ($formPaysLeaveCashOut && \App\Modules\Payroll\Application\PayrollData::cashOutValueReady())
                                    <div class="grid gap-3 sm:grid-cols-2" data-cash-out-value>
                                        <x-kit.select label="Value of a Converted Day" wire:model.live="formCashOutDayValue">
                                            @foreach (\App\Modules\Payroll\Domain\Benefits\LeaveCashOutValue::LABELS as $value => $label)<flux:select.option value="{{ $value }}">{{ $label }}</flux:select.option>@endforeach
                                        </x-kit.select>
                                        @if ($formCashOutDayValue === \App\Modules\Payroll\Domain\Benefits\LeaveCashOutValue::FACTOR)
                                            <flux:input label="Factor" wire:model="formCashOutFactor" inputmode="decimal" maxlength="10" data-cash-out-factor />
                                        @endif
                                    </div>
                                    <p class="text-xs text-muted-foreground">
                                        @if ($formCashOutDayValue === \App\Modules\Payroll\Domain\Benefits\LeaveCashOutValue::FACTOR)
                                            Days × the monthly rate × the factor. An employee with no monthly rate is paid at the daily rate. Terminal leave uses the highest monthly rate on record.
                                        @else
                                            Days × the employee's payroll daily rate. Terminal leave uses the daily rate of the highest salary on record.
                                        @endif
                                    </p>
                                @endif
                            </div>
                        @endif

                        @if ($sample !== null)
                            <p class="rounded-md border border-border bg-muted px-4 py-3 text-sm">{{ $sample['text'] }}</p>
                        @endif
                    @else
                        {{-- The catalog has no rule columns yet (the window before the upgrade finishes): the flag alone. --}}
                        <x-kit.select label="Tax" wire:model.live="formTax">
                            <flux:select.option value="{{ BenefitRule::TAX_TAXABLE }}">Taxable with the pay</flux:select.option>
                            <flux:select.option value="{{ BenefitRule::TAX_EXEMPT }}">Tax-exempt, under the yearly ceiling</flux:select.option>
                        </x-kit.select>
                    @endif
                </div>
            </div>

            <div class="sticky -bottom-6 z-10 -mx-6 -mb-6 mt-2 flex flex-col gap-2 border-t border-border bg-inherit px-6 py-3">
                @if ($formError !== null && $formErrorField === null)
                    <p role="alert" class="text-xs text-danger">{{ $formError }}</p>
                @elseif ($formError !== null)
                    <p role="alert" class="text-xs text-danger">Check the field marked above: {{ $formError }}</p>
                @endif
                <div class="flex justify-end gap-2">
                    <flux:button type="button" variant="outline" wire:click="$set('dialogOpen', false)">Cancel</flux:button>
                    <flux:button type="submit" variant="primary" :icon="$editing !== null ? 'pencil' : 'plus'">
                        {{ $editing !== null ? 'Save Changes' : 'Add Incentive Type' }}
                    </flux:button>
                </div>
            </div>
        </form>
    </flux:modal>
    @endif
</div>
