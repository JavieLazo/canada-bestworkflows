{{-- One report on its own page. It reads top to bottom: its name and one plain line, a row of
     filters that each state their own value, the answer in one frame, then the table. A changed
     filter drops the document and the page asks for the new one itself, so the reader never
     presses Generate; owners still supply every filter result and calculation. --}}
@php
    use App\Livewire\Reports\Support\ReportCatalog;

    $analysis = $view === 'analysis';
    $title = $type === 'employee-list' && $analysis ? 'Workforce Analysis' : $catalog[$type]['title'];
    $lead = $type === 'employee-list' && $analysis ? 'How your workforce is made up, from the same employee records.' : $catalog[$type]['lead'];
    $dated = $type !== 'employee-list' || $analysis;
    $period = 'custom';
    foreach ($periods as $key => $option) {
        if ($from === $option['from'] && $to === $option['to']) {
            $period = $key;
            break;
        }
    }
    $chosen = count($employeeIds);
@endphp
<div class="space-y-6" @if ($runnable && $generated === [] && ! $errors->any()) wire:init="generate" @endif>
    <div class="flex flex-wrap items-end justify-between gap-4 print:hidden">
        <div class="min-w-0">
            <a href="{{ route('reports.index') }}" wire:navigate class="mb-1.5 inline-flex items-center gap-1 text-sm font-medium text-muted-foreground hover:text-foreground focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary">
                <flux:icon.chevron-left variant="micro" /> Reports
            </a>
            <h1 class="text-2xl font-bold tracking-tight">{{ $title }}</h1>
            <p class="text-sm text-muted-foreground">{{ $lead }}</p>
        </div>
        @if ($switch !== [])
            <nav aria-label="{{ $catalog[$type]['category'] }} reports" class="inline-flex max-w-full flex-wrap items-center gap-1 rounded-lg bg-muted p-1 text-sm text-muted-foreground">
                @foreach ($switch as $link)
                    <a href="{{ route('reports.show', array_filter(['report' => $link['type'], 'view' => in_array($link['view'], ['', 'summary'], true) ? '' : $link['view'], ...$carried])) }}" wire:navigate
                        @if ($link['current']) aria-current="page" @endif
                        class="inline-flex items-center justify-center rounded-md px-3 py-1.5 font-medium transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary {{ $link['current'] ? 'bg-card text-foreground shadow-sm' : 'hover:text-foreground' }}">{{ $link['label'] }}</a>
                @endforeach
            </nav>
        @endif
    </div>

    @if (! $allowed)
        <section class="hris-card p-6 print:hidden">
            <h2 class="font-semibold">Source access is required</h2>
            <p class="mt-2 text-sm text-muted-foreground">Your role can open Reports. A source-module grant is also needed to open this report; ask your administrator to assign the appropriate access.</p>
        </section>
    @else
        <form wire:submit="generate" class="space-y-3 print:hidden" aria-label="Report filters" wire:key="report-filters-{{ $type }}-{{ $view }}">
            <div class="flex flex-wrap items-center gap-2">
                @if ($dated)
                    <div class="w-full sm:w-44">
                        <x-kit.select aria-label="Period" x-on:change="$event.target.value === 'custom' || $wire.setPeriod($event.target.value)">
                            @foreach ($periods as $key => $option)
                                <flux:select.option value="{{ $key }}" :selected="$period === $key">{{ $option['label'] }}</flux:select.option>
                            @endforeach
                            <flux:select.option value="custom" :selected="$period === 'custom'">Custom dates</flux:select.option>
                        </x-kit.select>
                    </div>
                    <div class="w-[calc(50%-0.25rem)] sm:w-40"><x-kit.date-field wire:model.live="from" aria-label="Report from date" /></div>
                    <div class="w-[calc(50%-0.25rem)] sm:w-40"><x-kit.date-field wire:model.live="to" aria-label="Report through date" /></div>
                @endif
                @if ($type !== 'audit-logs')
                    <flux:button type="button" icon="users" wire:click="chooseEmployees">
                        {{ $chosen === 0 ? ($type === 'attendance-reports' ? 'Choose employees' : 'All employees') : $chosen.' '.($chosen === 1 ? 'employee' : 'employees') }}
                    </flux:button>
                @endif
                @include('livewire.reports.partials.filters-primary')
                <div class="ml-auto flex flex-wrap items-center gap-2">
                    @if ($document !== null && $type !== 'attendance-reports')
                        <x-kit.export-button :href="route('reports.export', $generated)" :label="$analysis ? 'Export group summary CSV' : ($type === 'payslips' ? 'Export payslip summary CSV' : 'Export CSV')" />
                    @endif
                    @if ($document !== null)
                        @if ($printOptions['parts'] === [] && $printOptions['tables'] === [])
                            <flux:button type="button" icon="printer" x-on:click="window.print()">Print</flux:button>
                        @else
                            <flux:button type="button" icon="printer" x-on:click="$flux.modal('report-print').show()">Print</flux:button>
                        @endif
                    @endif
                </div>
            </div>
            @include('livewire.reports.partials.filters-more')
            @if ($errors->any())
                <div class="space-y-1 text-sm text-danger" role="alert">
                    @foreach ($errors->all() as $message)<p>{{ $message }}</p>@endforeach
                </div>
            @endif
        </form>
    @endif

    <p class="sr-only" role="status">{{ $document !== null ? count($document->rows).' report rows ready.' : 'Choose your filters and the report appears here.' }}</p>
    @if ($document !== null)
        <section class="min-w-0 space-y-6" aria-label="Generated report">
            @if ($document->rows === [] && $document->sections === [] && ! $analysis)
                <div class="hris-card flex flex-col items-center gap-2 px-6 py-10 text-center print:hidden">
                    <h2 class="text-base font-semibold">No records match this report.</h2>
                    @if (in_array($type, ReportCatalog::PAYROLL, true) && $latestPayroll !== null && $period !== 'latest')
                        <p class="max-w-lg text-sm text-muted-foreground">No payroll was posted with a period ending {{ \App\Support\HrisFormat::date($from) }} – {{ \App\Support\HrisFormat::date($to) }}. The latest posted payroll is {{ \App\Support\HrisFormat::date($latestPayroll['from']) }} – {{ \App\Support\HrisFormat::date($latestPayroll['to']) }}.</p>
                        <flux:button type="button" class="mt-2" wire:click="setPeriod('latest')">Show the latest payroll</flux:button>
                    @else
                        <p class="max-w-lg text-sm text-muted-foreground">Try a different period or a broader filter.</p>
                    @endif
                </div>
            @else
                @include('livewire.reports.partials.summary')
                @if ($analysis)
                    @if ($document->charts !== [])
                        <div class="print:hidden">@include('livewire.reports.partials.charts')</div>
                    @endif
                    @include('livewire.reports.partials.results')
                    @include('livewire.reports.partials.analysis-sections')
                @elseif (! $sheets)
                    @include('livewire.reports.partials.results')
                @endif
            @endif
            <div @class(['hidden print:block' => ! $sheets])>@include('livewire.reports.partials.document')</div>
            @include('livewire.reports.partials.print-options')
        </section>
    @elseif ($allowed)
        <section class="hris-card flex flex-col items-center gap-2 px-6 py-10 text-center print:hidden" aria-label="Report preview">
            @if ($type === 'attendance-reports' && $employeeIds === [])
                <h2 class="text-base font-semibold">Choose employees to compare</h2>
                <p class="max-w-lg text-sm text-muted-foreground">Attendance covers the employees you choose, for up to 31 days. Nothing is read until you choose.</p>
                <flux:button type="button" variant="primary" icon="users" class="mt-2" wire:click="chooseEmployees">Choose employees</flux:button>
            @elseif ($type === 'loan-summary' && $deductionCodes === [])
                <h2 class="text-base font-semibold">Choose the loans to include</h2>
                <p class="max-w-lg text-sm text-muted-foreground">Tick the deduction types above. The report shows what was deducted, not loan balances.</p>
            @elseif ($errors->any())
                <h2 class="text-base font-semibold">This report could not be prepared</h2>
                <p class="max-w-lg text-sm text-muted-foreground">Change the filters above and it will be prepared again.</p>
            @else
                <flux:icon.document-chart-bar class="size-8 text-muted-foreground" />
                <h2 class="text-base font-semibold" role="status">Preparing your report…</h2>
            @endif
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
                    <div class="max-w-sm space-y-1 text-xs text-muted-foreground">
                        <p>{{ $type === 'attendance-reports' ? 'Choose at least one employee. For larger groups, use a shorter period to stay within 5,000 employee-days.' : 'An empty selection includes all permitted employees. The report filters still apply.' }}</p>
                        @if ($employeeIds !== [])
                            <button type="button" wire:click="clearEmployees" class="font-medium text-primary hover:underline">{{ $type === 'attendance-reports' ? 'Clear employee selection' : 'Include all permitted employees' }}</button>
                        @endif
                    </div>
                    <flux:modal.close><flux:button variant="primary">Done</flux:button></flux:modal.close>
                </footer>
            </div>
        </flux:modal>
    @endif
</div>
