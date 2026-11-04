{{--
    Records › Leave Credits — the company-wide rules of the leave-credits
    flow: each leave type's credit rules, the minutes-to-days conversion
    table versions (drafted by one person, approved by another) and the
    earning presets an employee's policy starts from. Every amount here is
    HR's; nothing is built in.
--}}
@php
    use App\Modules\Timekeeping\Domain\Credits\LeavePolicy;
    use App\Modules\Timekeeping\Domain\Credits\LeaveQuantity;
    use App\Modules\Timekeeping\Domain\Credits\LeaveTypeCredits;
    use App\Modules\Timekeeping\Domain\Credits\MinuteConversion;
    use App\Support\ManilaClock;

    $cell = 'num h-8 w-full min-w-[5rem] rounded-md border border-border bg-background px-2 text-right text-sm focus:outline-none focus:ring-2 focus:ring-ring';
@endphp
<div class="space-y-6">
    <div>
        <h1 class="text-2xl font-bold tracking-tight">Leave Credits</h1>
        <p class="mt-1 text-sm text-muted-foreground">How leave credits are earned and charged: the rules of each leave type, the minutes-to-days table, and the presets an employee's policy starts from. Each employee's own policy is set in Leave Management › Leave policies.</p>
    </div>

    @unless ($canManage)
        <p class="rounded-lg border border-border bg-muted px-4 py-3 text-sm text-muted-foreground">Your role can view these settings. Changing them requires Records Management or Leave Management access to all data.</p>
    @endunless

    {{-- Leave type credit rules --}}
    <section class="hris-card overflow-hidden" aria-labelledby="types-heading" data-leave-type-rules>
        <header class="flex flex-wrap items-start justify-between gap-3 border-b border-border px-5 py-4">
            <div>
                <h2 id="types-heading" class="text-base font-bold">Leave types</h2>
                <p class="mt-1 text-sm text-muted-foreground">Which types earn credits, which may be charged for lateness and undertime or for absences, in what order, and how far below zero a charge may take them.</p>
            </div>
            <flux:button variant="ghost" :href="route('records.list', ['slug' => 'leave-types'])" wire:navigate>Leave Settings</flux:button>
        </header>
        @unless ($typesReady)
            <p class="px-5 py-3 text-sm text-muted-foreground">Credit rules can be set once this installation's upgrade finishes.</p>
        @endunless
        <div class="hris-table-wrap">
            <table class="hris-table">
                <thead>
                    <tr>
                        <th>Leave</th>
                        <th>Unit</th>
                        <th>Earns credits</th>
                        <th>Charged for lateness</th>
                        <th>Charged for absences</th>
                        <th class="text-right">Charge order</th>
                        <th class="text-right">Below zero</th>
                        <th>Cash-out</th>
                        <th>Year end</th>
                        <th class="text-right">Action</th>
                    </tr>
                </thead>
                <tbody>
                    @if ($types->isEmpty())
                        <tr wire:key="types-empty"><x-kit.empty-state :colspan="10" title="No leave type yet. Add one in Leave Settings." /></tr>
                    @endif
                    @foreach ($types as $type)
                        <tr wire:key="type-{{ $type->id }}" data-leave-type="{{ $type->token }}">
                            <td class="font-medium">{{ $type->name }}</td>
                            <td>{{ LeaveTypeCredits::UNIT_LABELS[$type->unit ?? 'DAYS'] ?? $type->unit }}</td>
                            <td>@if ($type->earns_credits)<span class="pill pill-success">Earns</span>@else<span class="text-muted-foreground">—</span>@endif</td>
                            <td>@if ($type->charge_late)<span class="pill pill-primary">Charged</span>@else<span class="text-muted-foreground">—</span>@endif</td>
                            <td>@if ($type->charge_absence)<span class="pill pill-primary">Charged</span>@else<span class="text-muted-foreground">—</span>@endif</td>
                            <td class="num text-right">{{ $type->charge_late || $type->charge_absence ? (int) $type->charge_order : '—' }}</td>
                            <td class="num text-right">{{ LeaveQuantity::format((float) ($type->below_zero_limit ?? 0)) }}</td>
                            <td>@if ($type->can_cash_out ?? false)<span class="pill pill-success">Allowed</span>@else<span class="text-muted-foreground">—</span>@endif</td>
                            <td>@if (($type->year_end ?? 'CARRY') === LeaveTypeCredits::YEAR_END_LAPSE)<span class="pill pill-caution">Lapses</span>@else<span class="text-muted-foreground">Carries</span>@endif</td>
                            <td class="text-right">
                                @if ($canManage && $typesReady)
                                    <x-kit.row-actions :label="$type->name">
                                        <flux:menu.item icon="pencil-square" wire:click="openType('{{ $type->id }}')">Edit credit rules</flux:menu.item>
                                    </x-kit.row-actions>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </section>

    {{-- Conversion tables --}}
    <section class="hris-card overflow-hidden" aria-labelledby="tables-heading" data-conversion-tables>
        <header class="flex flex-wrap items-start justify-between gap-3 border-b border-border px-5 py-4">
            <div>
                <h2 id="tables-heading" class="text-base font-bold">Minutes to days</h2>
                <p class="mt-1 text-sm text-muted-foreground">How late and undertime minutes become fractions of a day when they are charged to credits. A version is prepared by one person and approved by another; the approved version in force on the day decides.</p>
            </div>
            @if ($canManage && $tablesReady)
                <flux:button variant="primary" icon="plus" wire:click="openTable">Add version</flux:button>
            @endif
        </header>
        @unless ($tablesReady)
            <p class="px-5 py-3 text-sm text-muted-foreground">Conversion tables can be added once this installation's upgrade finishes.</p>
        @endunless
        <div class="hris-table-wrap">
            <table class="hris-table">
                <thead>
                    <tr>
                        <th>Effective from</th>
                        <th class="text-right">Hours a day</th>
                        <th class="text-right">Decimals</th>
                        <th>Rounding</th>
                        <th>Reference</th>
                        <th>Prepared by</th>
                        <th>Status</th>
                        <th class="text-right">Action</th>
                    </tr>
                </thead>
                <tbody>
                    @if ($tables->isEmpty())
                        <tr wire:key="tables-empty"><x-kit.empty-state :colspan="8" title="No conversion table yet. Until one is approved, nothing is charged to credits." /></tr>
                    @endif
                    @foreach ($tables as $row)
                        @php
                            $rowFrom = $row->effective_from->format('Y-m-d');
                            $rowInForce = $inForce !== null && $row->id === $inForce->id;
                            $rowShown = $shown !== null && $row->id === $shown->id;
                            $mine = $userId !== null && $row->created_by === (int) $userId;
                        @endphp
                        <tr wire:key="table-{{ $row->id }}" @if ($rowShown) aria-current="true" class="bg-primary-soft/40" @endif>
                            <td class="num whitespace-nowrap">{{ $row->effective_from->format('d M Y') }}</td>
                            <td class="num text-right">{{ LeaveQuantity::format($row->hours_per_day) }}</td>
                            <td class="num text-right">{{ $row->decimals }}</td>
                            <td>{{ MinuteConversion::ROUNDING_LABELS[$row->rounding] ?? $row->rounding }}</td>
                            <td class="text-muted-foreground">{{ $row->reference ?? '—' }}</td>
                            <td>{{ $row->created_by_name ?? 'An unnamed account' }} <span class="num text-muted-foreground">· {{ $row->created_at->setTimezone(ManilaClock::TZ)->format('d M Y') }}</span></td>
                            <td>
                                @if ($row->status === 'DRAFT')
                                    <span class="pill pill-caution">Draft</span>
                                @elseif ($rowFrom > $today)
                                    <span class="pill pill-primary">Upcoming</span>
                                @elseif ($rowInForce)
                                    <span class="pill pill-success">Current</span>
                                @else
                                    <span class="pill pill-muted">Superseded</span>
                                @endif
                                @if ($rowShown)<span class="pill pill-primary ml-1">In view</span>@endif
                            </td>
                            <td class="text-right">
                                <x-kit.row-actions :label="'Conversion table from '.$row->effective_from->format('d M Y')">
                                    @unless ($rowShown)
                                        <flux:menu.item icon="eye" wire:click="viewTable('{{ $row->id }}')">View rows</flux:menu.item>
                                    @endunless
                                    @if ($canManage && $row->status === 'DRAFT')
                                        <flux:menu.item icon="pencil-square" wire:click="openTable('{{ $row->id }}')">Edit draft</flux:menu.item>
                                        @if (! $mine)
                                            <flux:menu.item icon="check" wire:click="approveTable('{{ $row->id }}')">Approve</flux:menu.item>
                                        @endif
                                        <flux:menu.item icon="trash" variant="danger" wire:click="deleteTable('{{ $row->id }}')">Discard draft</flux:menu.item>
                                    @endif
                                </x-kit.row-actions>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @if ($shown !== null)
            <div class="space-y-3 border-t border-border px-5 py-4" data-conversion-rows>
                <p class="text-sm">
                    <span class="font-semibold">Rows of the version from {{ $shown->effective_from->format('d M Y') }}</span>
                    <span class="text-muted-foreground">· {{ LeaveQuantity::format($shown->hours_per_day) }} hours a day · {{ $shown->decimals }} decimals · {{ MinuteConversion::ROUNDING_LABELS[$shown->rounding] ?? $shown->rounding }}</span>
                    @if ($shown->status === 'DRAFT' && $shown->created_by_name)
                        <span class="text-muted-foreground">· prepared by {{ $shown->created_by_name }}, waiting for approval</span>
                    @elseif ($shown->approved_by_name)
                        <span class="text-muted-foreground">· approved by {{ $shown->approved_by_name }}</span>
                    @endif
                </p>
                @if ($differences === [])
                    <p class="text-xs text-muted-foreground">Every row is what the formula gives at these settings.</p>
                @else
                    <p class="text-xs text-foreground" data-conversion-differences>
                        <span class="pill pill-caution">{{ count($differences) }} {{ count($differences) === 1 ? 'row differs' : 'rows differ' }} from the formula</span>
                        {{ implode('; ', array_map(static fn (array $d): string => $d['value'].' '.($d['unit'] === 'hours' ? ($d['value'] === 1 ? 'hour' : 'hours') : 'min').': table '.$d['table'].', formula '.$d['formula'], array_slice($differences, 0, 8))) }}{{ count($differences) > 8 ? '; …' : '' }}
                    </p>
                @endif
                <div class="grid gap-x-6 gap-y-1 text-sm sm:grid-cols-2 lg:grid-cols-4">
                    @foreach ($shown->rows['hours'] ?? [] as $hour => $fraction)
                        <div class="flex justify-between border-b border-border py-1" wire:key="shown-hour-{{ $hour }}"><span class="text-muted-foreground">{{ $hour }} {{ (int) $hour === 1 ? 'hour' : 'hours' }}</span><span class="num">{{ $fraction }}</span></div>
                    @endforeach
                </div>
                <div class="grid gap-x-6 gap-y-1 text-sm sm:grid-cols-3 lg:grid-cols-6">
                    @foreach ($shown->rows['minutes'] ?? [] as $minute => $fraction)
                        <div class="flex justify-between border-b border-border py-1" wire:key="shown-minute-{{ $minute }}"><span class="text-muted-foreground">{{ $minute }} min</span><span class="num">{{ $fraction }}</span></div>
                    @endforeach
                </div>
            </div>
        @endif
    </section>

    {{-- Earning presets --}}
    <section class="hris-card overflow-hidden" aria-labelledby="presets-heading" data-leave-presets>
        <header class="flex flex-wrap items-start justify-between gap-3 border-b border-border px-5 py-4">
            <div>
                <h2 id="presets-heading" class="text-base font-bold">Policy presets</h2>
                <p class="mt-1 text-sm text-muted-foreground">Named sets of earning and charge rules. An employee's policy is copied from one in Leave Management, then approved; changing a preset changes nobody's policy.</p>
            </div>
            @if ($canManage && $presetsReady)
                <flux:button variant="primary" icon="plus" wire:click="openPreset">Add preset</flux:button>
            @endif
        </header>
        @unless ($presetsReady)
            <p class="px-5 py-3 text-sm text-muted-foreground">Presets can be added once this installation's upgrade finishes.</p>
        @endunless
        <div class="hris-table-wrap">
            <table class="hris-table">
                <thead>
                    <tr><th>Preset</th><th>Rules</th><th>Added by</th><th class="text-right">Action</th></tr>
                </thead>
                <tbody>
                    @if ($presets->isEmpty())
                        <tr wire:key="presets-empty"><x-kit.empty-state :colspan="4" title="No preset yet." /></tr>
                    @endif
                    @foreach ($presets as $preset)
                        <tr wire:key="preset-{{ $preset->id }}">
                            <td class="font-medium">{{ $preset->name }}</td>
                            <td class="text-sm text-muted-foreground">{{ $preset->summary }}</td>
                            <td>{{ $preset->created_by_name ?? 'An unnamed account' }}</td>
                            <td class="text-right">
                                @if ($canManage)
                                    <x-kit.row-actions :label="$preset->name">
                                        <flux:menu.item icon="pencil-square" wire:click="openPreset('{{ $preset->id }}')">Edit</flux:menu.item>
                                        <flux:menu.item icon="trash" variant="danger" wire:click="deletePreset('{{ $preset->id }}')">Delete</flux:menu.item>
                                    </x-kit.row-actions>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </section>

    {{-- Leave type rules dialog --}}
    <flux:modal wire:model.self="typeOpen" class="w-full max-w-lg" x-init="$el.querySelector('dialog')?.setAttribute('aria-labelledby', 'type-rules-heading')">
        <div class="space-y-4" data-type-rules-dialog>
            <flux:heading size="lg" id="type-rules-heading">Credit rules · {{ $typeName }}</flux:heading>
            <flux:checkbox wire:model="typeForm.earns_credits" label="Earns credits under an employee's policy" />
            <x-kit.select label="Kept in" wire:model="typeForm.unit">
                @foreach (LeaveTypeCredits::UNIT_LABELS as $unit => $label)<flux:select.option value="{{ $unit }}">{{ $label }}</flux:select.option>@endforeach
            </x-kit.select>
            <flux:checkbox wire:model="typeForm.charge_late" label="May be charged for lateness and undertime" />
            <flux:checkbox wire:model="typeForm.charge_absence" label="May be charged for absences" />
            <flux:input label="Charge order" type="number" min="0" max="999" step="1" wire:model="typeForm.charge_order" description="Among the types that may be charged, the lowest number is charged first." />
            <flux:input label="Below zero limit" type="number" min="0" step="0.0001" wire:model="typeForm.below_zero_limit" description="How far below zero a charge may take this type. 0 keeps it at zero or above." />
            <flux:checkbox wire:model="typeForm.can_cash_out" label="May be cashed out" description="Employees may ask to turn credits of this type into pay, within their policy's yearly limit." />
            <x-kit.select label="At year end" wire:model="typeForm.year_end">
                @foreach (LeaveTypeCredits::YEAR_END_LABELS as $value => $label)<flux:select.option value="{{ $value }}">{{ $label }}</flux:select.option>@endforeach
            </x-kit.select>
            <p class="-mt-2 text-xs text-muted-foreground">A yearly grant lapses: whatever is left above zero at the year end is dropped. A balance below zero carries either way.</p>
            <div class="flex flex-wrap justify-end gap-2">
                <flux:button variant="ghost" wire:click="$set('typeOpen', false)">Cancel</flux:button>
                <flux:button variant="primary" icon="check" wire:click="saveType">Save rules</flux:button>
            </div>
        </div>
    </flux:modal>

    {{-- Conversion table dialog --}}
    <flux:modal wire:model.self="tableOpen" class="w-full max-w-3xl" x-init="$el.querySelector('dialog')?.setAttribute('aria-labelledby', 'table-heading')">
        <div class="space-y-4" data-conversion-dialog>
            <flux:heading size="lg" id="table-heading">{{ $tableId === null ? 'New conversion table' : 'Edit conversion table draft' }}</flux:heading>
            <flux:text class="text-sm text-muted-foreground">Set the hours, decimals and rounding, fill the rows from the formula, then change any row by hand. The draft takes effect once approved.</flux:text>
            <div class="grid gap-3 sm:grid-cols-2">
                <div class="space-y-2">
                    <flux:label>Effective from</flux:label>
                    <x-kit.date-field wire:model="tableForm.effective_from" aria-label="Effective from" />
                </div>
                <flux:input label="Reference" wire:model="tableForm.reference" maxlength="160" placeholder="e.g. Table II, 8-hour day" />
                <flux:input label="Hours in a working day" type="number" min="1" max="24" step="0.25" wire:model="tableForm.hours_per_day" />
                <flux:input label="Decimals" type="number" min="0" max="6" step="1" wire:model="tableForm.decimals" />
                <x-kit.select label="When the next digit is a half" wire:model="tableForm.rounding">
                    @foreach (MinuteConversion::ROUNDING_LABELS as $key => $label)<flux:select.option value="{{ $key }}">{{ $label }}</flux:select.option>@endforeach
                </x-kit.select>
                <div class="flex items-end">
                    <flux:button variant="outline" icon="calculator" wire:click="fillFromFormula">Fill rows from the formula</flux:button>
                </div>
            </div>
            @if ($tableError)
                <p role="alert" class="text-sm text-danger">{{ $tableError }}</p>
            @endif
            <div>
                <p class="mb-2 text-sm font-semibold">Hours</p>
                <div class="grid gap-2 sm:grid-cols-4">
                    @foreach ($tableHours as $hour => $fraction)
                        <label class="flex items-center gap-2 text-sm" wire:key="hour-{{ $hour }}">
                            <span class="w-14 shrink-0 text-muted-foreground">{{ $hour }} h</span>
                            <input type="text" inputmode="decimal" class="{{ $cell }}" wire:model="tableHours.{{ $hour }}" aria-label="{{ $hour }} hours" />
                        </label>
                    @endforeach
                </div>
            </div>
            <div>
                <p class="mb-2 text-sm font-semibold">Minutes</p>
                <div class="grid gap-2 sm:grid-cols-4 lg:grid-cols-6">
                    @foreach ($tableMinutes as $minute => $fraction)
                        <label class="flex items-center gap-2 text-sm" wire:key="minute-{{ $minute }}">
                            <span class="w-12 shrink-0 text-muted-foreground">{{ $minute }}</span>
                            <input type="text" inputmode="decimal" class="{{ $cell }}" wire:model="tableMinutes.{{ $minute }}" aria-label="{{ $minute }} minutes" />
                        </label>
                    @endforeach
                </div>
            </div>
            <div class="flex flex-wrap justify-end gap-2">
                <flux:button variant="ghost" wire:click="$set('tableOpen', false)">Cancel</flux:button>
                <flux:button variant="primary" icon="check" wire:click="saveTable">Save draft</flux:button>
            </div>
        </div>
    </flux:modal>

    {{-- Preset dialog --}}
    <flux:modal wire:model.self="presetOpen" class="w-full max-w-3xl" x-init="$el.querySelector('dialog')?.setAttribute('aria-labelledby', 'preset-heading')">
        <div class="space-y-4" data-preset-dialog>
            <flux:heading size="lg" id="preset-heading">{{ $presetId === null ? 'New preset' : 'Edit preset' }}</flux:heading>
            <flux:input label="Preset name" wire:model="presetForm.name" maxlength="120" placeholder="e.g. Regular employees" />
            @include('livewire.leaves.partials.policy-fields', ['form' => 'presetForm', 'addRow' => 'addPresetRow', 'removeRow' => 'removePresetRow', 'earningTypes' => $earningTypes, 'types' => $types])
            @if ($presetError)
                <p role="alert" class="text-sm text-danger">{{ $presetError }}</p>
            @endif
            <div class="flex flex-wrap justify-end gap-2">
                <flux:button variant="ghost" wire:click="$set('presetOpen', false)">Cancel</flux:button>
                <flux:button variant="primary" icon="check" wire:click="savePreset">Save preset</flux:button>
            </div>
        </div>
    </flux:modal>
</div>
