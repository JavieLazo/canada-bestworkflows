{{--
    Employee Pay Settings — who is paid differently from the standard
    computation. Five summary cards (how many are listed, how many are
    customized and, by the table's own columns, how many have Contributions
    & tax, rates, and a loan or an allowance of their own), then the list:
    search, an All / Only customized switch and "Active only", one row per
    employee, who reads as on the Employee Records list (portrait with the
    name over the position, the status as pills), then Contributions & tax
    naming only what differs, what differs in bold. THE WHOLE ROW opens the
    employee's page; the name is not a link of its own. Pay data: the cards
    and rows show only for an all-data PAYROLL holder.
--}}
@php
    use App\Livewire\Employees\EmployeeFields;
    use App\Livewire\Records\Support\EmployeeRates;
    use App\Livewire\Records\Support\Hris;
    use App\Livewire\Records\Support\PaySettingsText;
    use App\Platform\Files\PortraitUrl;
    use App\Support\EmployeeName;

    // A phrase in bold when any of the columns behind it differs.
    $differs = static fn (array $customized, array $keys): bool => array_intersect($customized, $keys) !== [];
    // The system's tab-strip button, for the All / Only customized switch.
    $segment = static fn (bool $on): string => 'inline-flex items-center justify-center whitespace-nowrap rounded-md px-3 py-1 text-sm font-medium transition-all '.($on ? 'bg-card text-foreground shadow-sm' : 'hover:text-foreground');
@endphp
<div class="space-y-6">
    @include('livewire.payroll.partials.settings-header', [
        'activeView' => 'employees',
        'title' => 'Employee Pay Settings',
        'description' => 'Each employee\'s own pay settings. Open an employee to change theirs.',
    ])

    @if ($canRead)
        {{-- What the list adds up to, counted over everyone the search and Active only leave in view. --}}
        <dl class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5" data-pay-settings-summary>
            <x-payroll.settings.stat
                data-stat="listed"
                label="Employees"
                :value="number_format($summary['listed'])"
                :note="$activeOnly ? 'Active employees listed' : 'Active and inactive'"
            />
            <x-payroll.settings.stat
                data-stat="customized"
                label="Customized"
                :value="number_format($summary['customized'])"
                note="Paid apart from the standard"
            />
            <x-payroll.settings.stat
                data-stat="gov"
                label="Contributions & Tax"
                :value="number_format($summary['gov'])"
                note="With a setting of their own"
            />
            <x-payroll.settings.stat
                data-stat="rates"
                label="Rates"
                :value="number_format($summary['rates'])"
                note="With an hourly rate or rates of their own"
            />
            <x-payroll.settings.stat
                data-stat="recurring"
                label="Loans & Allowances"
                :value="number_format($summary['recurring'])"
                note="With a line that repeats"
            />
        </dl>
    @endif

    <section class="hris-card overflow-hidden">
        @unless ($canRead)
            <p class="px-5 py-6 text-sm text-muted-foreground">
                Employee pay settings are pay data. They are shown to payroll officers with all-data access to Payroll.
            </p>
        @else
            <header class="flex flex-wrap items-center gap-3 border-b border-border px-5 py-4">
                <flux:input
                    aria-label="Search employees"
                    placeholder="Search name, ID or Employee ID"
                    class="w-full min-w-[12rem] max-w-xs flex-1"
                    wire:model.live.debounce.250ms="search"
                />
                <div class="ml-auto flex flex-wrap items-center gap-4">
                    <div class="inline-flex h-9 items-center justify-center rounded-lg bg-muted p-1 text-muted-foreground" role="group" aria-label="Which employees to list">
                        <button type="button" wire:click="$set('onlyCustomized', false)" aria-pressed="{{ $onlyCustomized ? 'false' : 'true' }}" class="{{ $segment(! $onlyCustomized) }}">All</button>
                        <button type="button" wire:click="$set('onlyCustomized', true)" aria-pressed="{{ $onlyCustomized ? 'true' : 'false' }}" class="{{ $segment($onlyCustomized) }}">Only Customized</button>
                    </div>
                    <flux:checkbox wire:model.live="activeOnly" label="Active Only" />
                </div>
            </header>

            <div class="hris-table-wrap hris-table-scroll">
                <table class="hris-table">
                    <thead>
                        <tr>
                            <x-kit.sort-header field="name" label="Employee" />
                            <x-kit.sort-header field="status" label="Employment Status" />
                            <th>Contributions &amp; Tax</th>
                            <th>Hourly Rate</th>
                            <th>Pay Basis &amp; Rates</th>
                            <th>Loans &amp; Allowances</th>
                        </tr>
                    </thead>
                    <tbody>
                        @if ($rows->total() === 0)
                            <tr wire:key="empty">
                                <x-kit.empty-state
                                    :colspan="6"
                                    :title="$onlyCustomized ? 'Every employee here has the standard settings.' : 'No employee matches that search.'"
                                    :hint="$onlyCustomized ? 'Choose All to list everyone.' : null"
                                />
                            </tr>
                        @endif
                        @foreach ($rows as $row)
                            @php
                                $settings = $row['settings'];
                                $customized = $row['customized'];
                                $url = route('records.employee-pay-settings.show', ['employee' => $settings->id]);
                                $name = EmployeeName::display($settings);
                                $photo = PortraitUrl::for($settings->photo_path);
                                $awaiting = $row['pendingGov'] || $row['rates']['drafts'] > 0;
                            @endphp
                            {{-- An employee reads here as on the Employee Records list: the portrait (or initials)
                                 beside the name over the position, and the status as pills. NO ID OR EMPLOYEE ID is
                                 shown, and NO "Custom" pill: what is an employee's own reads in bold in its column.
                                 Awaiting approval alone sits under the position, on the rare row whose approval
                                 was refused.
                                 THE WHOLE ROW opens the employee's page, as that list's rows open the record: a click
                                 anywhere on it, Enter or Space once it has focus. Ctrl or Cmd with the click opens
                                 the page in a new tab, as it would on a link. --}}
                            <tr
                                wire:key="row-{{ $settings->id }}"
                                tabindex="0"
                                aria-label="Open {{ $name }}'s pay settings"
                                data-href="{{ $url }}"
                                class="cursor-pointer transition-colors hover:bg-muted/60 focus-visible:bg-muted/60 focus-visible:outline-none"
                                x-on:click="$event.ctrlKey || $event.metaKey ? window.open($el.dataset.href, '_blank') : Livewire.navigate($el.dataset.href)"
                                x-on:keydown.enter.self.prevent="Livewire.navigate($el.dataset.href)"
                                x-on:keydown.space.self.prevent="Livewire.navigate($el.dataset.href)"
                            >
                                <td>
                                    <div class="flex items-center gap-3">
                                        <span class="flex h-9 w-9 shrink-0 items-center justify-center overflow-hidden rounded-full bg-muted">
                                            @if ($photo !== null)
                                                <img src="{{ $photo }}" alt="{{ $name }}" class="h-full w-full object-cover" />
                                            @else
                                                <span class="num text-xs font-semibold">{{ EmployeeName::initials($settings) }}</span>
                                            @endif
                                        </span>
                                        <div class="min-w-0">
                                            <p class="truncate font-medium">{{ $name }}</p>
                                            <p class="truncate text-xs text-muted-foreground">{{ $settings->position ?? '—' }}</p>
                                            @if ($awaiting)
                                                <p class="mt-1"><span class="pill pill-caution">Awaiting approval</span></p>
                                            @endif
                                        </div>
                                    </div>
                                </td>
                                <td class="align-middle">
                                    <div class="flex flex-wrap items-center gap-2">
                                        @if (trim((string) $settings->employment_status) === '')
                                            <span class="text-muted-foreground">—</span>
                                        @else
                                            <span class="{{ EmployeeFields::isRegular($settings->employment_status) ? 'pill pill-success' : 'pill pill-info' }}">
                                                {{ EmployeeFields::employmentStatusLabel((string) $settings->employment_status) }}
                                            </span>
                                        @endif
                                        @unless ($settings->is_active)
                                            <span class="pill pill-muted">Inactive</span>
                                        @endunless
                                    </div>
                                </td>
                                <td class="{{ $differs($customized, PaySettingsText::summaryKeys()) ? 'font-semibold' : 'text-muted-foreground' }}">
                                    {{ PaySettingsText::summary($row['gov'], $customized) }}
                                </td>
                                <td class="whitespace-nowrap {{ $differs($customized, ['hourly_rate']) ? 'font-semibold' : 'text-muted-foreground' }}">
                                    {{ PaySettingsText::hourly($settings) }}
                                </td>
                                <td class="whitespace-nowrap {{ EmployeeRates::customized($row['rates']) ? 'font-semibold' : 'text-muted-foreground' }}">
                                    {{ EmployeeRates::phrase($row['rates'], $settings->employment_status) }}
                                </td>
                                <td class="whitespace-nowrap {{ $row['recurring'] !== null ? 'font-semibold' : 'text-muted-foreground' }}">
                                    @if ($row['recurring'] === null)
                                        None
                                    @else
                                        {{ $row['recurring']['count'] }} {{ $row['recurring']['count'] === 1 ? 'line' : 'lines' }}{{ $row['recurring']['left'] !== null ? ', '.Hris::money($row['recurring']['left']).' left' : '' }}
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <x-kit.pagination :paginator="$rows" noun="employees" />
        @endunless
    </section>
</div>
