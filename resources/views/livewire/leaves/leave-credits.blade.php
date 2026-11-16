{{--
    Leave Credits — the second tab of Leave Management, with no counterpart
    in the source. One row per employee the actor may see; one column per
    creditable Leave Settings type reading the balance AS OF the chosen day —
    a credit figure at three decimals, as a leave card states it, never
    "N days" — with an em dash where no credit was on the books; and, behind
    all_data + LEAVES, a row menu whose "Set credits…" opens the dialog at the
    bottom for that day's year. Used credits are derived from approved leave
    with pay and never edited here.

    The table nesting is the house one (card > wrap + scroll > hris-table);
    an Employee + six types + actions row is the roster's width, and the
    wrap scrolls sideways under the sticky head on a phone, as it does there.
    See App\Livewire\Leaves\LeaveCredits for the state and actions.
--}}
@php
    use App\Modules\Timekeeping\Domain\Credits\LeaveQuantity;
    use App\Platform\Files\PortraitUrl;
    use App\Support\EmployeeIdentity;
    use App\Support\EmployeeName;

    $fmt = static fn (float $days): string => LeaveQuantity::credits($days);
    $columns = count($types) + ($canEdit ? 2 : 1);
@endphp
<div class="space-y-4">
    <section class="hris-card overflow-hidden">
        <header class="flex flex-wrap items-center gap-3 border-b border-border px-5 py-4">
            <flux:input
                aria-label="Search employees"
                placeholder="Search ID, name or position"
                class="w-full min-w-[12rem] max-w-xs flex-1"
                wire:model.live.debounce.250ms="search"
            />

            {{-- The day the balances are read as of: the books as they stood
                 at the end of it (LeaveCreditRecords::balancesAsOf). Today
                 by default; its year is the one Set credits enters. --}}
            <div class="w-[11rem]">
                <x-kit.date-field wire:model.live="asOf" aria-label="Leave credits as of" />
            </div>

            <p class="text-xs text-muted-foreground">
                Leave credits as of this date.
                {{-- Monthly credits and acquired leave are posted by the schedule alone, so the tab says when it last ran. --}}
                <span data-last-run>Monthly credits are added automatically{{ $lastRun !== null ? '; last run '.$lastRun : '' }}.</span>
            </p>

            <div class="ml-auto flex flex-wrap items-center gap-2">
                {{-- Export only: Leave Credits has no Import CSV. --}}
                <x-kit.export-button :href="route('leaves.credits.export', array_filter(['q' => $search, 'asof' => $asOf, 'dir' => $sortDir]))" />
            </div>
        </header>

        <div class="hris-table-wrap hris-table-scroll">
            <table class="hris-table">
                <thead>
                    <tr>
                        <x-kit.sort-header field="employee" label="Employee" />
                        @foreach ($types as $key => $name)
                            <th class="whitespace-nowrap"><span class="block text-right">{{ $name }}</span></th>
                        @endforeach
                        @if ($canEdit)
                            <th class="w-10"><span class="sr-only">Actions</span></th>
                        @endif
                    </tr>
                </thead>
                <tbody>
                    @forelse ($rows as $employee)
                        @php
                            /** @var \App\Modules\Workforce\Contracts\DirectoryEmployee $employee */
                            $byKey = [];
                            foreach ($balances[$employee->id] ?? [] as $balance) {
                                $byKey[$balance->request_type] = $balance;
                            }
                            $photo = $employee->photo_path ? PortraitUrl::for($employee->photo_path) : null;
                        @endphp
                        <tr wire:key="credits-{{ $employee->id }}" @class(['opacity-60' => ! $employee->is_active])>
                            <td>
                                <div class="flex items-center gap-3">
                                    <span class="flex size-9 shrink-0 items-center justify-center overflow-hidden rounded-full bg-muted">
                                        @if ($photo)
                                            <img src="{{ $photo }}" alt="{{ EmployeeName::display($employee) }}" class="h-full w-full object-cover" />
                                        @else
                                            <span class="num text-xs font-semibold">{{ EmployeeName::initials($employee) }}</span>
                                        @endif
                                    </span>
                                    <div class="min-w-0">
                                        <p class="truncate font-medium">{{ EmployeeName::display($employee) }}</p>
                                        <p class="num truncate text-xs text-muted-foreground">
                                            {{ EmployeeIdentity::meta(EmployeeIdentity::besideName($employee), $employee->position) ?: '—' }}
                                        </p>
                                        {{-- A leave type with a waiting period: the day this employee acquired it, or the earliest day they can. --}}
                                        @foreach ($standing[$employee->id] ?? [] as $line)
                                            <p class="num truncate text-xs text-muted-foreground" data-leave-standing>{{ $line }}</p>
                                        @endforeach
                                    </div>
                                </div>
                            </td>
                            @foreach ($types as $key => $name)
                                @php $balance = $byKey[$key] ?? null; @endphp
                                <td class="num whitespace-nowrap text-right">
                                    @if ($balance === null || ! $balance->entered())
                                        <span class="text-muted-foreground">—</span>
                                    @else
                                        <span @class(['pill pill-caution' => $balance->remaining <= 0])>{{ LeaveQuantity::credits($balance->remaining) }}</span>
                                    @endif
                                </td>
                            @endforeach
                            @if ($canEdit)
                                {{-- Hidden without all_data + LEAVES; openCredits() re-checks the
                                     grant and re-resolves the id, and the command gates again. --}}
                                <td class="text-right">
                                    <x-kit.row-actions :label="EmployeeName::display($employee)">
                                        <flux:menu.item icon="pencil-square" wire:click="openCredits('{{ $employee->id }}')">Set Credits…</flux:menu.item>
                                    </x-kit.row-actions>
                                </td>
                            @endif
                        </tr>
                    @empty
                        <tr wire:key="empty">
                            <x-kit.empty-state :colspan="$columns" title="No employee matches those filters." />
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <x-kit.pagination :paginator="$rows" noun="employees" />
    </section>

    {{-- The Set credits dialog. openCredits() stages creditsEmployeeId and
         seeds the fields; saveCredits() writes through the command. The
         wire:key on the rows re-mounts the fields whenever the employee
         changes, so a reopened dialog shows that employee's year, not the
         last one edited. Accessible name: the heading carries an id and
         x-init points the dialog's aria-labelledby at it. --}}
    <flux:modal
        name="leave-credits"
        class="w-full max-w-lg"
        x-init="$el.querySelector('dialog')?.setAttribute('aria-labelledby', 'leave-credits-heading')"
    >
        <div class="space-y-4">
            <div>
                <flux:heading size="lg" id="leave-credits-heading">
                    Leave Credits{{ $dialogEmployee !== null ? ' · '.EmployeeName::display($dialogEmployee) : '' }} · {{ $year }}
                </flux:heading>
            </div>

            <div class="space-y-3" wire:key="leave-credits-{{ $creditsEmployeeId ?: 'none' }}">
                @foreach ($dialogRows as $balance)
                    <div class="grid grid-cols-[minmax(0,1fr)_8rem] items-center gap-3">
                        <div class="min-w-0">
                            <flux:label for="credit-{{ $balance->request_type }}">{{ $balance->label }}</flux:label>
                            <p class="num text-xs text-muted-foreground">used {{ $fmt($balance->used) }}</p>
                            {{-- A type this employee may not use may still be given credits; they cannot be filed against or converted to cash. --}}
                            @if (isset($dialogIneligible[$balance->request_type]))
                                <p class="mt-1 text-xs text-muted-foreground" data-credit-not-eligible><span class="pill pill-caution">Not eligible</span> {{ $dialogIneligible[$balance->request_type] }} Credits set here cannot be filed against or converted to cash.</p>
                            @endif
                        </div>
                        <flux:input
                            id="credit-{{ $balance->request_type }}"
                            type="number"
                            min="0"
                            max="999999.9999"
                            step="0.0001"
                            class:input="num text-right"
                            wire:model="creditDraft.{{ $balance->request_type }}"
                            aria-label="{{ $balance->label }} credits"
                        />
                    </div>
                @endforeach
            </div>

            <div class="flex items-center justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="outline">Cancel</flux:button>
                </flux:modal.close>
                <flux:button
                    variant="primary"
                    wire:click="saveCredits"
                    wire:loading.attr="disabled"
                    wire:target="saveCredits"
                >
                    Save Credits
                </flux:button>
            </div>
        </div>
    </flux:modal>
</div>
