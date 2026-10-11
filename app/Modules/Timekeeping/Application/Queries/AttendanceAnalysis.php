<?php

declare(strict_types=1);

namespace App\Modules\Timekeeping\Application\Queries;

use App\Domain\Attendance\CalendarDays;
use App\Domain\Attendance\DayRecord;
use App\Domain\Attendance\HolidaySetting;
use App\Domain\Attendance\Shift as DomainShift;
use App\Modules\Timekeeping\Contracts\Views\OvertimeDayView;
use App\Modules\Timekeeping\Contracts\Views\ScheduleDayView;
use App\Modules\Timekeeping\Domain\Attendance\DayClose;
use App\Modules\Timekeeping\Domain\Roster\ScheduleBasis;
use App\Modules\Workforce\Contracts\DirectoryEmployee;
use App\Platform\Reports\ReportCriteria;
use App\Platform\Reports\ReportDocument;
use App\Support\EmployeeIdentity;
use App\Support\EmployeeName;
use App\Support\HrisFormat;
use App\Support\ManilaClock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Management analysis keeps comparable attendance opportunities separate from canonical DTR totals.
 *
 * @phpstan-type DayFact array{date: string, excluded: string, values: array<string, float>, issues: list<string>, leave: string}
 * @phpstan-type Context array{department: string, unit: string, position: string, worksite: string, employment: string, active: bool, date_hired: string, date_resigned: ?string}
 */
final class AttendanceAnalysis
{
    private const int EXCEPTION_LIMIT = 200;

    /** @return array<string, string> */
    public static function groups(): array
    {
        return ['department' => 'Department', 'unit' => 'Unit', 'position' => 'Position', 'worksite' => 'Worksite', 'employment' => 'Employment status'];
    }

    /** @return array<string, string> */
    public static function filters(ReportCriteria $criteria): array
    {
        if (array_diff(array_keys($criteria->filters), ['groupBy', 'compare', 'threshold']) !== []) {
            throw ValidationException::withMessages(['filters' => 'Choose supported attendance analysis filters.']);
        }
        $values = Validator::make(['filters' => [
            'groupBy' => $criteria->filters['groupBy'] ?? 'department',
            'compare' => $criteria->filters['compare'] ?? 'none',
            'threshold' => $criteria->filters['threshold'] ?? '3',
        ]], [
            'filters.groupBy' => ['required', Rule::in(array_keys(self::groups()))],
            'filters.compare' => ['required', Rule::in(['none', 'previous'])],
            'filters.threshold' => ['required', 'integer', 'min:1', 'max:31'],
        ])->validate();

        return $values['filters'];
    }

    /**
     * @param  list<DayRecord>  $days
     * @param  array<string, ScheduleDayView>  $scheduleDays
     * @param  array<string, OvertimeDayView>  $overtime
     * @param  Context  $context
     * @param  list<HolidaySetting>  $holidays
     * @return list<DayFact>
     */
    public static function days(string $from, string $to, array $days, ?DomainShift $schedule, array $scheduleDays, array $overtime, array $context, array $holidays, float $openPunchHours, CarbonImmutable $now): array
    {
        $byDate = [];
        foreach ($days as $day) {
            $byDate[$day->date] = $day;
        }
        $facts = [];
        $today = ManilaClock::dayKey($now);
        foreach (CalendarDays::each($from, $to) as $date) {
            $day = $byDate[$date] ?? null;
            $scheduleDay = $scheduleDays[$date] ?? null;
            $shift = ($schedule ?? DomainShift::default())->forDate($date);
            $employed = $context['date_hired'] !== '' && $date >= $context['date_hired']
                && ($context['date_resigned'] === null || $date <= $context['date_resigned']);
            $excluded = match (true) {
                $date > $today => 'Future date',
                $context['date_hired'] === '' => 'Employment dates unavailable',
                ! $employed => 'Outside recorded employment',
                $scheduleDay === null || $day === null => 'Schedule evidence unavailable',
                $scheduleDay->basis === ScheduleBasis::NONE => 'No assigned schedule',
                $scheduleDay->basis === ScheduleBasis::ASSUMED => 'Assumed schedule history',
                $scheduleDay->basis === ScheduleBasis::MAY_DIFFER => 'Schedule history may differ',
                ! $scheduleDay->works => 'Rest day',
                HolidaySetting::on($date, $holidays) !== null => 'Holiday',
                ! DayClose::isOver($date, $shift, $openPunchHours, $now) => 'Ongoing day',
                $day->status === DayRecord::OFFICIAL_BUSINESS => 'Approved official business',
                $day->excuseType !== null && $day->excuseType !== '' => 'Approved leave',
                default => '',
            };
            $values = self::zero();
            $issues = [];
            $leave = '';
            if ($employed && $day !== null) {
                $decision = $overtime[$date] ?? null;
                $values['computed_ot'] = $day->overtimeMinutes;
                $values['approved_ot'] = (float) ($decision->approved_minutes ?? 0);
                $values['payable_ot'] = (float) ($decision->payable_minutes ?? 0);
                $values['paid_leave'] = $day->excusePaid === true ? $day->excuseDays : 0.0;
                $values['unpaid_leave'] = $day->excusePaid === false ? $day->excuseDays : 0.0;
                $values['ob'] = $day->status === DayRecord::OFFICIAL_BUSINESS ? 1.0 : 0.0;
                if ($values['ob'] > 0) {
                    $leave = 'Official business';
                } elseif ($day->excuseDays > 0 && $day->excuseType !== null) {
                    $leave = ucwords(strtolower(str_replace('_', ' ', $day->excuseType))).($day->excusePaid === false ? ' — unpaid' : ' — paid');
                }
                if ($decision !== null && in_array($decision->status, ['PENDING', 'OUTDATED'], true)) {
                    $issues[] = 'Overtime '.strtolower($decision->status);
                }
            }
            if ($excluded === '') {
                $complete = in_array($day->status, [DayRecord::PRESENT, DayRecord::LATE], true);
                $values['opportunities'] = 1.0;
                $values['completed'] = $complete ? 1.0 : 0.0;
                $values['fixed_completed'] = $complete && ! $shift->isFlexible() ? 1.0 : 0.0;
                $values['late_days'] = $complete && ! $shift->isFlexible() && $day->lateMinutes > 0 ? 1.0 : 0.0;
                $values['undertime_days'] = $complete && $day->undertimeMinutes > 0 ? 1.0 : 0.0;
                $values['late_minutes'] = $complete && ! $shift->isFlexible() ? $day->lateMinutes : 0.0;
                $values['undertime_minutes'] = $complete ? $day->undertimeMinutes : 0.0;
                $values['regular_minutes'] = $complete ? $day->regularMinutes() : 0.0;
                $values['absences'] = $day->isAbsenceDay($schedule) ? 1.0 : 0.0;
                $values['incomplete'] = $day->status === DayRecord::INCOMPLETE ? 1.0 : 0.0;
                if ($values['incomplete'] > 0) {
                    $issues[] = 'Incomplete punches — closed absence day';
                } elseif ($values['absences'] > 0) {
                    $issues[] = 'No attendance recorded';
                }
                if ($values['late_days'] > 0) {
                    $issues[] = 'Late';
                }
                if ($values['undertime_days'] > 0) {
                    $issues[] = 'Undertime';
                }
                $issues = array_values(array_unique([...$issues, ...$day->flags]));
            } else {
                $values['excluded'] = 1.0;
                if (in_array($excluded, ['No assigned schedule', 'Assumed schedule history', 'Schedule history may differ', 'Schedule evidence unavailable', 'Employment dates unavailable'], true)) {
                    $issues[] = $excluded;
                }
            }
            $facts[] = ['date' => $date, 'excluded' => $excluded, 'values' => $values, 'issues' => $issues, 'leave' => $leave];
        }

        return $facts;
    }

    /** @return array<string, float> */
    private static function zero(): array
    {
        return array_fill_keys(['opportunities', 'completed', 'fixed_completed', 'late_days', 'undertime_days', 'absences', 'incomplete', 'late_minutes', 'undertime_minutes', 'regular_minutes', 'computed_ot', 'approved_ot', 'payable_ot', 'paid_leave', 'unpaid_leave', 'ob', 'excluded'], 0.0);
    }

    /**
     * @param  array<string, string>  $filters
     * @param  array<string, DirectoryEmployee>  $employees
     * @param  array<string, Context>  $contexts
     * @param  array<string, list<DayFact>>  $current
     * @param  array<string, list<DayFact>>  $previous
     */
    public static function document(ReportCriteria $criteria, array $filters, array $employees, array $contexts, array $current, array $previous, string $previousFrom, string $previousTo): ReportDocument
    {
        $groups = [];
        $previousGroups = [];
        $daily = [];
        $byEmployee = [];
        $excluded = [];
        $leaveGroups = [];
        $exceptions = [];
        $repeat = [];
        $total = self::zero();
        foreach ($employees as $id => $employee) {
            $group = self::group($contexts[$id], $filters['groupBy']);
            $groups[$group] ??= self::zero();
            $previousGroups[$group] ??= self::zero();
            $sum = self::zero();
            $lateDates = [];
            foreach ($current[$id] as $fact) {
                self::add($sum, $fact['values']);
                $daily[$fact['date']] ??= self::zero();
                self::add($daily[$fact['date']], $fact['values']);
                if ($fact['excluded'] !== '') {
                    $reason = $fact['excluded'];
                    $excluded[$reason]['days'] = ($excluded[$reason]['days'] ?? 0) + 1;
                    $excluded[$reason]['employees'][$id] = true;
                }
                if ($fact['leave'] !== '') {
                    $leaveGroups[$fact['leave']]['days'] = ($leaveGroups[$fact['leave']]['days'] ?? 0.0)
                        + $fact['values']['paid_leave'] + $fact['values']['unpaid_leave'] + $fact['values']['ob'];
                    $leaveGroups[$fact['leave']]['employees'][$id] = true;
                }
                if ($fact['values']['late_days'] > 0) {
                    $lateDates[] = $fact['date'];
                }
                if ($fact['issues'] !== []) {
                    $exceptions[] = [$fact['date'], EmployeeIdentity::number($employee), EmployeeName::display($employee), implode('; ', $fact['issues']),
                        HrisFormat::duration($fact['values']['late_minutes']), HrisFormat::duration($fact['values']['undertime_minutes']),
                        $fact['excluded'] === '' ? 'Qualified attendance day' : 'Excluded from rates'];
                }
            }
            self::add($total, $sum);
            self::add($groups[$group], $sum);
            foreach ($previous[$id] ?? [] as $fact) {
                self::add($previousGroups[$group], $fact['values']);
            }
            $byEmployee[] = [EmployeeIdentity::number($employee), EmployeeName::display($employee), $group, ...self::metricCells($sum),
                HrisFormat::duration($sum['computed_ot']), HrisFormat::duration($sum['approved_ot']), HrisFormat::duration($sum['payable_ot'])];
            if (count($lateDates) >= (int) $filters['threshold']) {
                $repeat[] = [EmployeeIdentity::number($employee), EmployeeName::display($employee), (string) count($lateDates), self::count($sum['fixed_completed']), HrisFormat::duration($sum['late_minutes']), implode(', ', $lateDates)];
            }
        }
        ksort($groups, SORT_NATURAL | SORT_FLAG_CASE);
        ksort($daily);
        ksort($excluded);
        ksort($leaveGroups);
        usort($exceptions, static fn (array $a, array $b): int => ($a[0] <=> $b[0]) ?: strnatcasecmp($a[2], $b[2]) ?: ($a[1] <=> $b[1]));
        $columns = [self::groups()[$filters['groupBy']], ...self::metricColumns()];
        $rows = [];
        foreach ($groups as $label => $sum) {
            $rows[] = [$label, ...self::metricCells($sum)];
        }
        $dailyRows = [];
        foreach ($daily as $date => $sum) {
            $dailyRows[] = [$date, ...self::metricCells($sum), self::count($sum['excluded'])];
        }
        $excludedRows = [];
        foreach ($excluded as $reason => $coverage) {
            $excludedRows[] = [$reason, (string) $coverage['days'], (string) count($coverage['employees'])];
        }
        $leaveRows = [];
        foreach ($leaveGroups as $label => $coverage) {
            $leaveRows[] = [$label, self::count($coverage['days']), (string) count($coverage['employees'])];
        }
        $sections = [
            self::section('Daily attendance trend', ['Date', ...self::metricColumns(), 'Excluded employee-days'], $dailyRows,
                ['Each date belongs to the shift start. Future and ongoing days have no qualified denominator, not a zero attendance rate.'], [1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13]),
            self::section('Employee attendance detail', ['Employee ID', 'Employee', self::groups()[$filters['groupBy']], ...self::metricColumns(), 'OT computed', 'OT approved', 'OT payable'], $byEmployee,
                ['Attendance rates use qualified opportunities. Overtime columns retain current DTR facts within recorded employment, including excluded schedule or ongoing days; review those dates before use. Approved means the stored decision, payable means the current capped amount.'], [3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15, 16, 17]),
            self::section('Approved leave and official business', ['Recorded type', 'DTR day equivalents', 'Employees'], $leaveRows,
                ['Only approved facts recognized by the current DTR are counted; pending, rejected and withdrawn requests are not leave usage. Half-day leave is 0.5. Official business is separate credited work, not absence. These are not leave-credit balances.'], [1, 2]),
            self::section('Coverage excluded from attendance rates', ['Reason', 'Employee-days', 'Employees'], $excludedRows,
                ['Each excluded employee-day has one primary reason in order: future, employment coverage, schedule evidence, rest day, holiday, ongoing day, official business, approved leave. Underlying reasons can overlap. All approved leave dates, including half days, are excluded from punch-based rates.'], [1, 2]),
            self::section('Repeated lateness — at least '.$filters['threshold'].' qualifying days', ['Employee ID', 'Employee', 'Late days', 'Completed fixed-shift days', 'Late time', 'Contributing dates'], $repeat,
                ['This is a configurable occurrence threshold, not a disciplinary or legal classification. Only completed, recorded fixed-shift attendance days contribute.'], [2, 3, 4]),
            self::section('Attendance exceptions to review', ['Date', 'Employee ID', 'Employee', 'Evidence to review', 'Qualified late', 'Qualified undertime', 'Rate treatment'], array_slice($exceptions, 0, self::EXCEPTION_LIMIT),
                ['Showing '.min(count($exceptions), self::EXCEPTION_LIMIT).' of '.count($exceptions).' employee-days with exceptions, in date order. All aggregates above remain complete.'.(count($exceptions) > self::EXCEPTION_LIMIT ? ' Narrow the period or selected employees to inspect the remaining dates.' : '')], [4, 5]),
        ];
        if ($filters['compare'] === 'previous') {
            $comparison = [];
            foreach ($groups as $group => $sum) {
                $prior = $previousGroups[$group];
                $comparison[] = [$group, self::count($sum['opportunities']), self::count($prior['opportunities']),
                    self::rate($sum['completed'], $sum['opportunities']), self::rate($prior['completed'], $prior['opportunities']), self::delta($sum['completed'], $sum['opportunities'], $prior['completed'], $prior['opportunities']),
                    self::rate($sum['late_days'], $sum['fixed_completed']), self::rate($prior['late_days'], $prior['fixed_completed']), self::delta($sum['late_days'], $sum['fixed_completed'], $prior['late_days'], $prior['fixed_completed']),
                    self::rate($sum['absences'], $sum['opportunities']), self::rate($prior['absences'], $prior['opportunities']), self::delta($sum['absences'], $sum['opportunities'], $prior['absences'], $prior['opportunities'])];
            }
            $sections[] = self::section('Previous-period comparison', [self::groups()[$filters['groupBy']], 'Current opportunities', 'Previous opportunities', 'Current completion', 'Previous completion', 'Change (pp)', 'Current lateness', 'Previous lateness', 'Change (pp)', 'Current absence', 'Previous absence', 'Change (pp)'], $comparison,
                ['Current: '.$criteria->from.' through '.$criteria->to.'. Previous: '.$previousFrom.' through '.$previousTo.'. Same selected employees and equal calendar lengths; current grouping labels apply to both periods.',
                    'Rates use each period’s actual qualified denominator. Ongoing or excluded dates can make coverage unequal. Percentage-point changes are N/A when either denominator is zero; they do not establish a cause.'], [1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11]);
        }

        $dailyPoints = [];
        foreach ($daily as $date => $sum) {
            $dailyPoints[] = ['label' => $date, 'value' => $sum['late_minutes']];
        }
        $absencePoints = [];
        foreach ($groups as $label => $sum) {
            $absencePoints[] = ['label' => $label, 'value' => $sum['absences']];
        }
        usort($absencePoints, static fn (array $a, array $b): int => ($b['value'] <=> $a['value']) ?: strnatcasecmp($a['label'], $b['label']));

        return new ReportDocument('Attendance Management Analysis', $columns, $rows, [
            'All '.count($employees).' selected employees are included before grouping. Group names describe current placement, not historical organizational membership.',
            'Qualified opportunities are recorded, closed, nonholiday scheduled days within recorded hire and separation dates, inclusive. Rest days, uncertain schedules, ongoing/future days, approved leave and official business are excluded.',
            'Completion = completed attendance / qualified opportunities. Lateness = late days / completed fixed-shift days. Undertime = undertime days / completed attendance days, including flexible shifts. Absence = closed canonical absence days / qualified opportunities. Each rate shows its numerator and denominator; zero denominator is N/A.',
            'A closed incomplete scheduled day counts as an absence; incomplete days and absences overlap. Rates are weighted from combined numerators and denominators. Regular, late and undertime figures here use qualified completed days; the comparison and individual sheets retain full DTR totals.',
            'Employment coverage uses the employee record’s saved hire and separation dates. Half-day leave has no reconstructed partial attendance obligation. The repeated-lateness threshold is descriptive and does not assert government compliance or disciplinary status.',
        ], $sections, [
            ['label' => 'Selected employees', 'value' => (string) count($employees)],
            ['label' => 'Qualified employee-days', 'value' => self::count($total['opportunities'])],
            ['label' => 'Completion rate', 'value' => self::rate($total['completed'], $total['opportunities'])],
            ['label' => 'Late occurrence rate', 'value' => self::rate($total['late_days'], $total['fixed_completed'])],
            ['label' => 'Absence rate', 'value' => self::rate($total['absences'], $total['opportunities'])],
            ['label' => 'Repeated lateness', 'value' => (string) count($repeat)],
        ], ['All selected', ...self::metricCells($total)], numericColumns: [1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12], charts: [
            ['title' => 'Daily qualified late minutes', 'unit' => 'minutes', 'kind' => 'line', 'points' => $dailyPoints,
                'note' => 'Completed fixed-shift attendance on qualified days only. Zero qualified minutes on a date without qualifying attendance does not establish punctuality; inspect the daily coverage and denominators.'],
            ['title' => 'Absence days by '.strtolower(self::groups()[$filters['groupBy']]), 'unit' => 'days', 'points' => array_slice($absencePoints, 0, 10),
                'note' => 'Qualified closed absence days, including closed incomplete days. Showing '.min(count($absencePoints), 10).' of '.count($absencePoints).' groups, highest count first; group rates and full coverage are in the table.'],
        ]);
    }

    /** @param Context $context */
    private static function group(array $context, string $key): string
    {
        $label = trim((string) $context[$key]);
        if ($key === 'unit') {
            return ($context['department'] !== '' ? $context['department'].' / ' : '').($label !== '' ? $label : 'No unit recorded');
        }

        return $label !== '' ? $label : 'Not recorded';
    }

    /** @param array<string, float> $target
     * @param  array<string, float>  $values
     */
    private static function add(array &$target, array $values): void
    {
        foreach ($values as $key => $value) {
            $target[$key] += $value;
        }
    }

    /** @return list<string> */
    private static function metricColumns(): array
    {
        return ['Qualified days', 'Completed days', 'Completion rate', 'Late days', 'Late rate', 'Late time', 'Undertime days', 'Undertime rate', 'Undertime', 'Absence days', 'Absence rate', 'Incomplete days'];
    }

    /** @param array<string, float> $sum
     * @return list<string>
     */
    private static function metricCells(array $sum): array
    {
        return [self::count($sum['opportunities']), self::count($sum['completed']), self::rate($sum['completed'], $sum['opportunities']),
            self::count($sum['late_days']), self::rate($sum['late_days'], $sum['fixed_completed']), HrisFormat::duration($sum['late_minutes']),
            self::count($sum['undertime_days']), self::rate($sum['undertime_days'], $sum['completed']), HrisFormat::duration($sum['undertime_minutes']),
            self::count($sum['absences']), self::rate($sum['absences'], $sum['opportunities']), self::count($sum['incomplete'])];
    }

    private static function count(float $value): string
    {
        return $value === floor($value) ? (string) (int) $value : number_format($value, 1, '.', '');
    }

    private static function rate(float $numerator, float $denominator): string
    {
        return $denominator <= 0 ? 'N/A (0 days)' : number_format(100 * $numerator / $denominator, 1, '.', '').'% ('.self::count($numerator).'/'.self::count($denominator).')';
    }

    private static function delta(float $numerator, float $denominator, float $previousNumerator, float $previousDenominator): string
    {
        if ($denominator <= 0 || $previousDenominator <= 0) {
            return 'N/A';
        }
        $difference = 100 * ($numerator / $denominator - $previousNumerator / $previousDenominator);

        return ($difference > 0 ? '+' : '').number_format($difference, 1, '.', '').' pp';
    }

    /**
     * @param  list<string>  $columns
     * @param  list<list<string>>  $rows
     * @param  list<string>  $notes
     * @param  list<int>  $numericColumns
     * @return array{title: string, kind: 'table', columns: list<string>, rows: list<list<string>>, notes: list<string>, numericColumns: list<int>}
     */
    private static function section(string $title, array $columns, array $rows, array $notes, array $numericColumns): array
    {
        return ['title' => $title, 'kind' => 'table', 'columns' => $columns, 'rows' => $rows, 'notes' => $notes, 'numericColumns' => $numericColumns];
    }
}
