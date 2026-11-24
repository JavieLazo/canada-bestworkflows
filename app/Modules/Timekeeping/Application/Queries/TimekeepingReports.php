<?php

declare(strict_types=1);

namespace App\Modules\Timekeeping\Application\Queries;

use App\Domain\Attendance\CalendarDays;
use App\Domain\Attendance\DayRecord;
use App\Domain\Attendance\DtrCalculator;
use App\Domain\Attendance\DtrSummary;
use App\Domain\Attendance\HolidaySetting;
use App\Domain\Attendance\Shift as DomainShift;
use App\Domain\Enums\ModuleCode;
use App\Models\LeaveTravelRequest;
use App\Models\LeaveType;
use App\Modules\Organization\Contracts\HolidayCalendar;
use App\Modules\Timekeeping\Application\LeavePayDates;
use App\Modules\Timekeeping\Contracts\AttendanceSource;
use App\Modules\Timekeeping\Contracts\OpenPunchLimit;
use App\Modules\Timekeeping\Domain\Absence\LeaveCreditPolicy;
use App\Modules\Timekeeping\Domain\Attendance\DayClose;
use App\Modules\Timekeeping\Domain\Roster\ScheduleBasis;
use App\Modules\Workforce\Contracts\DirectoryEmployee;
use App\Modules\Workforce\Contracts\EmployeeReportContext;
use App\Modules\Workforce\Contracts\OperationalDirectory;
use App\Platform\Reports\ReportCriteria;
use App\Platform\Reports\ReportDocument;
use App\Support\Access;
use App\Support\EmployeeIdentity;
use App\Support\EmployeeName;
use App\Support\HrisFormat;
use App\Support\LocalClock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Reports retain Timekeeping's row scope and use its existing attendance and leave rules. */
final class TimekeepingReports
{
    /** @return array<string, string> Current organizational labels for the selected employee cohort. */
    public static function analysisGroups(): array
    {
        return AttendanceAnalysis::groups();
    }

    /** @return array<string, string> Numeric keys retain their minute or day units before display formatting. */
    public static function rankingSorts(): array
    {
        return [
            'late_minutes' => 'Late minutes',
            'late_days' => 'Days late',
            'undertime_minutes' => 'Undertime minutes',
            'undertime_days' => 'Days with undertime',
            'absences' => 'Absence days',
            'regular_minutes' => 'Regular minutes',
            'overtime_minutes' => 'Computed overtime minutes',
            'employee' => 'Employee name',
        ];
    }

    public function generate(Access $actor, ReportCriteria $criteria): ReportDocument
    {
        abort_unless($actor->hasModule(ModuleCode::REPORTS), 403);

        return match ($criteria->type) {
            'leave-reports' => $this->leaves($actor, $criteria),
            'attendance-reports' => $this->attendance($actor, $criteria),
            default => abort(404),
        };
    }

    private function leaves(Access $actor, ReportCriteria $criteria): ReportDocument
    {
        abort_unless($actor->hasModule(ModuleCode::LEAVES), 403);
        $query = LeaveTravelRequest::query()->with('cancellations:id,leave_travel_request_id,date')->toEmployee($actor, 'employee_id', ModuleCode::LEAVES)
            ->whereDate('start_date', '<=', $criteria->to)->whereDate('end_date', '>=', $criteria->from);
        if ($criteria->employeeIds !== []) {
            $query->whereIn('employee_id', $criteria->employeeIds);
        }
        $requests = $query->orderBy('start_date')->orderBy('code')->orderBy('id')
            ->limit(ReportDocument::MAX_ROWS + 1)
            ->get(['id', 'code', 'employee_id', 'request_type', 'day_type', 'start_date', 'end_date', 'status', 'is_paid', ...(LeavePayDates::ready() ? ['unpaid_dates'] : [])]);
        ReportDocument::guardSize($requests->count());
        $employees = $requests->isEmpty() ? collect() : app(OperationalDirectory::class)
            ->list($actor, array_values($requests->pluck('employee_id')->unique()->all()))->keyBy('id');
        $typeNames = [];
        foreach (LeaveType::query()->orderBy('name')->get(['name']) as $type) {
            $typeNames[] = $type->name;
        }
        $rows = [];
        $requestedDays = 0.0;
        $approvedLeaveDays = 0.0;
        foreach ($requests as $request) {
            $employee = $employees->get($request->employee_id);
            $start = $request->start_date->format('Y-m-d');
            $end = $request->end_date->format('Y-m-d');
            $cancelledDates = array_values(array_filter($request->cancelledDates(),
                static fn (string $date): bool => $date >= $criteria->from && $date <= $criteria->to));
            $days = LeaveCreditPolicy::coveredDays($request->day_type, max($start, $criteria->from), min($end, $criteria->to), $cancelledDates);
            $requestedDays += $days;
            if ($request->status === 'APPROVED' && $request->request_type !== 'BUSINESS_TRAVEL') {
                $approvedLeaveDays += $days;
            }
            $pay = $request->request_type === 'BUSINESS_TRAVEL' ? 'Not applicable'
                : (! in_array($request->status, ['APPROVED', 'CANCELLED'], true) ? 'Not decided' : ($request->is_paid === false ? 'Unpaid' : (LeavePayDates::partly($request) ? 'Partly paid' : 'Paid')));
            $rows[] = [
                $request->code,
                EmployeeIdentity::number($employee),
                EmployeeName::display($employee),
                HrisFormat::requestDescription($request->request_type, $typeNames),
                $start,
                $end,
                $request->day_type === 'HALF_DAY' ? 'Half day' : 'Whole day',
                LeaveCreditPolicy::format($days),
                HrisFormat::requestStatus($request->status),
                $pay,
                implode(', ', $cancelledDates),
            ];
        }

        return new ReportDocument('Leave Reports', ['Request', 'Employee ID', 'Employee', 'Type', 'Start Date', 'End Date', 'Day Type', 'Days in Period', 'Status', 'Pay', 'Cancelled Dates'], $rows, [
            'Requests overlapping the selected period, including Business Travel / OB. Days in period count remaining covered calendar days after cancellations, with half days counted as 0.5.',
            'Pending, disapproved, withdrawn and cancelled requests are shown as requests; they are not approved leave usage.',
            'The table total counts requested covered days across all statuses, including OB; the approved leave metric excludes OB. Neither figure is a leave-credit ledger balance.',
        ], [], [
            ['label' => 'Requests', 'value' => (string) $requests->count()],
            ['label' => 'Employees', 'value' => (string) $requests->pluck('employee_id')->unique()->count()],
            ['label' => 'Approved Leave Days', 'value' => LeaveCreditPolicy::format($approvedLeaveDays)],
            ['label' => 'Pending Requests', 'value' => (string) $requests->where('status', 'PENDING')->count()],
        ], ['Requested Days', '', '', '', '', '', '', LeaveCreditPolicy::format($requestedDays), '', '', ''], [7]);
    }

    /**
     * Approved leave days in a period, per leave type, largest first — the Reports home's graph.
     * The same scope, day count and exclusions as the Leave Requests report: business travel is
     * not leave, and a cancelled date is not a leave day.
     *
     * @return list<array{label: string, value: float}>
     */
    public function approvedLeaveByType(Access $actor, string $from, string $to): array
    {
        abort_unless($actor->hasModule(ModuleCode::REPORTS) && $actor->hasModule(ModuleCode::LEAVES), 403);
        $requests = LeaveTravelRequest::query()->with('cancellations:id,leave_travel_request_id,date')->toEmployee($actor, 'employee_id', ModuleCode::LEAVES)
            ->where('status', 'APPROVED')->where('request_type', '!=', 'BUSINESS_TRAVEL')
            ->whereDate('start_date', '<=', $to)->whereDate('end_date', '>=', $from)
            ->orderBy('start_date')->orderBy('id')->limit(ReportDocument::MAX_ROWS + 1)
            ->get(['id', 'request_type', 'day_type', 'start_date', 'end_date']);
        ReportDocument::guardSize($requests->count());
        $typeNames = [];
        foreach (LeaveType::query()->orderBy('name')->get(['name']) as $type) {
            $typeNames[] = $type->name;
        }
        $days = [];
        foreach ($requests as $request) {
            $cancelled = array_values(array_filter($request->cancelledDates(),
                static fn (string $date): bool => $date >= $from && $date <= $to));
            $covered = LeaveCreditPolicy::coveredDays($request->day_type,
                max($request->start_date->format('Y-m-d'), $from), min($request->end_date->format('Y-m-d'), $to), $cancelled);
            $label = HrisFormat::requestDescription($request->request_type, $typeNames);
            $days[$label] = ($days[$label] ?? 0.0) + $covered;
        }
        $days = array_filter($days, static fn (float $total): bool => $total > 0);
        uksort($days, static fn (string $left, string $right): int => ($days[$right] <=> $days[$left]) ?: strnatcasecmp($left, $right));
        $points = [];
        foreach ($days as $label => $total) {
            $points[] = ['label' => (string) $label, 'value' => $total];
        }

        return $points;
    }

    private function attendance(Access $actor, ReportCriteria $criteria): ReportDocument
    {
        abort_unless($actor->hasModule(ModuleCode::EDTR), 403);
        if ($criteria->employeeIds === []) {
            throw ValidationException::withMessages(['employeeIds' => 'Choose at least one employee for the attendance report.']);
        }
        $dayCount = count(CalendarDays::each($criteria->from, $criteria->to));
        if ($dayCount > 31) {
            throw ValidationException::withMessages(['to' => 'Choose no more than 31 days for an attendance report.']);
        }
        foreach ($criteria->employeeIds as $employeeId) {
            abort_unless($actor->mayReadAllData(ModuleCode::EDTR)
                || ($actor->hasEmployeeLink() && $employeeId === $actor->ownEmployeeId()), 403);
        }
        $analysisFilters = $criteria->view === 'analysis' ? AttendanceAnalysis::filters($criteria) : [];
        // Bound the WHOLE employee-day request before any attendance reads or
        // calculations. A future-only range does not bypass the report limit.
        ReportDocument::guardSize(count($criteria->employeeIds) * $dayCount * (($analysisFilters['compare'] ?? 'none') === 'previous' ? 2 : 1));
        $employees = app(OperationalDirectory::class)->list($actor, $criteria->employeeIds);
        if ($employees->count() !== count($criteria->employeeIds)) {
            throw ValidationException::withMessages(['employeeIds' => 'One or more selected employees are unavailable.']);
        }
        if ($criteria->view === 'analysis') {
            return $this->analysis($actor, $criteria, $employees, $analysisFilters, $dayCount);
        }
        $rankingFilters = $criteria->view === 'summary' ? $this->rankingFilters($criteria) : [];

        $holidays = app(HolidayCalendar::class)->all();
        $openPunchHours = app(OpenPunchLimit::class)->hours();
        $now = CarbonImmutable::now();
        $rows = [];
        $sections = [];
        $columns = [];
        $ranking = [];
        foreach ($employees as $employee) {
            $attendance = $this->employeeAttendance($actor, $criteria, $employee, $holidays, $openPunchHours, $now);
            if ($attendance['document'] === null) {
                $ranking[] = ['employee' => $employee, 'metrics' => $attendance['metrics']];

                continue;
            }
            $report = $attendance['document'];
            $columns = ['Employee ID', 'Employee', 'Position', ...$report->columns];
            foreach ($report->rows as $row) {
                $rows[] = [EmployeeIdentity::number($employee), EmployeeName::display($employee), $employee->position ?? '', ...$row];
            }
            $sections[] = [
                'title' => $report->title, 'kind' => 'attendance', 'columns' => $report->columns, 'rows' => $report->rows, 'notes' => $report->notes,
                'identity' => [
                    ['label' => 'Employee ID', 'value' => EmployeeIdentity::number($employee)],
                    ['label' => 'Employee', 'value' => EmployeeName::display($employee)],
                    ['label' => 'Position', 'value' => $employee->position ?: 'Not recorded'],
                ],
                'highlights' => $report->summary, 'totals' => $report->totals, 'numericColumns' => $report->numericColumns,
            ];
        }

        if ($criteria->view === 'summary') {
            return $this->attendanceRanking($rankingFilters, $ranking, $dayCount);
        }

        return new ReportDocument('Attendance Reports', $columns, $rows, [
            'Current attendance for '.$employees->count().' selected employee(s), with totals shown separately for each employee.',
        ], $sections, [
            ['label' => 'Employees', 'value' => (string) $employees->count()],
            ['label' => 'Employee-Days Shown', 'value' => (string) count($rows)],
            ['label' => 'Days in Selected Period', 'value' => (string) $dayCount],
        ], [], range(8, 12));
    }

    /**
     * @param  Collection<int, DirectoryEmployee>  $employees
     * @param  array<string, string>  $filters
     */
    private function analysis(Access $actor, ReportCriteria $criteria, Collection $employees, array $filters, int $dayCount): ReportDocument
    {
        $contexts = app(EmployeeReportContext::class)->attendanceContext($actor, $criteria->employeeIds);
        if (count($contexts) !== $employees->count()) {
            throw ValidationException::withMessages(['employeeIds' => 'Employment coverage is unavailable for one or more selected employees.']);
        }
        $holidays = app(HolidayCalendar::class)->all();
        $openPunchHours = app(OpenPunchLimit::class)->hours();
        $now = CarbonImmutable::now();
        $previousTo = CalendarDays::previous($criteria->from);
        $previousFrom = $previousTo;
        for ($offset = 1; $offset < $dayCount; $offset++) {
            $previousFrom = CalendarDays::previous($previousFrom);
        }
        $current = [];
        $previous = [];
        foreach ($employees as $employee) {
            $context = $contexts[$employee->id];
            $current[$employee->id] = $this->analysisDays($actor, $employee->id, $criteria->from, $criteria->to, $context, $holidays, $openPunchHours, $now);
            if ($filters['compare'] === 'previous') {
                $previous[$employee->id] = $this->analysisDays($actor, $employee->id, $previousFrom, $previousTo, $context, $holidays, $openPunchHours, $now);
            }
        }

        return AttendanceAnalysis::document($criteria, $filters, $employees->keyBy('id')->all(), $contexts, $current, $previous, $previousFrom, $previousTo);
    }

    /**
     * @param  array{department: string, unit: string, position: string, worksite: string, employment: string, active: bool, date_hired: string, date_resigned: ?string}  $context
     * @param  list<HolidaySetting>  $holidays
     * @return list<array{date: string, excluded: string, values: array<string, float>, issues: list<string>, leave: string}>
     */
    private function analysisDays(Access $actor, string $employeeId, string $from, string $to, array $context, array $holidays, float $openPunchHours, CarbonImmutable $now): array
    {
        $through = min($to, LocalClock::dayKey($now));
        $days = [];
        $schedule = null;
        if ($from <= $through) {
            $inputs = app(AttendanceSource::class)->forDtr($actor, $employeeId, $from, $through);
            $schedule = $inputs->shift;
            $days = DtrCalculator::compute($inputs->logs, $schedule, $inputs->leaves, $from, $through, $holidays);
        }
        $scheduleDays = $days === [] ? [] : app(ScheduleDays::class)->forEmployee($actor, $employeeId, $from, $through);
        $overtime = app(OvertimeDecisions::class)->forDays($actor, $employeeId, $days, $from, $through);

        return AttendanceAnalysis::days($from, $to, $days, $schedule, $scheduleDays, $overtime, $context, $holidays, $openPunchHours, $now);
    }

    /**
     * @param  list<HolidaySetting>  $holidays
     * @return array{document: ReportDocument|null, metrics: array<string, int|float>}
     */
    private function employeeAttendance(Access $actor, ReportCriteria $criteria, DirectoryEmployee $employee, array $holidays, float $openPunchHours, CarbonImmutable $now): array
    {
        $details = $criteria->view !== 'summary';
        $lastComputed = min($criteria->to, LocalClock::todayKey());
        $days = [];
        $schedule = null;
        if ($criteria->from <= $lastComputed) {
            $inputs = app(AttendanceSource::class)->forDtr($actor, $employee->id, $criteria->from, $lastComputed);
            $schedule = $inputs->shift;
            $days = DtrCalculator::compute($inputs->logs, $schedule, $inputs->leaves, $criteria->from, $lastComputed, $holidays);
        }
        $overtime = app(OvertimeDecisions::class)->forDays($actor, $employee->id, $days, $criteria->from, $lastComputed);
        $remarks = $details ? app(AttendanceRemarks::class)->between($actor, $employee->id, $criteria->from, $lastComputed) : [];
        $scheduleDays = $days === [] ? [] : app(ScheduleDays::class)->forEmployee($actor, $employee->id, $criteria->from, $lastComputed);
        $rows = [];
        $unscheduled = 0;
        $payable = 0;
        $approved = 0;
        $undertimeDays = 0;
        $incomplete = 0;
        $provisional = 0;
        foreach ($days as $day) {
            $decision = $overtime[$day->date] ?? null;
            $payable += $decision->payable_minutes ?? 0;
            $approved += $decision->approved_minutes ?? 0;
            if (($scheduleDays[$day->date] ?? null)?->basis === ScheduleBasis::NONE) {
                $unscheduled++;
            }
            $undertimeDays += $day->undertimeMinutes > 0 ? 1 : 0;
            $incomplete += $day->status === DayRecord::INCOMPLETE ? 1 : 0;
            $provisional += DayClose::isOver($day->date, ($schedule ?? DomainShift::default())->forDate($day->date), $openPunchHours, $now) ? 0 : 1;
            if (! $details) {
                continue;
            }
            $rows[] = [
                $day->date,
                $this->punch($day->timeIn, $day->date),
                $this->punch($day->breakOut, $day->date),
                $this->punch($day->breakIn, $day->date),
                $this->punch($day->timeOut, $day->date),
                HrisFormat::duration($day->regularMinutes()),
                HrisFormat::duration($day->lateMinutes),
                HrisFormat::duration($day->undertimeMinutes),
                HrisFormat::duration($day->overtimeMinutes),
                $decision?->approved_minutes === null ? '—' : HrisFormat::duration($decision->approved_minutes),
                $decision->status ?? '—',
                $day->status,
                $remarks[$day->date] ?? $day->remarks ?? '',
            ];
        }
        $summary = DtrSummary::of($days);
        $absences = DayClose::absences($days, $schedule, $openPunchHours, $now);
        $metrics = [
            'late_minutes' => $summary->lateMinutes, 'late_days' => $summary->lates,
            'undertime_minutes' => $summary->undertimeMinutes, 'undertime_days' => $undertimeDays,
            'absences' => $absences, 'incomplete_days' => $incomplete,
            'regular_minutes' => $summary->regularMinutes(), 'overtime_minutes' => $summary->overtimeMinutes,
            'approved_minutes' => $approved, 'payable_minutes' => $payable,
            'unscheduled_days' => $unscheduled, 'provisional_days' => $provisional,
        ];
        if (! $details) {
            return ['document' => null, 'metrics' => $metrics];
        }
        $notes = [
            'Position: '.($employee->position ?: 'Not recorded').'.',
            'Current attendance through '.$lastComputed.'. Future dates are not classified; an ongoing shift may still be incomplete.',
            'Totals: regular time '.HrisFormat::duration($summary->regularMinutes()).'; late '.HrisFormat::duration($summary->lateMinutes).'; undertime '.HrisFormat::duration($summary->undertimeMinutes).'; absences '.$absences.'.',
            'OT approved shows the stored decision. Current payable OT is '.HrisFormat::duration($payable).', capped by computed time and the overtime formula. Pending overtime pays nothing.',
        ];
        if ($unscheduled > 0) {
            $notes[] = $unscheduled.' day(s) have no assigned schedule. Their figures use the default schedule and need review.';
        }

        $document = new ReportDocument(EmployeeIdentity::option($employee, EmployeeName::display($employee)), ['Date', 'First In', 'Break Out', 'Break In', 'Final Out', 'Regular Time', 'Late', 'Undertime', 'OT Computed', 'OT Approved', 'OT Status', 'Status', 'Remarks'], $rows, $notes, [], [
            ['label' => 'Regular Time', 'value' => HrisFormat::duration($summary->regularMinutes())],
            ['label' => 'Late', 'value' => HrisFormat::duration($summary->lateMinutes)],
            ['label' => 'Undertime', 'value' => HrisFormat::duration($summary->undertimeMinutes)],
            ['label' => 'Computed Overtime', 'value' => HrisFormat::duration($summary->overtimeMinutes)],
            ['label' => 'Payable Overtime', 'value' => HrisFormat::duration($payable)],
            ['label' => 'Absence Days', 'value' => (string) $absences],
        ], ['Totals', '', '', '', '', HrisFormat::duration($summary->regularMinutes()), HrisFormat::duration($summary->lateMinutes), HrisFormat::duration($summary->undertimeMinutes), HrisFormat::duration($summary->overtimeMinutes), HrisFormat::duration($approved), '', '', ''], range(5, 9));

        return ['document' => $document, 'metrics' => $metrics];
    }

    /** @return array<string, string> */
    private function rankingFilters(ReportCriteria $criteria): array
    {
        if (array_diff(array_keys($criteria->filters), ['issue', 'minimum', 'limit', 'search']) !== []) {
            throw ValidationException::withMessages(['filters' => 'Choose supported attendance report filters.']);
        }
        $validated = Validator::make([
            'sort' => $criteria->sort !== '' ? $criteria->sort : 'late_minutes',
            'direction' => $criteria->direction,
            'filters' => [
                'issue' => $criteria->filters['issue'] ?? 'all',
                'minimum' => $criteria->filters['minimum'] ?? '0',
                'limit' => $criteria->filters['limit'] ?? 'all',
                'search' => $criteria->filters['search'] ?? '',
            ],
        ], [
            'sort' => ['required', Rule::in(array_keys(self::rankingSorts()))],
            'direction' => ['required', Rule::in(['asc', 'desc'])],
            'filters.issue' => ['required', Rule::in(['all', 'late', 'undertime', 'absent', 'incomplete'])],
            'filters.minimum' => ['required', 'numeric', 'min:0', 'max:1000000'],
            'filters.limit' => ['required', Rule::in(['all', '10', '25', '50'])],
            'filters.search' => ['nullable', 'string', 'max:120'],
        ])->validate();
        $filters = [...$validated['filters'], 'sort' => $validated['sort'], 'direction' => $validated['direction']];
        if ($filters['sort'] === 'employee' && (float) $filters['minimum'] > 0) {
            throw ValidationException::withMessages(['filters.minimum' => 'Choose a minute or day metric before setting a minimum.']);
        }

        return $filters;
    }

    /**
     * @param  array<string, string>  $filters
     * @param  list<array{employee: DirectoryEmployee, metrics: array<string, int|float>}>  $ranking
     */
    private function attendanceRanking(array $filters, array $ranking, int $dayCount): ReportDocument
    {
        $selected = count($ranking);
        $search = mb_strtolower(trim($filters['search'] ?? ''));
        $ranking = array_values(array_filter($ranking, static function (array $row) use ($filters, $search): bool {
            $employee = $row['employee'];
            $metrics = $row['metrics'];
            if ($search !== '' && ! str_contains(mb_strtolower(implode(' ', [
                $employee->employee_code ?? '', $employee->employee_id_no ?? '',
                EmployeeName::display($employee), $employee->position ?? '',
            ])), $search)) {
                return false;
            }
            $issueMetric = match ($filters['issue']) {
                'late' => 'late_days', 'undertime' => 'undertime_days',
                'absent' => 'absences', 'incomplete' => 'incomplete_days', default => null,
            };
            if ($issueMetric !== null && $metrics[$issueMetric] <= 0) {
                return false;
            }

            return $filters['sort'] === 'employee' || $metrics[$filters['sort']] >= (float) $filters['minimum'];
        }));
        usort($ranking, static function (array $left, array $right) use ($filters): int {
            $leftName = EmployeeName::display($left['employee']);
            $rightName = EmployeeName::display($right['employee']);
            $comparison = $filters['sort'] === 'employee'
                ? strnatcasecmp($leftName, $rightName)
                : $left['metrics'][$filters['sort']] <=> $right['metrics'][$filters['sort']];
            if ($comparison !== 0) {
                return $filters['direction'] === 'desc' ? -$comparison : $comparison;
            }

            // Equal metrics always read in the same name / code / ID order.
            return strnatcasecmp($leftName, $rightName)
                ?: strnatcasecmp($left['employee']->employee_code ?? '', $right['employee']->employee_code ?? '')
                ?: strcmp($left['employee']->id, $right['employee']->id);
        });
        $matched = count($ranking);
        if ($filters['limit'] !== 'all') {
            $ranking = array_slice($ranking, 0, (int) $filters['limit']);
        }

        $totals = array_fill_keys(['late_minutes', 'late_days', 'undertime_minutes', 'undertime_days', 'absences', 'incomplete_days', 'regular_minutes', 'overtime_minutes', 'approved_minutes', 'payable_minutes', 'unscheduled_days', 'provisional_days'], 0.0);
        $rows = [];
        foreach ($ranking as $index => $row) {
            $employee = $row['employee'];
            $metrics = $row['metrics'];
            foreach ($totals as $key => $value) {
                $totals[$key] += $metrics[$key];
            }
            $review = [];
            if ($metrics['unscheduled_days'] > 0) {
                $review[] = $metrics['unscheduled_days'].' no schedule';
            }
            if ($metrics['provisional_days'] > 0) {
                $review[] = $metrics['provisional_days'].' ongoing';
            }
            $rows[] = [
                (string) ($index + 1), EmployeeIdentity::number($employee), EmployeeName::display($employee), $employee->position ?? '',
                (string) $metrics['late_days'], HrisFormat::duration($metrics['late_minutes']),
                (string) $metrics['undertime_days'], HrisFormat::duration($metrics['undertime_minutes']),
                (string) $metrics['absences'], (string) $metrics['incomplete_days'],
                HrisFormat::duration($metrics['regular_minutes']), HrisFormat::duration($metrics['overtime_minutes']),
                HrisFormat::duration($metrics['approved_minutes']), HrisFormat::duration($metrics['payable_minutes']),
                implode('; ', $review),
            ];
        }

        return new ReportDocument('Attendance Comparison', [
            'Rank', 'Employee ID', 'Employee', 'Position', 'Late Days', 'Late', 'Undertime Days', 'Undertime',
            'Absences', 'Incomplete Days', 'Regular Time', 'OT Computed', 'OT Approved', 'OT Payable', 'Review',
        ], $rows, [
            'Compared '.$selected.' selected employee(s) over '.$dayCount.' calendar day(s). '.$matched.' match the filters; '.count($rows).' are shown. Totals cover the employees shown.',
            'Ordered by '.self::rankingSorts()[$filters['sort']].' ('.$filters['direction'].'). Minimum '.$filters['minimum'].' applies to that metric in minutes or days. Issue: '.$filters['issue'].($search !== '' ? '. Search: '.$filters['search'] : '').'.',
            'Late days and undertime days count dates with positive computed minutes. Absences use the same closed-day rule as the DTR, including incomplete scheduled days only after they close.',
            'Future dates are excluded. Ongoing days are provisional; their incomplete punches are shown but are not yet absences. No-schedule days use default hours and need review.',
            'OT computed is attendance time; OT approved is the stored decision. OT payable is capped by current computed time and the overtime formula; pending overtime pays nothing.',
        ], [], [
            ['label' => 'Employees Selected', 'value' => (string) $selected],
            ['label' => 'Employees Matching', 'value' => (string) $matched],
            ['label' => 'Employees Shown', 'value' => (string) count($rows)],
            ['label' => 'Late — Shown', 'value' => HrisFormat::duration($totals['late_minutes'])],
            ['label' => 'Undertime — Shown', 'value' => HrisFormat::duration($totals['undertime_minutes'])],
            ['label' => 'Absences — Shown', 'value' => (string) $totals['absences']],
        ], [
            'Totals Shown', '', '', '', (string) $totals['late_days'], HrisFormat::duration($totals['late_minutes']),
            (string) $totals['undertime_days'], HrisFormat::duration($totals['undertime_minutes']),
            (string) $totals['absences'], (string) $totals['incomplete_days'],
            HrisFormat::duration($totals['regular_minutes']), HrisFormat::duration($totals['overtime_minutes']),
            HrisFormat::duration($totals['approved_minutes']), HrisFormat::duration($totals['payable_minutes']), '',
        ], [0, ...range(4, 13)], [
            '', '', 'employee', '', 'late_days', 'late_minutes', 'undertime_days', 'undertime_minutes',
            'absences', '', 'regular_minutes', 'overtime_minutes', '', '', '',
        ]);
    }

    private function punch(?string $instant, string $date): string
    {
        if ($instant === null || $instant === '') {
            return '—';
        }
        $at = LocalClock::instant($instant);
        $time = $at->setTimezone(LocalClock::zone())->format('g:i A');

        return $time.(LocalClock::dayKey($at) > $date ? ' next day' : '');
    }
}
