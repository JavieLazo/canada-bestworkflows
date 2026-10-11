{{-- Reports share the module toolbar and table patterns; owners supply every filter result and calculation. --}}
@php
    use App\Livewire\Reports\Support\ReportCatalog;
@endphp
<div class="space-y-6">
    <div class="print:hidden">
        <h1 class="text-2xl font-bold tracking-tight">Reports</h1>
        <p class="text-sm text-muted-foreground">Explore your workforce, compare attendance and prepare reports with the details you need.</p>
    </div>

    <section class="hris-card print:hidden" aria-label="Report setup">
        <header class="flex flex-wrap items-center gap-4 border-b border-border px-5 py-4">
            <div class="w-full sm:w-72">
                <x-kit.select aria-label="Report type" x-on:change="$wire.selectReport($event.target.value)">
                    @foreach ($groups as $category => $reports)
                        <optgroup label="{{ $category }}">
                            @foreach ($reports as $key => $report)
                                <flux:select.option value="{{ $key }}" :selected="$type === $key" :disabled="! ReportCatalog::allows($actor, $key)">{{ $report['title'] }}{{ ReportCatalog::allows($actor, $key) ? '' : ' · Access required' }}</flux:select.option>
                            @endforeach
                        </optgroup>
                    @endforeach
                </x-kit.select>
            </div>
            <div class="min-w-0 flex-1">
                <p class="text-sm text-muted-foreground">{{ $catalog[$type]['description'] }}</p>
                <p class="mt-1 text-xs text-muted-foreground">{{ $catalog[$type]['source'] }}</p>
            </div>
        </header>

        @if ($allowed)
            <form wire:submit="generate" aria-label="Report filters" wire:key="report-filters-{{ $type }}">
                @if (in_array($type, ['employee-list', 'attendance-reports'], true))
                    <div class="flex flex-wrap items-center gap-2 border-b border-border px-5 py-3" aria-label="Report starting points">
                        <span class="mr-1 text-xs font-medium text-muted-foreground">Start with</span>
                        @foreach ($type === 'employee-list' ? ['workforce' => 'Workforce analysis', 'demographics' => 'Demographics', 'development' => 'Education & development', 'service' => 'Service & movements', 'quality' => 'Record quality', 'directory' => 'Directory', 'profile' => 'Profiles', 'missing' => 'Missing information'] : ['attendance-analysis' => 'Management analysis', 'late' => 'Most late minutes', 'frequent' => 'Frequent lateness', 'undertime' => 'Most undertime', 'absences' => 'Absences'] as $key => $label)
                            <flux:button type="button" size="sm" variant="ghost" wire:click="applyPreset('{{ $key }}')">{{ $label }}</flux:button>
                        @endforeach
                    </div>
                @endif

                <div class="space-y-4 p-5">
                    <div class="grid items-end gap-4 sm:grid-cols-2 xl:grid-cols-4">
                        @if ($type !== 'employee-list' || $view === 'analysis')
                            <div class="min-w-0 space-y-1.5"><label for="report-from" class="text-sm font-medium">From</label><x-kit.date-field id="report-from" wire:model.live="from" aria-label="Report from date" /></div>
                            <div class="min-w-0 space-y-1.5"><label for="report-to" class="text-sm font-medium">Through</label><x-kit.date-field id="report-to" wire:model.live="to" aria-label="Report through date" /></div>
                        @endif
                        @if ($type !== 'audit-logs')
                            <div class="min-w-0 {{ $type === 'employee-list' ? 'sm:col-span-2' : '' }}">
                                <p class="mb-1.5 text-sm font-medium">Employees</p>
                                <flux:button type="button" class="w-full" icon="users" wire:click="chooseEmployees">
                                    {{ $employeeIds === [] ? ($type === 'attendance-reports' ? 'Choose employees' : 'All permitted employees') : count($employeeIds).' selected' }}
                                </flux:button>
                            </div>
                        @endif
                        @if ($type === 'attendance-reports')
                            <x-kit.select label="View" wire:model.live="view" aria-label="Attendance view">
                                <flux:select.option value="analysis">Management analysis</flux:select.option>
                                <flux:select.option value="summary">Employee comparison</flux:select.option>
                                <flux:select.option value="details">Individual daily sheets</flux:select.option>
                            </x-kit.select>
                        @elseif ($type === 'employee-list')
                            <x-kit.select label="View" wire:model.live="view" aria-label="Employee report view">
                                <flux:select.option value="summary">Flexible employee list</flux:select.option>
                                <flux:select.option value="analysis">Management analysis</flux:select.option>
                            </x-kit.select>
                            <div>
                                <flux:input label="Search employees" wire:model.live.debounce.350ms="filters.search" placeholder="ID, Employee ID, name, position or email" />
                            </div>
                        @endif
                    </div>
                    @if ($type !== 'employee-list' || $view === 'analysis')
                        <div class="flex flex-wrap items-center gap-2 text-xs text-muted-foreground" aria-label="Quick periods">
                            @foreach ($periods as $key => $period)
                                <button type="button" wire:click="setPeriod('{{ $key }}')" aria-pressed="{{ $from === $period['from'] && $to === $period['to'] ? 'true' : 'false' }}"
                                    @class(['rounded-md border px-2.5 py-1.5 font-medium transition-colors focus-visible:outline-2 focus-visible:outline-primary', 'border-primary/30 bg-primary-soft text-primary' => $from === $period['from'] && $to === $period['to'], 'border-border hover:bg-accent hover:text-foreground' => $from !== $period['from'] || $to !== $period['to']])>{{ $period['label'] }}</button>
                            @endforeach
                            <span class="sm:ml-auto">{{ $type === 'employee-list' ? 'Activity window for training and recorded movements · workforce snapshot is current' : $catalog[$type]['period'] }}</span>
                        </div>
                    @endif
                    @if ($employeeIds !== [])
                        <button type="button" wire:click="clearEmployees" class="text-xs font-medium text-primary hover:underline">{{ $type === 'attendance-reports' ? 'Clear employee selection' : 'Include all permitted employees' }}</button>
                    @endif

                    @if ($type === 'employee-list')
                        @include('livewire.reports.partials.employee-filters')
                    @elseif ($type === 'attendance-reports' && $view === 'summary')
                        @include('livewire.reports.partials.attendance-filters')
                    @endif
                    @if (in_array($type, ['employee-list', 'attendance-reports'], true) && $view === 'analysis')
                        @include('livewire.reports.partials.analysis-filters')
                    @endif

                    @if ($type === 'loan-summary')
                        <fieldset class="space-y-3 border-t border-border pt-4">
                            <legend class="text-sm font-semibold">Loan deduction types</legend>
                            <p class="text-xs text-muted-foreground">Choose the deductions to include. This report shows repayments, not outstanding balances.</p>
                            <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                                @forelse ($loanTypes as $code => $label)
                                    <flux:checkbox wire:model.live="deductionCodes" value="{{ $code }}" label="{{ $label }} ({{ $code }})" />
                                @empty
                                    <p class="text-sm text-muted-foreground">No posted deduction types are available.</p>
                                @endforelse
                            </div>
                        </fieldset>
                    @endif
                </div>
                <footer class="flex flex-wrap items-center justify-between gap-3 border-t border-border px-5 py-4">
                    <p class="max-w-xl text-xs text-muted-foreground">
                        @if ($type === 'employee-list')
                            Current employee records · filters combine to narrow your results
                        @elseif ($type === 'attendance-reports')
                            Select employees · up to 31 days and 5,000 employee-days · future days excluded
                        @else
                            {{ $catalog[$type]['period'] }} · up to 5,000 source records
                        @endif
                    </p>
                    <div class="flex flex-wrap items-center gap-2">
                        <flux:button type="button" variant="ghost" wire:click="resetFilters">Reset filters</flux:button>
                        <flux:button type="submit" variant="primary" icon="document-chart-bar" wire:loading.attr="disabled">Generate report</flux:button>
                    </div>
                </footer>
                @if ($errors->any())
                    <div class="space-y-1 border-t border-border px-5 py-3 text-sm text-danger" role="alert">
                        @foreach ($errors->all() as $message)<p>{{ $message }}</p>@endforeach
                    </div>
                @endif
                <p wire:loading wire:target="generate,sortBy" class="px-5 pb-4 text-sm text-muted-foreground" role="status">Preparing your report…</p>
            </form>
        @else
            <div class="p-6">
                <h2 class="font-semibold">Source access is required</h2>
                <p class="mt-2 text-sm text-muted-foreground">Your role can open Reports. A source-module grant is also needed to generate a report; ask your administrator to assign the appropriate access.</p>
            </div>
        @endif
    </section>

    <p class="sr-only" role="status">{{ $document !== null ? count($document->rows).' report rows ready.' : 'Set your filters, then generate a report.' }}</p>
    @if ($document !== null)
        <section class="min-w-0 space-y-4" aria-label="Generated report">
            <header class="flex flex-wrap items-center justify-between gap-3 print:hidden">
                <div>
                    <h2 class="text-lg font-semibold">{{ $document->title }}</h2>
                    <p class="mt-1 text-xs text-muted-foreground">{{ number_format(count($document->rows)) }} rows · Generated {{ $generatedAt }} (Manila)</p>
                </div>
                <div class="flex flex-wrap gap-2">
                    @if ($type !== 'attendance-reports')
                        <x-kit.export-button :href="route('reports.export', $generated)" :label="$view === 'analysis' ? 'Export group summary CSV' : ($type === 'payslips' ? 'Export payslip summary CSV' : 'Export CSV')" />
                    @endif
                    <flux:button type="button" icon="printer" x-on:click="window.print()">Print / Save as PDF</flux:button>
                </div>
            </header>
            @if ($view === 'analysis')
                @include('livewire.reports.partials.results')
                @include('livewire.reports.partials.analysis-sections')
                <div class="hidden print:block">@include('livewire.reports.partials.document')</div>
            @elseif ($document->sections === [])
                @include('livewire.reports.partials.results')
                <div class="hidden print:block">@include('livewire.reports.partials.document')</div>
            @else
                @include('livewire.reports.partials.document')
            @endif
        </section>
    @elseif ($allowed)
        <section class="hris-card px-6 py-10 text-center print:hidden" aria-label="Report preview">
            <flux:icon.document-chart-bar class="mx-auto size-8 text-muted-foreground" />
            <h2 class="mt-3 text-base font-semibold">Build the report you need</h2>
            <p class="mx-auto mt-2 max-w-lg text-sm text-muted-foreground">
                @if ($type === 'employee-list')
                    Combine filters, choose your columns and generate a list. Use the starting points for workforce details, profiles or missing information.
                @elseif ($type === 'attendance-reports')
                    Choose employees and a period to compare lateness, undertime and absences, or review their individual daily sheets.
                @else
                    Choose a period and coverage, then generate your report to review, print or export.
                @endif
            </p>
        </section>
    @endif

    @if ($allowed && $type !== 'audit-logs')
        <flux:modal name="report-employees" class="w-full max-w-2xl" x-init="$el.querySelector('dialog')?.setAttribute('aria-labelledby', 'report-employees-heading')">
            <div class="space-y-5">
                <header>
                    <h2 id="report-employees-heading" class="text-lg font-semibold">Choose employees</h2>
                    <p class="mt-1 text-sm text-muted-foreground">Filter the list, then select employees individually or select all matching your filters.</p>
                </header>
                <livewire:kit.employee-checklist wire:model.live="employeeIds" name="report-employees"
                    :active-only="false" :scope-modules="ReportCatalog::employeeScope($type)"
                    :with-filters="true" :selection-limit="\App\Platform\Reports\ReportCriteria::MAX_EMPLOYEES"
                    placeholder="Search ID, Employee ID, name or position" aria-label="Report employees"
                    wire:key="report-employees-{{ $type }}" />
                <footer class="flex flex-wrap items-center justify-between gap-3 border-t border-border pt-4">
                    <p class="max-w-sm text-xs text-muted-foreground">{{ $type === 'attendance-reports' ? 'Choose at least one employee. For larger groups, use a shorter period to stay within 5,000 employee-days.' : 'An empty selection includes all permitted employees. The report filters still apply.' }}</p>
                    <flux:modal.close><flux:button variant="primary">Done</flux:button></flux:modal.close>
                </footer>
            </div>
        </flux:modal>
    @endif
</div>
