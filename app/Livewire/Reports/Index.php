<?php

declare(strict_types=1);

namespace App\Livewire\Reports;

use App\Domain\Enums\ModuleCode;
use App\Livewire\Concerns\WithSorting;
use App\Livewire\Concerns\WithTableFilters;
use App\Livewire\Reports\Support\PrintOptions;
use App\Livewire\Reports\Support\ReportCatalog;
use App\Modules\Payroll\Application\Queries\PayrollReports;
use App\Modules\Timekeeping\Application\Queries\TimekeepingReports;
use App\Modules\Workforce\Application\Queries\EmployeeReport;
use App\Platform\Reports\ReportCriteria;
use App\Platform\Reports\ReportDocument;
use App\Support\Access;
use App\Support\ManilaClock;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Renderless;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * One report on its own page and address. Document filters stay explicit and every generation
 * is reauthorized: a changed filter drops the document on screen, and the page then asks for the
 * new one itself, so what is shown always matches the filters beside it.
 */
#[Layout('layouts.app')]
#[Title('Reports')]
class Index extends Component
{
    use WithSorting { sortBy as private changeSort; }
    use WithTableFilters;

    /** The Payroll Register's column sets on screen, by the owner's printed section they show. */
    private const array REGISTER_SETS = ['summary' => 0, 'earnings' => 1, 'deductions' => 2];

    /** The comparison's columns worth reading first: who, how late, how often absent, overtime approved, what to check. */
    private const array ATTENDANCE_SUMMARY = [1, 2, 4, 5, 6, 7, 8, 12, 14];

    /** Request-local only; avoids reading the same document twice on Generate. */
    private ?ReportDocument $documentForRequest = null;

    /** @var array{from: string, to: string}|null Request-local only; the newest posted payroll period, read once. */
    private ?array $latestPayroll = null;

    private bool $latestPayrollRead = false;

    #[Locked]
    public string $type = 'payroll-register';

    public string $from = '';

    public string $to = '';

    /**
     * Not Locked: the checklist edits this filter; criteria validate its shape
     * and every owner applies the IDs only after its source row scope.
     *
     * @var list<string>
     */
    public array $employeeIds = [];

    /** @var list<string> */
    public array $deductionCodes = [];

    /** @var array<string, string> Draft filters, validated again by the owner query. */
    public array $filters = [];

    /** @var array<string, string> Analysis controls are separate from directory and ranking filters. */
    public array $analysisFilters = [];

    /** @var list<string> */
    public array $columns = [];

    public string $view = 'summary';

    /** Which of a wide report's column sets the screen's table shows; print and CSV keep them all. */
    public string $columnsView = 'summary';

    /** The employee list's starting point last chosen, so the switch can show it; blank once a filter or column is changed by hand. */
    public string $preset = 'directory';

    /** @var array<string, string> Server-validated filters for the document being shown. */
    #[Locked]
    public array $generated = [];

    public function mount(?string $report = null): void
    {
        $actor = app(Access::class);
        abort_unless($actor->hasModule(ModuleCode::REPORTS), 403);
        $this->to = ManilaClock::todayKey();
        $this->from = substr($this->to, 0, 8).'01';
        if ($report !== null) {
            abort_unless(isset(ReportCatalog::all()[$report]), 404);
            $this->type = $report;
        } else {
            foreach (array_keys(ReportCatalog::all()) as $type) {
                if (ReportCatalog::allows($actor, $type)) {
                    $this->type = $type;
                    break;
                }
            }
        }
        $this->defaultFilters();
        $view = request()->query('view');
        if (is_string($view) && in_array($view, $this->views(), true)) {
            $this->view = $view;
        }
        // A payroll report opens on the newest posted payroll, never on a month with nothing in it yet.
        $latest = $this->latestPayroll();
        if ($latest !== null) {
            $this->from = $latest['from'];
            $this->to = $latest['to'];
        }
        $this->carryOver();
    }

    /**
     * The period and the employees chosen on the report the reader came from, so moving between a
     * report's views, or between sibling reports, does not ask for them again. They are filters like
     * any typed on this page: criteria validate them and every owner applies them after its row scope.
     */
    private function carryOver(): void
    {
        $from = request()->query('from');
        $to = request()->query('to');
        $day = '/\A\d{4}-\d{2}-\d{2}\z/';
        if (is_string($from) && is_string($to) && preg_match($day, $from) === 1 && preg_match($day, $to) === 1 && $from <= $to) {
            $this->from = $from;
            $this->to = $to;
        }
        $employees = request()->query('employees');
        if (is_string($employees) && $employees !== '' && $this->type !== 'audit-logs') {
            $ids = array_values(array_unique(explode(',', $employees)));
            if (count($ids) <= ReportCriteria::MAX_EMPLOYEES && array_filter($ids, static fn (string $id): bool => preg_match('/\A[^,\s]{1,26}\z/', $id) !== 1) === []) {
                $this->employeeIds = $ids;
            }
        }
    }

    /** @return array<string, string> What a link to another view or sibling report carries with it. */
    private function carried(): array
    {
        return array_filter(['from' => $this->from, 'to' => $this->to, 'employees' => implode(',', $this->employeeIds)]);
    }

    public function selectReport(string $type): void
    {
        abort_unless(ReportCatalog::allows(app(Access::class), $type), 403);
        if ($this->type === $type) {
            return;
        }
        $this->type = $type;
        $this->employeeIds = [];
        $this->deductionCodes = [];
        $this->defaultFilters();
        $this->updated();
    }

    public function updated(string $property = ''): void
    {
        if ($property === 'perPage' || str_starts_with($property, 'paginators.')) {
            return;
        }
        if ($property === 'columnsView') {
            // Which columns the screen shows changes nothing about the document or its coverage.
            $this->resetPage();

            return;
        }
        if ($property === 'sortKey' && $this->type === 'attendance-reports' && $this->sortKey === 'employee') {
            $this->filters['minimum'] = '0';
        }
        if (str_starts_with($property, 'columns') || str_starts_with($property, 'filters.')) {
            $this->preset = '';
        }
        $this->invalidate(true);
    }

    /** A column set this report does not have falls back to the first. */
    public function updatedColumnsView(): void
    {
        if (! isset($this->columnSets()[$this->columnsView])) {
            $this->columnsView = 'summary';
        }
    }

    /**
     * Drops the document on screen. `$rerun` then has the page ask for the new one, when the
     * filters as they stand can produce a report at all.
     */
    private function invalidate(bool $rerun): void
    {
        $this->documentForRequest = null;
        $this->generated = [];
        $this->resetPage();
        $this->resetValidation();
        if ($rerun && $this->runnable()) {
            $this->js('$wire.generate()');
        }
    }

    /** Whether the filters as they stand name everything their report needs before it can be read. */
    private function runnable(): bool
    {
        return ReportCatalog::allows(app(Access::class), $this->type)
            && ! ($this->type === 'attendance-reports' && $this->employeeIds === [])
            && ! ($this->type === 'loan-summary' && $this->deductionCodes === [])
            && ! ($this->type === 'employee-list' && $this->view !== 'analysis' && $this->columns === []);
    }

    /** @return list<string> */
    private function views(): array
    {
        return match ($this->type) {
            'attendance-reports' => ['summary', 'details', 'analysis'],
            'employee-list' => ['summary', 'analysis'],
            default => ['summary'],
        };
    }

    /** @return array<string, string> The column sets this report's table can show, none for a report narrow enough to show whole. */
    private function columnSets(): array
    {
        return match (true) {
            $this->type === 'payroll-register' => ['summary' => 'Summary', 'earnings' => 'Earnings', 'deductions' => 'Deductions', 'all' => 'Everything'],
            $this->type === 'attendance-reports' && $this->view === 'summary' => ['summary' => 'Summary', 'all' => 'All Columns'],
            default => [],
        };
    }

    /** @return array{from: string, to: string}|null */
    private function latestPayroll(): ?array
    {
        if (! $this->latestPayrollRead) {
            $this->latestPayrollRead = true;
            $actor = app(Access::class);
            $this->latestPayroll = in_array($this->type, ReportCatalog::PAYROLL, true) && ReportCatalog::allows($actor, $this->type)
                ? app(PayrollReports::class)->latestPeriod($actor) : null;
        }

        return $this->latestPayroll;
    }

    private function defaultFilters(): void
    {
        $this->filters = $this->type === 'employee-list' ? [
            'search' => '', 'active' => 'ALL', 'employment' => 'ALL', 'department' => 'ALL',
            'position' => 'ALL', 'worksite' => 'ALL', 'sex' => 'ALL', 'civilStatus' => 'ALL',
            'education' => 'ALL', 'missing' => 'ALL', 'ageMin' => '', 'ageMax' => '',
            'tenureMin' => '', 'tenureMax' => '', 'hiredFrom' => '', 'hiredTo' => '',
        ] : ($this->type === 'attendance-reports' ? [
            'search' => '', 'issue' => 'all', 'minimum' => '0', 'limit' => 'all',
        ] : []);
        $this->columns = $this->type === 'employee-list'
            ? ['employee_id', 'employee', 'department', 'unit', 'position', 'employment', 'active', 'worksite'] : [];
        $this->sortKey = $this->type === 'attendance-reports' ? 'late_minutes' : ($this->type === 'employee-list' ? 'employee' : '');
        $this->sortDir = $this->type === 'attendance-reports' ? 'desc' : 'asc';
        $this->view = 'summary';
        $this->columnsView = 'summary';
        $this->preset = 'directory';
        $this->analysisFilters = $this->type === 'employee-list'
            ? ['analysis' => 'composition', 'groupBy' => 'department', 'crossTab' => 'sex']
            : ($this->type === 'attendance-reports' ? ['groupBy' => 'department', 'compare' => 'none', 'threshold' => '3'] : []);
    }

    public function resetFilters(): void
    {
        abort_unless(ReportCatalog::allows(app(Access::class), $this->type), 403);
        $view = $this->view;
        $this->employeeIds = [];
        $this->deductionCodes = [];
        $this->defaultFilters();
        // The view is the page the reader is on, not one of its filters.
        $this->view = $view;
        $this->updated();
    }

    public function applyPreset(string $preset): void
    {
        abort_unless(ReportCatalog::allows(app(Access::class), $this->type), 403);
        if ($this->type === 'employee-list') {
            abort_unless(in_array($preset, ['directory', 'profile', 'missing', 'workforce', 'demographics', 'development', 'service', 'quality'], true), 422);
            $this->defaultFilters();
            if ($preset === 'profile') {
                $this->columns = ['employee_id', 'employee', 'sex', 'civil_status', 'age', 'date_hired', 'tenure', 'education'];
            } elseif ($preset === 'missing') {
                $this->filters['missing'] = 'ANY';
                $this->columns = ['employee_id', 'employee', 'department', 'position', 'email', 'mobile', 'missing'];
            } elseif (in_array($preset, ['workforce', 'demographics', 'development', 'service', 'quality'], true)) {
                $this->view = 'analysis';
                $this->analysisFilters['analysis'] = $preset === 'workforce' ? 'composition' : $preset;
                $this->analysisFilters['groupBy'] = match ($preset) {
                    'demographics' => 'age', 'development' => 'education', 'service' => 'tenure', default => 'department',
                };
            }
            $this->updated();
            $this->preset = in_array($preset, ['directory', 'profile', 'missing'], true) ? $preset : '';

            return;
        } elseif ($this->type === 'attendance-reports') {
            if ($preset === 'attendance-analysis') {
                $this->view = 'analysis';
                $this->updated();

                return;
            }
            $presets = [
                'late' => ['late_minutes', 'late'], 'frequent' => ['late_days', 'late'],
                'undertime' => ['undertime_minutes', 'undertime'], 'absences' => ['absences', 'absent'],
            ];
            abort_unless(isset($presets[$preset]), 422);
            [$this->sortKey, $this->filters['issue']] = $presets[$preset];
            $this->sortDir = 'desc';
            $this->filters['minimum'] = '1';
            $this->view = 'summary';
        } else {
            abort(422);
        }
        $this->updated();
    }

    /** @return list<string> */
    protected function sortableKeys(): array
    {
        return array_keys($this->sortOptions());
    }

    /** @return array<string, string> */
    private function sortOptions(): array
    {
        if ($this->view === 'analysis') {
            return [];
        }

        return match ($this->type) {
            'employee-list' => EmployeeReport::columns(),
            'attendance-reports' => TimekeepingReports::rankingSorts(),
            default => [],
        };
    }

    public function sortBy(string $key): void
    {
        abort_unless(ReportCatalog::allows(app(Access::class), $this->type), 403);
        if (! in_array($key, $this->sortableKeys(), true)) {
            return;
        }
        $regenerate = $this->generated !== [];
        $this->changeSort($key);
        if ($this->type === 'attendance-reports' && $key === 'employee') {
            $this->filters['minimum'] = '0';
        }
        $this->invalidate(! $regenerate);
        if ($regenerate) {
            $this->generate();
        }
    }

    public function clearEmployees(): void
    {
        abort_unless(ReportCatalog::allows(app(Access::class), $this->type), 403);
        $this->employeeIds = [];
        $this->updated();
    }

    #[Renderless]
    public function chooseEmployees(): void
    {
        abort_unless(ReportCatalog::allows(app(Access::class), $this->type), 403);
        abort_if($this->type === 'audit-logs', 403);
        $this->modal('report-employees')->show();
    }

    public function setPeriod(string $preset): void
    {
        abort_unless(ReportCatalog::allows(app(Access::class), $this->type), 403);
        abort_if($this->type === 'employee-list' && $this->view !== 'analysis', 422);
        $period = $this->periods()[$preset] ?? null;
        abort_if($period === null, 422);
        $this->from = $period['from'];
        $this->to = $period['to'];
        $this->updated();
    }

    /** @return array<string, array{label: string, from: string, to: string}> Manila calendar dates, including today. */
    private function periods(): array
    {
        $today = ManilaClock::instant(ManilaClock::todayKey().'T00:00:00')->setTimezone(ManilaClock::TZ);
        $previous = $today->subMonthNoOverflow();
        $latest = $this->latestPayroll();

        return [
            ...($latest === null ? [] : ['latest' => ['label' => 'Latest payroll', 'from' => $latest['from'], 'to' => $latest['to']]]),
            'month' => ['label' => 'Month to date', 'from' => $today->startOfMonth()->format('Y-m-d'), 'to' => $today->format('Y-m-d')],
            'previous-month' => ['label' => 'Previous month', 'from' => $previous->startOfMonth()->format('Y-m-d'), 'to' => $previous->endOfMonth()->format('Y-m-d')],
            'last-seven' => ['label' => 'Last 7 days', 'from' => $today->subDays(6)->format('Y-m-d'), 'to' => $today->format('Y-m-d')],
            'last-thirty' => ['label' => 'Last 30 days', 'from' => $today->subDays(29)->format('Y-m-d'), 'to' => $today->format('Y-m-d')],
        ];
    }

    public function generate(): void
    {
        $actor = app(Access::class);
        abort_unless(ReportCatalog::allows($actor, $this->type), 403);
        $this->generated = [];
        $this->documentForRequest = null;
        $this->resetValidation();
        if ($this->type === 'employee-list' && $this->view !== 'analysis' && $this->columns === []) {
            $this->addError('columns', 'Choose at least one report column.');

            return;
        }
        $dailySheets = $this->type === 'attendance-reports' && $this->view === 'details';
        $analysis = $this->view === 'analysis';
        $filters = $analysis
            ? ($this->type === 'employee-list' ? [...$this->filters, ...$this->analysisFilters] : $this->analysisFilters)
            : ($dailySheets ? [] : $this->filters);
        $criteria = ReportCriteria::from([
            'type' => $this->type, 'from' => $this->from, 'to' => $this->to,
            'employeeIds' => $this->employeeIds, 'deductionCodes' => $this->deductionCodes,
            'filters' => $filters, 'columns' => $analysis ? [] : $this->columns,
            'sort' => $dailySheets || $analysis ? '' : $this->sortKey, 'direction' => $dailySheets || $analysis ? 'asc' : $this->sortDir, 'view' => $this->view,
        ]);
        $this->documentForRequest = ReportCatalog::generate($actor, $criteria);
        $this->generated = $criteria->query();
    }

    /**
     * The table the screen draws from a document: the whole of it, or the column set chosen
     * for a report too wide to read at once. Print and CSV never pass through here.
     *
     * @return array{columns: list<string>, rows: list<list<string>>, totals: list<string>, numeric: list<int>, keys: list<string>}
     */
    private function tableFor(ReportDocument $document, ReportCriteria $criteria): array
    {
        $section = $criteria->type === 'payroll-register' ? ($document->sections[self::REGISTER_SETS[$this->columnsView] ?? -1] ?? null) : null;
        if ($section !== null) {
            return ['columns' => $section['columns'], 'rows' => $section['rows'], 'totals' => $section['totals'] ?? [],
                'numeric' => $section['numericColumns'] ?? [], 'keys' => []];
        }
        if ($criteria->type === 'attendance-reports' && $criteria->view === 'summary' && $this->columnsView === 'summary') {
            $pick = static fn (array $cells): array => array_map(static fn (int $index): string => (string) ($cells[$index] ?? ''), self::ATTENDANCE_SUMMARY);

            return ['columns' => $pick($document->columns), 'rows' => array_map($pick, $document->rows),
                'totals' => $document->totals === [] ? [] : $pick($document->totals),
                'numeric' => array_keys(array_filter(self::ATTENDANCE_SUMMARY, static fn (int $index): bool => in_array($index, $document->numericColumns, true))),
                'keys' => $pick($document->columnKeys)];
        }

        return ['columns' => $document->columns, 'rows' => $document->rows, 'totals' => $document->totals,
            'numeric' => $document->numericColumns, 'keys' => $document->columnKeys];
    }

    public function render(): View
    {
        $actor = app(Access::class);
        abort_unless($actor->hasModule(ModuleCode::REPORTS), 403);
        $allowed = ReportCatalog::allows($actor, $this->type);
        $criteria = $this->generated === [] ? null : ReportCriteria::from($this->generated);
        $document = $criteria === null ? null : ($this->documentForRequest ?? ReportCatalog::generate($actor, $criteria));
        // Payslips and daily sheets are documents by nature: the screen shows the sheets themselves.
        $sheets = $criteria !== null && $document !== null && $document->sections !== []
            && ($criteria->type === 'payslips' || ($criteria->type === 'attendance-reports' && $criteria->view === 'details'));
        $table = $document === null || $sheets ? null : $this->tableFor($document, $criteria);

        return view('livewire.reports.index', [
            'catalog' => ReportCatalog::all(), 'actor' => $actor,
            'switch' => ReportCatalog::switch($this->type, $this->view),
            'printTitle' => ReportCatalog::printTitle($this->type, $this->view),
            'carried' => $this->carried(),
            'printOptions' => $document === null ? ['parts' => [], 'tables' => []] : PrintOptions::for($this->type, $this->view, $document, $sheets),
            'periods' => $this->periods(),
            'latestPayroll' => $this->latestPayroll(),
            'allowed' => $allowed,
            'runnable' => $this->runnable(),
            'document' => $document,
            'sheets' => $sheets,
            'table' => $table,
            'columnSets' => $this->columnSets(),
            'previewRows' => $table === null ? null : $this->paginateClamped($table['rows']),
            'columnOptions' => EmployeeReport::columns(),
            'sortOptions' => $this->sortOptions(),
            'analysisTopics' => EmployeeReport::analysisTopics(),
            'analysisGroups' => $this->type === 'employee-list' ? EmployeeReport::groupings() : TimekeepingReports::analysisGroups(),
            'analysisCrossTabs' => EmployeeReport::crossTabs(),
            'employeeOptions' => $this->type === 'employee-list' && $allowed
                ? app(EmployeeReport::class)->options($actor) : [],
            'loanTypes' => $this->type === 'loan-summary' && $allowed
                ? app(PayrollReports::class)->loanTypes($actor) : [],
            'generatedAt' => ManilaClock::instant(now()->toIso8601String())->setTimezone(ManilaClock::TZ)->format('d M Y, g:i A'),
        ]);
    }
}
