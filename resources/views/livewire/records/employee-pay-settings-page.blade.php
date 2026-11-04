{{--
    Employee Pay Settings — who is paid differently from the standard
    computation: search, the "only customized" and "active only" filters,
    one row per employee with each setting in a phrase (the ones that
    differ in bold, with a Custom pill), each row opening the employee's
    page. Pay data: the rows show only for an all-data PAYROLL holder.
--}}
@php
    use App\Livewire\Records\Support\EmployeeRates;
    use App\Livewire\Records\Support\Hris;
    use App\Livewire\Records\Support\PaySettingsText;
    use App\Modules\Payroll\Domain\Deductions\GovDeductionSettings;
    use App\Support\EmployeeName;

    // A phrase in bold when any of the columns behind it differs.
    $differs = static fn (array $customized, array $keys): bool => array_intersect($customized, $keys) !== [];
@endphp
<div class="space-y-6">
    <div>
        <h1 class="text-2xl font-bold tracking-tight">Employee Pay Settings</h1>
        <p class="text-sm text-muted-foreground">
            The employees who are paid differently from the standard computation: contributions turned off or fixed,
            another way of withholding tax, an hourly rate or Pay Basis &amp; Rates of their own, recurring deductions and allowances. Open an employee to change theirs.
        </p>
    </div>

    @include('livewire.payroll.partials.settings-switch', ['activeView' => 'employees'])

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
                    <flux:checkbox wire:model.live="onlyCustomized" label="Only customized" />
                    <flux:checkbox wire:model.live="activeOnly" label="Active only" />
                </div>
            </header>

            <div class="hris-table-wrap hris-table-scroll">
                <table class="hris-table">
                    <thead>
                        <tr>
                            <x-kit.sort-header field="name" label="Employee" />
                            <x-kit.sort-header field="status" label="Employment status" />
                            <th>SSS</th>
                            <th>PhilHealth</th>
                            <th>Pag-IBIG</th>
                            <th>Withholding tax</th>
                            <th>Hourly rate</th>
                            <th>Pay Basis &amp; Rates</th>
                            <th>Recurring lines</th>
                        </tr>
                    </thead>
                    <tbody>
                        @if ($rows->total() === 0)
                            <tr wire:key="empty">
                                <x-kit.empty-state
                                    :colspan="9"
                                    :title="$onlyCustomized ? 'Every employee here has the standard settings.' : 'No employee matches that search.'"
                                    :hint="$onlyCustomized ? 'Untick Only customized to list everyone.' : null"
                                />
                            </tr>
                        @endif
                        @foreach ($rows as $row)
                            @php
                                $settings = $row['settings'];
                                $customized = $row['customized'];
                                $url = route('records.employee-pay-settings.show', ['employee' => $settings->id]);
                            @endphp
                            <tr wire:key="row-{{ $settings->id }}">
                                <td>
                                    <a href="{{ $url }}" wire:navigate class="font-medium text-primary hover:underline">{{ EmployeeName::display($settings) }}</a>
                                    <p class="num text-xs text-muted-foreground">
                                        {{ $settings->employee_code }}@if ($settings->employee_id_no) · {{ $settings->employee_id_no }}@endif
                                        @unless ($settings->is_active) · Inactive @endunless
                                    </p>
                                    @if ($customized !== [] || EmployeeRates::customized($row['rates']) || $row['recurring'] !== null)
                                        <span class="pill pill-primary mt-1">Custom</span>
                                    @endif
                                    @if ($row['pendingGov'] || $row['rates']['drafts'] > 0)
                                        <span class="pill pill-caution mt-1">Awaiting approval</span>
                                    @endif
                                </td>
                                <td class="text-muted-foreground">{{ $settings->employment_status ?: '—' }}</td>
                                @foreach (array_keys(GovDeductionSettings::CONTRIBUTIONS) as $key)
                                    <td class="whitespace-nowrap {{ $differs($customized, PaySettingsText::contributionKeys($key)) ? 'font-semibold' : 'text-muted-foreground' }}">
                                        {{ PaySettingsText::contribution($row['gov'], $key) }}
                                    </td>
                                @endforeach
                                <td class="whitespace-nowrap {{ $differs($customized, PaySettingsText::withholdingKeys()) ? 'font-semibold' : 'text-muted-foreground' }}">
                                    {{ PaySettingsText::withholding($row['gov']) }}
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
                                        {{ $row['recurring']['count'] }} {{ $row['recurring']['count'] === 1 ? 'line' : 'lines' }}{{ $row['recurring']['left'] !== null ? ', '.Hris::peso($row['recurring']['left']).' left' : '' }}
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
