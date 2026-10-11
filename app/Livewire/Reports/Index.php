<?php

declare(strict_types=1);

namespace App\Livewire\Reports;

use App\Domain\Enums\ModuleCode;
use App\Livewire\Concerns\WithSorting;
use App\Livewire\Concerns\WithTableFilters;
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

/** On-demand reporting keeps document filters explicit and reauthorizes every generation. */
#[Layout('layouts.app')]
#[Title('Reports')]
class Index extends Component
{
    use WithSorting { sortBy as private changeSort; }
    use WithTableFilters;

    /** Request-local only; avoids reading the same document twice on Generate. */
    private ?ReportDocument $documentForRequest = null;

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

    /** @var array<string, string> Server-validated filters for the document being shown. */
    #[Locked]
    public array $generated = [];

    public function mount(): void
    {
        $actor = app(Access::class);
        abort_unless($actor->hasModule(ModuleCode::REPORTS), 403);
        $this->to = ManilaClock::todayKey();
        $this->from = substr($this->to, 0, 8).'01';
        foreach (array_keys(ReportCatalog::all()) as $type) {
            if (ReportCatalog::allows($actor, $type)) {
                $this->type = $type;
                break;
            }
        }
        $this->defaultFilters();
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
        if ($property === 'sortKey' && $this->type === 'attendance-reports' && $this->sortKey === 'employee') {
            $this->filters['minimum'] = '0';
        }
        $this->documentForRequest = null;
        $this->generated = [];
        $this->resetPage();
        $this->resetValidation();
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
        $this->analysisFilters = $this->type === 'employee-list'
            ? ['analysis' => 'composition', 'groupBy' => 'department', 'crossTab' => 'sex']
            : ($this->type === 'attendance-reports' ? ['groupBy' => 'department', 'compare' => 'none', 'threshold' => '3'] : []);
    }

    public function resetFilters(): void
    {
        abort_unless(ReportCatalog::allows(app(Access::class), $this->type), 403);
        $this->employeeIds = [];
        $this->deductionCodes = [];
        $this->defaultFilters();
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
        $this->updated();
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

        return [
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

    public function render(): View
    {
        $actor = app(Access::class);
        abort_unless($actor->hasModule(ModuleCode::REPORTS), 403);
        $criteria = $this->generated === [] ? null : ReportCriteria::from($this->generated);
        $document = $criteria === null ? null : ($this->documentForRequest ?? ReportCatalog::generate($actor, $criteria));

        return view('livewire.reports.index', [
            'catalog' => ReportCatalog::all(), 'actor' => $actor,
            'groups' => collect(ReportCatalog::all())->groupBy('category', preserveKeys: true),
            'periods' => $this->periods(),
            'allowed' => ReportCatalog::allows($actor, $this->type),
            'document' => $document,
            'previewRows' => $document === null || ($document->sections !== [] && $criteria->view !== 'analysis') ? null : $this->paginateClamped($document->rows),
            'columnOptions' => EmployeeReport::columns(),
            'sortOptions' => $this->sortOptions(),
            'analysisTopics' => EmployeeReport::analysisTopics(),
            'analysisGroups' => $this->type === 'employee-list' ? EmployeeReport::groupings() : TimekeepingReports::analysisGroups(),
            'analysisCrossTabs' => EmployeeReport::crossTabs(),
            'employeeOptions' => $this->type === 'employee-list' && ReportCatalog::allows($actor, $this->type)
                ? app(EmployeeReport::class)->options($actor) : [],
            'loanTypes' => $this->type === 'loan-summary' && ReportCatalog::allows($actor, $this->type)
                ? app(PayrollReports::class)->loanTypes($actor) : [],
            'generatedAt' => ManilaClock::instant(now()->toIso8601String())->setTimezone(ManilaClock::TZ)->format('d M Y, g:i A'),
        ]);
    }
}
