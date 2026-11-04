{{-- Annual allocations and leave usage share the owner's balances; only HR can post credit entries. --}}
@php
    use App\Modules\Timekeeping\Application\Commands\RecordLeaveCredit;
    use App\Modules\Timekeeping\Domain\Absence\LeaveCreditPolicy;
    use App\Support\EmployeeName;
    use App\Support\ManilaClock;

    $fmt = static fn (?float $value): string => $value === null ? '—' : LeaveCreditPolicy::format($value);
    $exportQuery = ['employee' => $employeeId, 'year' => $year, 'type' => $type];
@endphp
<div class="space-y-4" data-leave-ledger>
    <section class="hris-card p-5">
        <div class="flex flex-wrap items-end gap-4">
            @if ($canPick)
                <div class="w-full min-w-0 sm:max-w-xs">
                    <p id="ledger-employee-label" class="mb-2 text-sm font-medium">Employee</p>
                    <livewire:kit.employee-picker name="ledger-employee" :value="$employeeId"
                        labelled-by="ledger-employee-label" wire:key="ledger-employee-{{ $employeeId }}" />
                </div>
            @else
                <div class="w-full min-w-0 sm:w-auto sm:flex-1">
                    <h2 class="text-base font-bold">{{ $own ? 'My leave ledger' : 'Leave ledger' }}</h2>
                    <p class="break-words text-sm text-muted-foreground">{{ $employee ? EmployeeName::display($employee) : 'No employee record linked.' }}</p>
                </div>
            @endif
            <div class="w-28">
                <flux:input label="Calendar year" type="number" min="2000" max="2100" wire:model.live.debounce.500ms="year" />
            </div>
            @if ($employee && ! $own)
                <div class="ml-auto flex flex-wrap gap-2">
                    <x-kit.export-button :href="route('leaves.ledger.export', [...$exportQuery, 'section' => 'balances'])" />
                    @if ($canEdit)
                        <flux:button variant="primary" icon="plus" wire:click="openEntry">Record credits</flux:button>
                    @endif
                </div>
            @endif
        </div>
        <p class="mt-4 text-xs text-muted-foreground">
            January–December allocations. HR enters earned credits and optional carry-forward amounts manually.
            Leave taken is counted through {{ $today }}; approved future leave is shown as booked.
        </p>
    </section>

    @if ($employee)
        <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
            @forelse ($balances as $balance)
                <section class="hris-card min-w-0 p-5" wire:key="ledger-balance-{{ $balance->request_type }}">
                    <div class="flex items-start justify-between gap-3">
                        <h3 class="break-words font-semibold">{{ $balance->label }}</h3>
                        <div class="shrink-0 text-right">
                            <p class="num text-xl font-bold">{{ $fmt($balance->available) }}</p>
                            <p class="text-xs text-muted-foreground">days available</p>
                        </div>
                    </div>
                    <dl class="mt-4 grid grid-cols-2 gap-4 text-sm">
                        <div><dt class="text-muted-foreground">Opening balance</dt><dd class="num mt-1 font-medium">{{ $fmt($balance->opening) }}</dd></div>
                        <div><dt class="text-muted-foreground">Manual accrual</dt><dd class="num mt-1 font-medium">{{ $fmt($balance->accrued) }}</dd></div>
                        <div><dt class="text-muted-foreground">Paid leave taken</dt><dd class="num mt-1 font-medium">{{ $fmt($balance->paid_taken) }}</dd></div>
                        <div><dt class="text-muted-foreground">Closing balance</dt><dd class="num mt-1 font-medium">{{ $fmt($balance->closing) }}</dd></div>
                    </dl>
                    <dl class="mt-4 space-y-1 border-t border-border pt-3 text-xs text-muted-foreground">
                        <div class="flex justify-between gap-3"><dt>Carried forward</dt><dd class="num">{{ $fmt($balance->carried) }}</dd></div>
                        <div class="flex justify-between gap-3"><dt>Adjustments</dt><dd class="num">{{ $fmt($balance->adjustments) }}</dd></div>
                        <div class="flex justify-between gap-3"><dt>Unpaid leave taken</dt><dd class="num">{{ $fmt($balance->unpaid_taken) }}</dd></div>
                        <div class="flex justify-between gap-3"><dt>Booked paid leave</dt><dd class="num">{{ $fmt($balance->booked_paid) }}</dd></div>
                        <div class="flex justify-between gap-3"><dt>Booked unpaid leave</dt><dd class="num">{{ $fmt($balance->booked_unpaid) }}</dd></div>
                    </dl>
                </section>
            @empty
                <p class="text-sm text-muted-foreground">No leave types or balances to show for this year.</p>
            @endforelse
        </div>
        <p class="text-xs text-muted-foreground">
            Closing = opening + manual accrual + carried forward + adjustments − paid leave taken.
            Available = closing − booked paid leave. Unpaid leave does not reduce credits.
            Whole and half calendar days count; a request spanning New Year belongs to its start year.
        </p>

        <section class="hris-card overflow-hidden">
            <header class="flex flex-wrap items-center gap-3 border-b border-border px-5 py-4">
                <div class="min-w-0 flex-1">
                    <h2 class="text-base font-bold">Credit history</h2>
                    <p class="text-xs text-muted-foreground">Recorded entries for {{ $year }}. Existing allocations have no earlier movement history.</p>
                </div>
                <x-kit.filter-select label="History leave type" class="w-full sm:w-52" wire:model.live="type">
                    <flux:select.option value="ALL">All leave types</flux:select.option>
                    @foreach ($historyTypes as $key => $label)
                        <flux:select.option value="{{ $key }}">{{ $label }}</flux:select.option>
                    @endforeach
                </x-kit.filter-select>
                @if (! $own)
                    <x-kit.export-button :href="route('leaves.ledger.export', $exportQuery)" />
                @endif
            </header>
            <div class="hris-table-wrap hris-table-scroll">
                <table class="hris-table">
                    <thead><tr><th>Recorded</th><th>Leave type</th><th>Entry</th><th class="text-right">Days</th><th>Note</th></tr></thead>
                    <tbody>
                        @forelse ($rows as $row)
                            <tr wire:key="ledger-entry-{{ $row->id }}">
                                <td class="num whitespace-nowrap">{{ $row->recorded_at->setTimezone(ManilaClock::TZ)->format('M d, Y, g:i A') }}</td>
                                <td class="whitespace-nowrap">{{ $row->label }}</td>
                                <td class="whitespace-nowrap">
                                    {{ RecordLeaveCredit::LABELS[$row->kind] ?? $row->kind }}
                                    @if ($row->source_year)<span class="num text-xs text-muted-foreground">from {{ $row->source_year }}</span>@endif
                                </td>
                                <td class="num whitespace-nowrap text-right">{{ $row->days > 0 ? '+' : '' }}{{ $fmt($row->days) }}</td>
                                <td class="min-w-48 max-w-sm break-words">{{ $row->note }}</td>
                            </tr>
                        @empty
                            <tr wire:key="ledger-empty"><x-kit.empty-state :colspan="5" title="No credit entries for these filters." /></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <x-kit.pagination :paginator="$rows" noun="entries" />
        </section>
    @elseif ($canPick)
        <p class="px-5 py-8 text-center text-sm text-muted-foreground">Choose an employee to view their leave ledger.</p>
    @endif

    @if ($canEdit)
        <flux:modal wire:model.self="entryOpen" class="w-full max-w-lg"
            x-init="$el.querySelector('dialog')?.setAttribute('aria-labelledby', 'ledger-entry-heading')">
            <form class="space-y-4" wire:submit="saveEntry">
                <div>
                    <h2 id="ledger-entry-heading" class="text-lg font-semibold">Record leave credits · {{ $entryYear }}</h2>
                    <p class="text-sm text-muted-foreground">{{ $employee ? EmployeeName::display($employee) : '' }}</p>
                </div>
                <x-kit.select label="Leave type" wire:model="entryType">
                    @foreach ($types as $key => $label)<flux:select.option value="{{ $key }}">{{ $label }}</flux:select.option>@endforeach
                </x-kit.select>
                <x-kit.select label="Entry type" wire:model.live="kind">
                    @foreach (RecordLeaveCredit::KINDS as $key => $label)<flux:select.option value="{{ $key }}">{{ $label }}</flux:select.option>@endforeach
                </x-kit.select>
                @if ($kind === 'CARRY_FORWARD')
                    <p class="rounded-md bg-muted p-3 text-sm">For an employee with a leave policy, {{ $entryYear - 1 }}'s whole balance is carried into {{ $entryYear }} automatically once that year is posted, and a typed carry is refused. Use this only for an employee the posting never reaches; correct a carry with an adjustment.</p>
                @elseif ($kind === 'ADJUSTMENT')
                    <p class="text-sm text-muted-foreground">Use a positive amount to add credits or a negative amount to correct them. Earlier entries remain in the history.</p>
                @elseif ($kind === 'OPENING')
                    <p class="text-sm text-muted-foreground">The first allocation for this leave type and year. Use an adjustment to correct an existing allocation.</p>
                @endif
                <flux:input label="Days" type="number" step="0.0001" max="999999.9999" :min="$kind === 'ADJUSTMENT' ? '-999999.9999' : '0.0001'" wire:model="days" required />
                <flux:textarea label="Note" placeholder="Reason or reference for this entry" wire:model="note" maxlength="500" rows="3" required />
                <div class="flex flex-wrap justify-end gap-2">
                    <flux:button variant="outline" type="button" wire:click="$set('entryOpen', false)">Cancel</flux:button>
                    <flux:button variant="primary" type="submit" wire:loading.attr="disabled" wire:target="saveEntry">Record credits</flux:button>
                </div>
            </form>
        </flux:modal>
    @endif
</div>
