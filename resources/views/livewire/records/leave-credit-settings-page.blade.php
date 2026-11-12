{{--
    Records › Leave Settings — the company-wide rules of the leave-credits
    flow, on three tabs. Leave types: the leave rules (one per leave type, for
    every employee, changed in place and in force from the day it is saved)
    and each leave type's filing and credit settings — ticking a credit
    deduction is what charges attendance to it. Conversion table: the
    minutes-to-days table, FIXED and shown read-only. Signatories: the employees
    who sign the Application for Leave. Every other amount here is HR's, and nothing
    is set per employee. The dialogs sit outside the tabs.
--}}
@php
    use App\Modules\Timekeeping\Domain\Absence\LeaveCreditPolicy;
    use App\Modules\Timekeeping\Domain\Credits\LeaveQuantity;
    use App\Modules\Timekeeping\Domain\Credits\LeaveTypeCredits;
@endphp
<div class="space-y-6">
    <div>
        <h1 class="text-2xl font-bold tracking-tight">Leave Settings</h1>
        <p class="mt-1 text-sm text-muted-foreground">Manage how leave credits are earned and deducted based on each leave type's rules, employee eligibility, and minutes-to-days conversion.</p>
    </div>

    @unless ($canManage)
        <p class="rounded-lg border border-border bg-muted px-4 py-3 text-sm text-muted-foreground">Your role can view these settings. Changing them requires Records Management or Leave Management access to all data.</p>
    @endunless

    <div>
        {{-- The same tab strip Leave Management and User Management draw: a muted role=tablist of
             role=tab buttons; $set fires updatedTab(), which whitelists the value. Only the
             active tab's content is rendered. --}}
        <div class="inline-flex h-auto flex-wrap items-center justify-start rounded-lg bg-muted p-1 text-muted-foreground" role="tablist" aria-label="Leave Settings sections">
            <button
                type="button"
                role="tab"
                aria-selected="{{ $tab === 'types' ? 'true' : 'false' }}"
                wire:click="$set('tab', 'types')"
                class="inline-flex items-center justify-center whitespace-nowrap rounded-md px-3 py-1 text-sm font-medium transition-all {{ $tab === 'types' ? 'bg-card text-foreground shadow-sm' : 'hover:text-foreground' }}"
                data-settings-tab="types"
            >Leave types</button>
            <button
                type="button"
                role="tab"
                aria-selected="{{ $tab === 'table' ? 'true' : 'false' }}"
                wire:click="$set('tab', 'table')"
                class="inline-flex items-center justify-center whitespace-nowrap rounded-md px-3 py-1 text-sm font-medium transition-all {{ $tab === 'table' ? 'bg-card text-foreground shadow-sm' : 'hover:text-foreground' }}"
                data-settings-tab="table"
            >Conversion table</button>
            <button
                type="button"
                role="tab"
                aria-selected="{{ $tab === 'signatories' ? 'true' : 'false' }}"
                wire:click="$set('tab', 'signatories')"
                class="inline-flex items-center justify-center whitespace-nowrap rounded-md px-3 py-1 text-sm font-medium transition-all {{ $tab === 'signatories' ? 'bg-card text-foreground shadow-sm' : 'hover:text-foreground' }}"
                data-settings-tab="signatories"
            >Signatories</button>
        </div>

    @if ($tab === 'types')
    {{-- Leave types: one row per type, with its rule (the one in force today) and its filing and credit settings --}}
    <section class="mt-4 hris-card overflow-hidden" aria-labelledby="types-heading" data-leave-rules data-leave-type-rules>
        <header class="flex items-center justify-between gap-3 border-b border-border px-5 py-4 max-sm:flex-col max-sm:items-start">
            <div class="min-w-0 flex-1">
                <h2 id="types-heading" class="text-base font-bold">Leave types</h2>
                @unless ($rulesReady)
                    <p class="mt-2 text-sm text-muted-foreground">Leave rules can be set once this installation's upgrade finishes.</p>
                @endunless
            </div>
            {{-- Adding, renaming and deleting a leave type need Records access to all data, like every Records list. The leave types have no CSV. --}}
            @if ($canEditTypes)
                <div class="flex shrink-0 flex-wrap items-center gap-2">
                    <flux:button variant="primary" icon="plus" wire:click="openAdd" data-add-leave-type>Add leave type</flux:button>
                </div>
            @endif
        </header>
        @unless ($typesReady)
            <p class="px-5 py-3 text-sm text-muted-foreground">Credit rules can be set once this installation's upgrade finishes.</p>
        @endunless
        <div class="hris-table-wrap">
            <table class="hris-table">
                <thead>
                    <tr>
                        <th>Leave</th>
                        <th>Payment basis</th>
                        <th>Leave rule</th>
                        <th>Credit deductions</th>
                        <th>Cash conversion</th>
                        <th>Year-end treatment</th>
                        <th class="text-right">Action</th>
                    </tr>
                </thead>
                <tbody>
                    @if ($types->isEmpty())
                        <tr wire:key="types-empty"><x-kit.empty-state :colspan="7" title="No leave type yet." description="Add one with its rule and settings in a single step." /></tr>
                    @endif
                    @foreach ($types as $type)
                        {{-- Official business is filed like leave but is time worked: it holds no credits and takes no rule. --}}
                        @continue(! LeaveCreditPolicy::creditable((string) $type->name))
                        @php
                            $rule = $rules[(string) $type->token] ?? null;
                            $typeSettings = $settings[(string) $type->token] ?? null;
                            $holdsCredits = $typeSettings === null || $typeSettings->filedAgainstCredits();
                            $chargedFor = array_filter([$type->charge_late ? 'Late and undertime' : null, $type->charge_absence ? 'Absences' : null]);
                        @endphp
                        <tr wire:key="type-{{ $type->id }}" data-leave-type="{{ $type->token }}" data-leave-rule="{{ $type->token }}" data-leave-filed="{{ $typeSettings->filing ?? LeaveTypeCredits::FILING_CREDITS }}">
                            <td class="font-medium">
                                {{ $type->name }}
                                {{-- Who may use it, when not everyone may (Filing and credit settings). --}}
                                @if ($typeSettings !== null && ! $typeSettings->eligibility->isEveryone())
                                    <span class="block text-xs font-normal text-muted-foreground" data-leave-eligibility>for {{ $typeSettings->eligibility->describe($statusNames) }}</span>
                                @endif
                            </td>
                            <td class="text-sm">
                                @if ($typeSettings === null || $typeSettings->filedAgainstCredits())
                                    {{ LeaveTypeCredits::FILING_LABELS[LeaveTypeCredits::FILING_CREDITS] }}
                                    {{-- The maximum negative balance is 0 unless HR set one; it covers credit deductions and leave approved with pay alike. --}}
                                    @if ((float) ($type->below_zero_limit ?? 0) > 0)
                                        <span class="block text-xs text-muted-foreground" data-leave-below-zero>negative balance up to <span class="num">{{ LeaveQuantity::credits((float) $type->below_zero_limit) }}</span></span>
                                    @endif
                                @elseif ($typeSettings->filing === LeaveTypeCredits::FILING_UNPAID)
                                    <span class="pill pill-muted">{{ LeaveTypeCredits::FILING_LABELS[LeaveTypeCredits::FILING_UNPAID] }}</span>
                                @else
                                    {{ LeaveTypeCredits::FILING_LABELS[$typeSettings->filing] }}
                                    <span class="block text-xs text-muted-foreground">{{ $typeSettings->filingText() }}</span>
                                @endif
                                @if (($typeSettings->dayCount ?? LeaveTypeCredits::DAYS_WORKING) === LeaveTypeCredits::DAYS_CALENDAR)
                                    <span class="block text-xs text-muted-foreground">counts calendar days</span>
                                @endif
                                {{-- The details of leave a request for it asks (Filing and credit settings). --}}
                                @if (($typeSettings->leaveDetails ?? \App\Modules\Timekeeping\Domain\Absence\LeaveApplication::NONE) !== \App\Modules\Timekeeping\Domain\Absence\LeaveApplication::NONE)
                                    <span class="block text-xs text-muted-foreground" data-leave-details-asked>asks {{ \App\Modules\Timekeeping\Domain\Absence\LeaveApplication::SECTION_LABELS[$typeSettings->leaveDetails] }} details</span>
                                @endif
                            </td>
                            <td class="text-sm">
                                <span class="block max-w-xl {{ $rule === null ? 'text-muted-foreground' : '' }}">{{ $rule !== null ? ucfirst($rule->summary) : 'Available immediately; no credits are earned.' }}</span>
                                @if ($rule !== null)<span class="num text-xs text-muted-foreground">since {{ $rule->effective_from->format('d M Y') }}</span>@endif
                            </td>
                            {{-- Credit deductions, cash conversion and the year-end treatment belong to a type paid from earned leave credits; the others hold none. --}}
                            <td class="text-sm">
                                @if (! $holdsCredits || $chargedFor === [])
                                    <span class="text-muted-foreground">—</span>
                                @else
                                    {{ implode(' · ', $chargedFor) }}
                                    <span class="num block text-xs text-muted-foreground">priority {{ (int) $type->charge_order }}</span>
                                    {{-- Each type with credit deductions needs its own priority: a shared one is refused on the next save, so it is flagged here. --}}
                                    @if (($orderClashes[(string) $type->token] ?? []) !== [])
                                        <span class="block text-xs text-danger" data-charge-order-clash>Same priority as {{ implode(', ', $orderClashes[(string) $type->token]) }}</span>
                                    @endif
                                @endif
                            </td>
                            {{-- Cash conversion with its limits, and whether the balance is paid out when an employee leaves. --}}
                            <td class="text-sm">
                                @if ($holdsCredits && ($type->can_cash_out ?? false))
                                    <span class="pill pill-success">Allowed</span>
                                    @foreach ($typeSettings->cashOutLimits->lines() as $line)
                                        <span class="block text-xs text-muted-foreground" data-cash-out-limit>{{ $line }}</span>
                                    @endforeach
                                @else
                                    <span class="text-muted-foreground">—</span>
                                @endif
                                @if ($typeSettings->paidOnSeparation)
                                    <span class="block text-xs text-muted-foreground" data-paid-on-separation-flag>Paid out on separation</span>
                                @endif
                            </td>
                            <td>@if (! $holdsCredits)<span class="text-muted-foreground">—</span>@elseif (($type->year_end ?? 'CARRY') === LeaveTypeCredits::YEAR_END_LAPSE)<span class="pill pill-caution">Expires</span>@else<span class="text-muted-foreground">Carries forward</span>@endif</td>
                            <td class="text-right">
                                {{-- A viewer reads the rule in its cell; only a manager has anything to change. --}}
                                @if ($canManage)
                                    <x-kit.row-actions :label="$type->name">
                                        @if ($rulesReady)
                                            <flux:menu.item icon="clock" wire:click="openRule('{{ $type->id }}')">Leave rule…</flux:menu.item>
                                        @endif
                                        @if ($typesReady)
                                            <flux:menu.item icon="adjustments-horizontal" wire:click="openType('{{ $type->id }}')">Filing and credit settings…</flux:menu.item>
                                        @endif
                                        @if ($canEditTypes)
                                            <flux:menu.item icon="pencil-square" wire:click="openRename('{{ $type->id }}')">Rename…</flux:menu.item>
                                            <flux:menu.item icon="trash" variant="danger" wire:click="askDelete('{{ $type->id }}')">Delete…</flux:menu.item>
                                        @endif
                                    </x-kit.row-actions>
                                @else
                                    <span class="text-muted-foreground">—</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </section>
    @elseif ($tab === 'signatories')
    {{-- The employees who sign the Application for Leave. The head of office differs by office, so it is set on each department or unit. --}}
    <section class="mt-4 hris-card p-5" aria-labelledby="signatories-heading" data-leave-signatories>
        <div class="flex flex-wrap items-start justify-between gap-3">
            {{-- A real basis, so the actions wrap under the text on a phone instead of squeezing it. --}}
            <div class="min-w-0 grow basis-80">
                <h2 id="signatories-heading" class="text-base font-bold">Signatories</h2>
                <p class="mt-1 text-sm text-muted-foreground">
                    Shown on every Application for Leave, with each employee's current name and position. Approved leaves keep the signatories they were approved with.
                    Set the head of each office in
                    @if ($mayOpenDepartments)<a href="{{ route('records.departments') }}" wire:navigate class="underline">Departments &amp; Units</a>@else Departments &amp; Units @endif.
                </p>
            </div>
            @if ($canManage && $signatoriesReady)
                @if ($signatoryRows !== [])
                    <div class="flex items-center gap-2">
                        <flux:button variant="ghost" size="sm" icon="x-mark" wire:click="cancelSignatories" data-signatory-cancel>Cancel</flux:button>
                        <flux:button variant="primary" size="sm" wire:click="saveSignatories" data-signatory-save>Save</flux:button>
                    </div>
                @else
                    <button type="button" wire:click="editSignatories" class="text-sm font-medium text-primary hover:underline" data-signatory-edit>Edit</button>
                @endif
            @endif
        </div>
        @unless ($signatoriesReady)
            <p class="mt-2 text-sm text-muted-foreground">The signatories can be chosen once this installation's upgrade finishes. Until then, the form keeps the officers it shows now.</p>
        @endunless
        @if ($signatoryRows !== [])
            <div class="mt-4 space-y-4">
                @foreach ($signatories as $signatory)
                    @php $signatoryRow = $signatoryRows[$signatory->slot]; @endphp
                    <fieldset class="grid gap-3 sm:grid-cols-[10rem_1fr_1fr]" wire:key="leave-signatory-{{ $signatory->slot }}" data-signatory="{{ $signatory->slot }}">
                        <legend class="sr-only">{{ $signatory->label }}</legend>
                        <p id="signatory-{{ $signatory->slot }}-label" class="pt-2 text-sm font-semibold">{{ $signatory->label }}</p>
                        <div class="min-w-0 space-y-1">
                            <livewire:kit.employee-picker
                                name="signatory-{{ $signatory->slot }}"
                                :value="$signatoryRow['picked']"
                                :active-only="true"
                                labelled-by="signatory-{{ $signatory->slot }}-label"
                                wire:key="signatory-picker-{{ $signatory->slot }}-{{ $signatoryForm[$signatory->slot] ?? '' }}"
                            />
                            @if ($signatoryRow['note'] !== '')
                                <p class="text-xs text-muted-foreground" data-signatory-note>{{ $signatoryRow['note'] }}</p>
                            @endif
                            @if ($signatoryRow['clearable'])
                                <button type="button" class="text-xs text-muted-foreground underline hover:text-foreground" wire:click="clearSignatory('{{ $signatory->slot }}')" data-signatory-clear>Leave blank</button>
                            @endif
                        </div>
                        <div class="min-w-0 sm:pt-2">
                            <p class="text-xs font-medium uppercase tracking-wide text-muted-foreground">Designation</p>
                            <p class="mt-1 text-sm" data-signatory-designation>{{ $signatoryRow['designation'] !== '' ? $signatoryRow['designation'] : '—' }}</p>
                        </div>
                    </fieldset>
                @endforeach
            </div>
        @else
            <dl class="mt-4 grid gap-4 sm:grid-cols-2">
                @foreach ($signatories as $signatory)
                    <div class="min-w-0" wire:key="leave-signatory-view-{{ $signatory->slot }}">
                        <dt class="text-xs font-medium uppercase tracking-wide text-muted-foreground">{{ $signatory->label }}</dt>
                        <dd class="mt-1 text-sm font-semibold">{{ $signatory->name !== '' ? $signatory->name : '—' }}</dd>
                        <dd class="text-sm text-muted-foreground">{{ $signatory->designation !== '' ? $signatory->designation : '—' }}</dd>
                        @if ($canManage && $signatoriesReady && $signatory->employeeId !== '' && ! $signatory->employeeActive)
                            <dd class="mt-1 text-xs text-danger" data-signatory-inactive>No longer active. Edit to choose who signs now.</dd>
                        @elseif ($canManage && $signatoriesReady && $signatory->employeeId === '' && $signatory->name !== '')
                            <dd class="mt-1 text-xs text-muted-foreground" data-signatory-unlinked>No employee chosen yet.</dd>
                        @endif
                    </div>
                @endforeach
            </dl>
        @endif
    </section>
    @elseif ($tab === 'table')
    {{-- The minutes-to-days table: FIXED (ConversionTables) — its rows shown, never edited, always in use. --}}
    <section class="mt-4 hris-card overflow-hidden" aria-labelledby="tables-heading" data-conversion-tables>
        <header class="border-b border-border px-5 py-4">
            <h2 id="tables-heading" class="text-base font-bold">Conversion of Working Hours/Minutes into Fraction of a Day</h2>
        </header>
        @if ($table !== null)
            <div class="space-y-3 px-5 py-4" data-conversion-rows>
                <div class="grid gap-x-6 gap-y-1 text-sm sm:grid-cols-2 lg:grid-cols-4">
                    @foreach ($table['hours'] as $hour => $fraction)
                        <div class="flex justify-between border-b border-border py-1" wire:key="shown-hour-{{ $hour }}"><span class="text-muted-foreground">{{ $hour }} {{ (int) $hour === 1 ? 'hour' : 'hours' }}</span><span class="num">{{ $fraction }}</span></div>
                    @endforeach
                </div>
                <div class="grid gap-x-6 gap-y-1 text-sm sm:grid-cols-3 lg:grid-cols-6">
                    @foreach ($table['minutes'] as $minute => $fraction)
                        <div class="flex justify-between border-b border-border py-1" wire:key="shown-minute-{{ $minute }}"><span class="text-muted-foreground">{{ $minute }} min</span><span class="num">{{ $fraction }}</span></div>
                    @endforeach
                </div>
            </div>
        @endif
    </section>
    @endif
    </div>

    {{-- Leave rule dialog: a leave type's ONE rule, filled in with what is in force today.
         Save puts the change in force from today; there is no draft, no approval and no version to pick.
         STICKY FOOTER, as the eDTR dialogs: the <dialog> is the scroll container and Flux pads it (p-6),
         so the footer is `sticky -bottom-6 -mx-6 -mb-6`; its fill is `bg-inherit` down an unbroken chain
         from the <dialog> (the body wrapper carries it too), and scroll-pb-32 keeps a focused field clear of it.
         WIDE, NOT LONG: this dialog and Add leave type are max-w-6xl, Filing and credit settings max-w-7xl
         (four cards in two balanced columns), and their cards stand in two columns from lg (the partials),
         so a laptop screen holds them with little or no scrolling. --}}
    @if ($canManage)
    <flux:modal wire:model.self="ruleOpen" class="w-full max-w-6xl scroll-pb-32" x-init="$el.querySelector('dialog')?.setAttribute('aria-labelledby', 'leave-rule-heading')">
        <div class="space-y-4 bg-inherit" data-leave-rule-dialog>
            <div class="space-y-1 pr-8">
                <flux:heading size="lg" id="leave-rule-heading">Leave rule · {{ $ruleName }}</flux:heading>
                <flux:text class="text-sm text-muted-foreground">When the leave is acquired and how its credits accrue, the same for every employee.</flux:text>
            </div>
            <div class="flex items-start gap-2 rounded-lg bg-muted px-3 py-2 text-xs text-muted-foreground" data-leave-rule-applies>
                <flux:icon.information-circle variant="micro" class="mt-px size-4 shrink-0" />
                <p>Saving applies this from today, {{ \App\Support\HrisFormat::date($today) }}, to every employee — this month's accrual included, as a month accrues under the rule in force on its last day. Credits already earned and payroll already paid stay as they are.</p>
            </div>

            @include('livewire.records.partials.leave-rule-fields')

            <div class="sticky -bottom-6 z-10 -mx-6 -mb-6 mt-2 flex flex-col gap-2 border-t border-border bg-inherit px-6 py-3">
                @if ($ruleError !== null)
                    <p class="text-sm text-danger" role="alert">{{ $ruleError }}</p>
                @endif
                <div class="flex flex-wrap justify-end gap-2">
                    <flux:button variant="ghost" wire:click="$set('ruleOpen', false)">Cancel</flux:button>
                    <flux:button variant="primary" icon="check" wire:click="saveRule">Save</flux:button>
                </div>
            </div>
        </div>
    </flux:modal>
    @endif

    @if ($canEditTypes)
    {{-- Add leave type: the name, the rule and the settings in ONE form, so nothing is left for a second step,
         in the order a leave is set up: what it is called, how it is filed (the payment basis decides
         what follows), when it is available and how credits are earned, then how the credits are kept,
         deducted and converted — that last section only for a leave paid from earned leave credits.
         The rule applies from today; left as it starts, the type simply has none. The leave rule dialog's sticky footer. --}}
    <flux:modal wire:model.self="addOpen" class="w-full max-w-6xl scroll-pb-32" x-init="$el.querySelector('dialog')?.setAttribute('aria-labelledby', 'add-leave-type-heading')">
        <div class="space-y-5 bg-inherit" data-add-leave-type-dialog>
            <flux:heading size="lg" id="add-leave-type-heading">Add leave type</flux:heading>
            <div class="grid gap-3 lg:grid-cols-2">
                <flux:input label="Name" wire:model="newName" maxlength="160" placeholder="e.g. Special Privilege Leave" />
                <flux:input label="Description" wire:model="newDescription" maxlength="2000" description="Optional. Shown to whoever files the leave." />
            </div>
            @include('livewire.records.partials.leave-type-settings-fields', ['part' => 'filing'])
            <div class="space-y-3 border-t border-border pt-4">
                <div>
                    <h3 class="text-base font-semibold">Leave rule</h3>
                    <p class="mt-1 text-xs text-muted-foreground">When the leave is acquired and how its credits accrue, the same for every employee, from today. Left as it is, the leave is available immediately and no credits are earned; you can change it later from the leave type's menu.</p>
                </div>
                @include('livewire.records.partials.leave-rule-fields')
            </div>
            @if ($ruleCredits)
                <div class="space-y-3 border-t border-border pt-4" data-add-credit-settings>
                    <div>
                        <h3 class="text-base font-semibold">Credit settings</h3>
                        <p class="mt-1 text-xs text-muted-foreground">How the credits are kept, what is deducted from them and whether they may be converted to cash. These apply as soon as the leave type is added.</p>
                    </div>
                    @include('livewire.records.partials.leave-type-settings-fields', ['part' => 'credits'])
                </div>
            @endif
            <div class="sticky -bottom-6 z-10 -mx-6 -mb-6 mt-2 flex flex-col gap-2 border-t border-border bg-inherit px-6 py-3">
                @if ($addError !== null)
                    <p class="text-sm text-danger" role="alert">{{ $addError }}</p>
                @endif
                <div class="flex flex-wrap justify-end gap-2">
                    <flux:button variant="ghost" wire:click="$set('addOpen', false)">Cancel</flux:button>
                    <flux:button variant="primary" icon="plus" wire:click="saveNewType">Add</flux:button>
                </div>
            </div>
        </div>
    </flux:modal>

    {{-- Rename a leave type --}}
    <flux:modal wire:model.self="renameOpen" class="w-full max-w-md" x-init="$el.querySelector('dialog')?.setAttribute('aria-labelledby', 'rename-leave-type-heading')">
        <div class="space-y-4" data-rename-leave-type-dialog>
            <flux:heading size="lg" id="rename-leave-type-heading">Rename leave type</flux:heading>
            <flux:input label="Name" wire:model="newName" maxlength="160" />
            <flux:input label="Description" wire:model="newDescription" maxlength="2000" />
            @if ($addError !== null)
                <p class="text-sm text-danger" role="alert">{{ $addError }}</p>
            @endif
            <div class="flex flex-wrap justify-end gap-2">
                <flux:button variant="ghost" wire:click="$set('renameOpen', false)">Cancel</flux:button>
                <flux:button variant="primary" icon="check" wire:click="saveRename">Save</flux:button>
            </div>
        </div>
    </flux:modal>

    {{-- Delete a leave type only from here: the menu stages it, this dialog's one button deletes (confirmDelete()). --}}
    <flux:modal wire:model.self="deleteOpen" class="w-full max-w-md" x-init="$el.querySelector('dialog')?.setAttribute('aria-labelledby', 'delete-leave-type-heading')">
        <div class="space-y-4" data-delete-leave-type-dialog>
            <flux:heading size="lg" id="delete-leave-type-heading">Delete {{ $deleteTypeName }}?</flux:heading>
            <p class="text-sm text-muted-foreground">A leave type that credits, requests, cash conversions or a leave rule still name cannot be deleted, and you will be told so.</p>
            <div class="flex flex-wrap justify-end gap-2">
                <flux:button variant="ghost" wire:click="$set('deleteOpen', false)">Cancel</flux:button>
                <flux:button variant="danger" icon="trash" wire:click="confirmDelete">Delete</flux:button>
            </div>
        </div>
    </flux:modal>
    @endif

    {{-- Filing and credit settings dialog: leave filing over credit management beside credit deductions over cash conversion, with the leave rule dialog's sticky footer --}}
    <flux:modal wire:model.self="typeOpen" class="w-full max-w-7xl scroll-pb-32" x-init="$el.querySelector('dialog')?.setAttribute('aria-labelledby', 'type-rules-heading')">
        <div class="space-y-4 bg-inherit" data-type-rules-dialog>
            <div class="space-y-1 pr-8">
                <flux:heading size="lg" id="type-rules-heading">Filing and credit settings · {{ $typeName }}</flux:heading>
                <flux:text class="text-sm text-muted-foreground">Saved settings apply to requests filed or approved from then on. When the leave is acquired and how credits accrue are set in its leave rule.</flux:text>
            </div>
            @include('livewire.records.partials.leave-type-settings-fields')
            <div class="sticky -bottom-6 z-10 -mx-6 -mb-6 mt-2 flex flex-wrap justify-end gap-2 border-t border-border bg-inherit px-6 py-3">
                <flux:button variant="ghost" wire:click="$set('typeOpen', false)">Cancel</flux:button>
                <flux:button variant="primary" icon="check" wire:click="saveType">Save</flux:button>
            </div>
        </div>
    </flux:modal>
</div>
