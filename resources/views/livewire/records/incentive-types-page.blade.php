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
    <div>
        <h1 class="text-2xl font-bold tracking-tight">Incentives &amp; Bonuses</h1>
        <p class="text-sm text-muted-foreground">
            The allowances, incentives and bonuses a payroll can pay: how much, how often, who qualifies and how each is taxed.
            A scheduled one is proposed on every draft payroll it is due on; a payroll officer can still change the amount.
        </p>
    </div>

    <section class="hris-card overflow-hidden">
        <header class="flex flex-wrap items-center gap-3 border-b border-border px-5 py-4">
            @unless ($rulesReady)
                <p class="w-full text-xs text-muted-foreground">Benefit rules can be set up once this installation's upgrade finishes; every type is typed per payroll until then.</p>
            @endunless
            @unless ($canEdit)
                <p class="w-full text-xs text-muted-foreground">Your role can view this catalog. Adding, editing and deleting require all-data access to Records Management or Payroll.</p>
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
                        Add incentive type
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
                        <th>How often</th>
                        <x-kit.sort-header field="taxable" label="Tax" />
                        <th>Who qualifies</th>
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
                <flux:button variant="primary" wire:click="deleteConfirmed">Delete</flux:button>
            </div>
        </div>
    </flux:modal>

    <flux:modal
        wire:model.self="dialogOpen"
        class="w-full max-w-lg"
        x-init="$el.querySelector('dialog')?.setAttribute('aria-labelledby', 'incentive-type-heading')"
    >
        <div class="space-y-4">
            <div class="space-y-2">
                <flux:heading size="lg" id="incentive-type-heading">
                    {{ $editing !== null ? 'Edit incentive type' : 'Add incentive type' }}
                    @if ($editing !== null)
                        <span class="num ml-2 text-sm font-normal text-muted-foreground">{{ $editing->code }}</span>
                    @endif
                </flux:heading>
                @if ($editing !== null)
                    <flux:text class="text-sm text-muted-foreground">
                        Saving re-prices every open payroll that pays it; posted payslips keep what they paid.
                    </flux:text>
                @endif
            </div>
            <form class="grid gap-4" wire:submit="save">
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
                        <x-kit.select label="Amount" wire:model.live="formAmountType">
                            @foreach (BenefitText::AMOUNT_TYPES as $value => $label)
                                <flux:select.option value="{{ $value }}">{{ $label }}</flux:select.option>
                            @endforeach
                        </x-kit.select>
                        <div class="space-y-2">
                            @if (! in_array($formAmountType, [BenefitRule::AMOUNT_TYPED, BenefitRule::AMOUNT_MONTH], true))
                                <flux:label for="benefit-amount">{{ $formAmountType === BenefitRule::AMOUNT_PERCENT ? 'Percent of the monthly salary' : ($formAmountType === BenefitRule::AMOUNT_DAILY ? 'Amount per day' : 'Amount') }}</flux:label>
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
                            <x-kit.select label="How often" wire:model.live="formFrequency" :disabled="$formAmountType === BenefitRule::AMOUNT_TYPED">
                                @foreach (BenefitText::FREQUENCIES as $value => $label)
                                    <flux:select.option value="{{ $value }}">{{ $label }}</flux:select.option>
                                @endforeach
                            </x-kit.select>
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
                        <p class="text-xs text-muted-foreground">An amount typed per payroll is never proposed on its own: the officer types it on each payroll that pays it.</p>
                    @elseif ($formFrequency === BenefitRule::FREQUENCY_ONCE)
                        <p class="text-xs text-muted-foreground">A one-time benefit is never proposed on its own: the officer types its amount on the payroll that pays it.</p>
                    @endif

                    <div class="rounded-md border border-border bg-muted/40 p-4">
                        <flux:label>Who qualifies</flux:label>
                        <div class="mt-3 grid gap-3 sm:grid-cols-2">
                            <x-kit.select label="Employment status" wire:model.live="formStatusMode">
                                <flux:select.option value="ALL">All employees</flux:select.option>
                                <flux:select.option value="SOME">Only these statuses</flux:select.option>
                            </x-kit.select>
                            <div class="space-y-2">
                                <flux:label for="benefit-service">Minimum months of service</flux:label>
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

                    <div class="grid gap-4 sm:grid-cols-2">
                        <x-kit.select label="Tax" wire:model.live="formTax">
                            <flux:select.option value="{{ BenefitRule::TAX_TAXABLE }}">Taxable with the pay</flux:select.option>
                            <flux:select.option value="{{ BenefitRule::TAX_EXEMPT }}">Tax-exempt, under the yearly ceiling</flux:select.option>
                            <flux:select.option value="{{ BenefitRule::TAX_DE_MINIMIS }}">De minimis (own cap)</flux:select.option>
                            <flux:select.option value="{{ BenefitRule::TAX_NOT_INCOME }}">Not income</flux:select.option>
                        </x-kit.select>
                        <div class="space-y-2">
                            @if ($formTax === BenefitRule::TAX_DE_MINIMIS)
                                <flux:label for="benefit-cap">Tax-free up to</flux:label>
                                <x-kit.money-input id="benefit-cap" wire:model.live="formCap" aria-label="Tax-free up to" :aria-invalid="$errorAt('cap') !== null" />
                                @if ($errorAt('cap') !== null)
                                    <p class="text-xs text-danger">{{ $errorAt('cap') }}</p>
                                @endif
                            @endif
                        </div>
                    </div>
                    <div class="space-y-2">
                        <flux:label for="benefit-reference">Approval reference (optional)</flux:label>
                        <flux:input id="benefit-reference" wire:model="formApprovalReference" maxlength="160" placeholder="The resolution or memo it rests on" />
                        @if ($errorAt('reference') !== null)
                            <p class="text-xs text-danger">{{ $errorAt('reference') }}</p>
                        @endif
                    </div>
                    <p class="text-xs text-muted-foreground">A taxable amount is taxed with the pay. A tax-exempt one is added after the tax, up to the yearly ceiling of exempt benefits; a de minimis one is tax-free up to its own cap, the rest counting toward that ceiling; an amount that is not income is never taxed or counted.</p>

                    <div class="flex items-center gap-3 rounded-md border border-border bg-muted/40 p-4">
                        <flux:switch id="benefit-13th" wire:model.live="formCountsToward13th" />
                        <div>
                            <flux:label for="benefit-13th">Counts toward the 13th month</flux:label>
                            <p class="text-xs text-muted-foreground">Off for bonuses and allowances; on only for pay that is part of basic salary.</p>
                        </div>
                    </div>

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

                @if ($formError !== null && $formErrorField === null)
                    <p class="text-xs text-danger">{{ $formError }}</p>
                @elseif ($formError !== null)
                    <p class="text-xs text-danger">Check the field marked above: {{ $formError }}</p>
                @endif

                <div>
                    <flux:button type="submit" variant="primary" :icon="$editing !== null ? 'pencil' : 'plus'">
                        {{ $editing !== null ? 'Save changes' : 'Add incentive type' }}
                    </flux:button>
                </div>
            </form>
        </div>
    </flux:modal>
    @endif
</div>
