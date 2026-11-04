<?php

declare(strict_types=1);

namespace App\Modules\Timekeeping\Application;

use App\Domain\Attendance\CalendarDays;
use App\Domain\Attendance\HolidaySetting;
use App\Domain\Enums\AuditEvent;
use App\Domain\Enums\ModuleCode;
use App\Domain\Payroll\PayrollPeriod;
use App\Models\EmployeeLeavePolicy;
use App\Models\LeaveCredit;
use App\Models\LeaveEarningPosting;
use App\Modules\Organization\Contracts\HolidayCalendar;
use App\Modules\Timekeeping\Application\Queries\LeaveTypeCreditRecords;
use App\Modules\Timekeeping\Domain\Credits\LeaveEarningRule;
use App\Modules\Timekeeping\Domain\Credits\LeavePolicy;
use App\Modules\Timekeeping\Domain\Credits\LeaveQuantity;
use App\Modules\Timekeeping\Domain\Credits\LeaveTypeCredits;
use App\Modules\Timekeeping\Infrastructure\AttendanceInputQuery;
use App\Modules\Workforce\Contracts\EmployeeLeaveFacts;
use App\Support\Access;
use App\Support\AuditService;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * THE MONTH-END EARNING. For every employee with an approved leave policy,
 * every month from the first approved version's effective month to the last
 * month whose posting day has passed is posted once: each leave type that
 * earns under the policy in force on that month's posting date gets an
 * ACCRUAL ledger entry for what LeaveEarningRule gives it from the month's
 * facts (LeaveMonthFacts), and a `leave_earning_postings` row records the
 * facts, the policy and the entry — the row exists even when the month earns
 * nothing, so the month is never looked at twice. Once a year is over and
 * its December is posted (or earns nothing), the year is CLOSED: every leave
 * type's whole closing balance — a debt below zero included — becomes a
 * CARRY_FORWARD entry in the new year, recorded under the period `YYYY-YE`.
 * CREDITS NEVER LAPSE. Years before the policy that still hold hand-entered
 * rows are closed first, oldest first, so a balance chains into the present
 * and a payroll always charges the live year (LeaveCharges::liveYear).
 *
 * Idempotent by the unique key on (employee, period, type): the schedule
 * runs it every half hour and a run that finds everything posted writes
 * nothing. Each employee-month is one transaction under the leave-credits
 * lock, the lock every credit writer takes first. The schedule's runs post
 * as "System (scheduled)"; "Post now" on Leave Management posts as the
 * leave manager who pressed it. Hired and left dates come from Workforce's
 * EmployeeLeaveFacts; an employee who left earns through the month they
 * left, and an inactive record with no Date Resigned is skipped and named
 * in the run's `skipped` list. A type earns only while its current credit
 * rules say it earns (LeaveTypeCreditRecords), whatever the policy says.
 */
final class PostLeaveEarnings
{
    public const string SYSTEM_ACTOR = 'System (scheduled)';

    /**
     * Posts every month that is due as of `$today` (Manila, 'Y-m-d').
     *
     * @param  list<string>|null  $employeeIds  null for everyone with a policy
     * @return array{posted: int, employees: int, skipped: list<string>}
     */
    public function run(string $today, ?Access $actor = null, ?array $employeeIds = null): array
    {
        // The schedule runs with no actor; a person running it must manage
        // leave for everyone, as every other credit writer requires.
        if ($actor !== null && ! $actor->mayReadAllData(ModuleCode::LEAVES)) {
            throw new RuntimeException('Your role cannot manage organization-wide data.');
        }
        if (! LeavePolicyStore::ready() || ! LeaveTypeCreditRecords::ready()) {
            return ['posted' => 0, 'employees' => 0, 'skipped' => []];
        }
        $ids = $employeeIds ?? LeavePolicyStore::employeesWithPolicies();
        if ($employeeIds !== null) {
            $ids = array_values(array_intersect($ids, LeavePolicyStore::employeesWithPolicies()));
        }
        if ($ids === []) {
            return ['posted' => 0, 'employees' => 0, 'skipped' => []];
        }
        $facts = app(EmployeeLeaveFacts::class)->forPosting($ids);
        $holidays = app(HolidayCalendar::class)->all();
        $earning = [];
        foreach (LeaveTypeCreditRecords::rules() as $token => $entry) {
            if ($entry['rules']->earnsCredits) {
                $earning[] = $token;
            }
        }
        $posted = 0;
        $employees = 0;
        $skipped = [];
        foreach ($ids as $employeeId) {
            $employee = $facts[$employeeId] ?? null;
            if ($employee === null) {
                $skipped[] = "{$employeeId}: no employee record";

                continue;
            }
            // An inactive record with no Date Resigned has no last month to
            // earn through: nothing posts until HR sets the date, and the
            // run says so.
            if (! $employee['is_active'] && $employee['date_resigned'] === null) {
                $skipped[] = "{$employeeId}: inactive without a Date Resigned";

                continue;
            }
            $first = LeavePolicyStore::firstEffective($employeeId);
            if ($first === null) {
                continue;
            }
            $start = max(substr($first, 0, 7), substr($employee['date_hired'], 0, 7));
            $count = $this->postEmployee($employeeId, $employee, $start, $today, $holidays, $actor, $earning);
            if ($count > 0) {
                $employees++;
                $posted += $count;
            }
        }

        return ['posted' => $posted, 'employees' => $employees, 'skipped' => $skipped];
    }

    /**
     * The months approving a DRAFT version would post on the next run, as of
     * `$today`: each month from the version's effective month (or the hire
     * month) whose posting day has passed, that no later approved version
     * takes over, that this version earns in, and that holds no posting yet
     * — what the approve dialog shows before anyone commits to it. Empty for
     * an employee the job skips.
     *
     * @return list<string> 'YYYY-MM', ascending
     */
    public function previewFor(Access $actor, string $versionId, string $today): array
    {
        if (! $actor->mayReadAllData(ModuleCode::LEAVES) || ! LeavePolicyStore::ready()) {
            return [];
        }
        $version = EmployeeLeavePolicy::query()->whereKey($versionId)->first();

        return $version === null ? [] : $this->preview($version, $today);
    }

    /**
     * @see previewFor
     *
     * @return list<string>
     */
    public function preview(EmployeeLeavePolicy $version, string $today): array
    {
        if (! LeavePolicyStore::ready() || ! LeaveTypeCreditRecords::ready()) {
            return [];
        }
        $employee = app(EmployeeLeaveFacts::class)->forPosting([$version->employee_id])[$version->employee_id] ?? null;
        if ($employee === null || (! $employee['is_active'] && $employee['date_resigned'] === null)) {
            return [];
        }
        $policy = LeavePolicyStore::policy($version);
        $earning = [];
        foreach (LeaveTypeCreditRecords::rules() as $token => $entry) {
            if ($entry['rules']->earnsCredits) {
                $earning[] = $token;
            }
        }
        $tokens = array_values(array_intersect(array_keys($policy->earning()), $earning));
        if ($tokens === []) {
            return [];
        }
        $from = $version->effective_from->format('Y-m-d');
        $later = EmployeeLeavePolicy::query()->where('employee_id', $version->employee_id)
            ->where('status', EmployeeLeavePolicy::APPROVED)->where('effective_from', '>', $from)
            ->min('effective_from');
        $later = $later === null ? null : substr((string) $later, 0, 10);
        $start = max(substr($from, 0, 7), substr($employee['date_hired'], 0, 7));
        $lastMonth = $employee['date_resigned'] !== null ? min(substr($employee['date_resigned'], 0, 7), substr($today, 0, 7)) : substr($today, 0, 7);
        $months = [];
        for ($month = $start; $month <= $lastMonth; $month = self::nextMonth($month)) {
            $range = PayrollPeriod::calendarMonthRange($month.'-01');
            if ($later !== null && $later <= $range['to']) {
                break;
            }
            $postingDate = $policy->postingDay() === LeavePolicy::MONTH_END
                ? $range['to']
                : self::nextMonth($month).'-'.str_pad((string) $policy->postingDay(), 2, '0', STR_PAD_LEFT);
            if ($postingDate >= $today) {
                break;
            }
            $posted = LeaveEarningPosting::query()->where('employee_id', $version->employee_id)->where('period', $month)->whereIn('request_type', $tokens)->count();
            if ($posted < count($tokens)) {
                $months[] = $month;
            }
        }

        return $months;
    }

    /**
     * @param  array{id: string, date_hired: string, date_resigned: ?string, is_active: bool}  $employee
     * @param  list<HolidaySetting>  $holidays
     * @param  list<string>  $earning  the types whose CURRENT credit rules earn
     * @return int months posted
     */
    private function postEmployee(string $employeeId, array $employee, string $startMonth, string $today, array $holidays, ?Access $actor, array $earning): int
    {
        $posted = 0;
        // Years before the policy that still hold rows (hand-entered
        // credits) close first, oldest first, so their balances chain
        // forward into the years the policy posts.
        $this->closeEarlierYears($employeeId, (int) substr($startMonth, 0, 4), $today, $actor);
        $lastMonth = $employee['date_resigned'] !== null ? min(substr($employee['date_resigned'], 0, 7), substr($today, 0, 7)) : substr($today, 0, 7);
        for ($month = $startMonth; $month <= $lastMonth; $month = self::nextMonth($month)) {
            $range = PayrollPeriod::calendarMonthRange($month.'-01');
            $policyRow = LeavePolicyStore::inForce($employeeId, $range['to']);
            if ($policyRow === null) {
                continue;
            }
            $policy = LeavePolicyStore::policy($policyRow);
            $year = (int) substr($month, 0, 4);
            if ($policy->earning() !== []) {
                // The posting date: the month's last day, or day N of the month after; due once it has passed.
                $postingDate = $policy->postingDay() === LeavePolicy::MONTH_END
                    ? $range['to']
                    : self::nextMonth($month).'-'.str_pad((string) $policy->postingDay(), 2, '0', STR_PAD_LEFT);
                if ($postingDate >= $today) {
                    break;
                }
                // The policy's earning types, kept to those whose CURRENT
                // credit rules still earn: a type HR un-ticked since the
                // policy was approved posts nothing from here on.
                $tokens = array_values(array_intersect(array_keys($policy->earning()), $earning));
                if ($tokens === []) {
                    continue;
                }
                $already = LeaveEarningPosting::query()->where('employee_id', $employeeId)->where('period', $month)->whereIn('request_type', $tokens)->pluck('request_type')->all();
                $due = array_values(array_diff($tokens, $already));
                if ($due !== []) {
                    $attendance = app(AttendanceInputQuery::class)->forPosting([$employeeId], $range['from'], $range['to'])[$employeeId];
                    $counts = LeaveMonthFacts::count($attendance, $range, $employee['date_hired'], $employee['date_resigned'], $holidays, $policy->unpaidCounts());
                    $lines = [];
                    DB::transaction(function () use ($employeeId, $month, $year, $policy, $policyRow, $due, $counts, $actor, &$lines): void {
                        LeaveCreditWriter::lock();
                        foreach ($due as $token) {
                            if (LeaveEarningPosting::query()->where('employee_id', $employeeId)->where('period', $month)->where('request_type', $token)->exists()) {
                                continue;
                            }
                            $rule = $policy->earning()[$token];
                            $before = LeaveMonthFacts::balance($employeeId, $year, $token);
                            $earned = LeaveEarningRule::month($rule, LeaveMonthFacts::forType($counts, $before));
                            $entryId = null;
                            if ($earned['days'] > 0) {
                                $entryId = $this->credit($actor ?? Access::forGuest(), $employeeId, $year, $token, 'ACCRUAL', $earned['days'], "Earned for {$month}: {$earned['basis']}");
                            }
                            LeaveEarningPosting::create([
                                'employee_id' => $employeeId, 'period' => $month, 'request_type' => $token, 'policy_id' => $policyRow->id,
                                'days' => $earned['days'], 'inputs' => [...$counts, 'balance_before' => $before, 'basis' => $earned['basis'], 'rule' => $rule], 'entry_id' => $entryId,
                            ]);
                            $lines[] = $token.' '.LeaveQuantity::format($earned['days']);
                        }
                    });
                    if ($lines !== []) {
                        $posted++;
                        $this->audit($actor, $employeeId, "Leave earned for {$month}: ".implode(' · ', $lines));
                    }
                }
            }
            // THE YEAR END: once December is behind us — posted above, or
            // earning nothing under this policy — the year closes and every
            // type's balance carries into the new one.
            if (substr($month, 5, 2) === '12' && $today > $year.'-12-31') {
                $this->closeYear($employeeId, $year, $actor);
            }
        }

        return $posted;
    }

    /**
     * Closes, oldest first, every year before `$firstPolicyYear` that still
     * holds a credits row for the employee and is not closed yet — years the
     * posting loop never visits because no policy was in force then.
     */
    private function closeEarlierYears(string $employeeId, int $firstPolicyYear, string $today, ?Access $actor): void
    {
        $years = LeaveCredit::query()->where('employee_id', $employeeId)->where('year', '<', $firstPolicyYear)
            ->distinct()->orderBy('year')->pluck('year')->map(static fn (mixed $year): int => (int) $year)->all();
        foreach ($years as $year) {
            if ($today > $year.'-12-31') {
                $this->closeYear($employeeId, $year, $actor);
            }
        }
    }

    /**
     * The year-end step for `$year`, once per type under the policy: what
     * the rule carries becomes a CARRY_FORWARD entry in the next year and
     * what a lapsing type drops a LAPSE entry in the old year, recorded
     * under `YYYY-YE`.
     */
    private function closeYear(string $employeeId, int $year, ?Access $actor): void
    {
        $period = $year.'-YE';
        $lines = [];
        DB::transaction(function () use ($employeeId, $year, $period, $actor, &$lines): void {
            LeaveCreditWriter::lock();
            // Every type that holds a row in the year, earning or not: a
            // charge-only policy's balances carry too.
            $tokens = LeaveCredit::query()->where('employee_id', $employeeId)->where('year', $year)
                ->orderBy('request_type')->pluck('request_type')->map(static fn (mixed $token): string => (string) $token)->unique()->values()->all();
            $rules = LeaveTypeCreditRecords::rules();
            foreach ($tokens as $token) {
                if (LeaveEarningPosting::query()->where('employee_id', $employeeId)->where('period', $period)->where('request_type', $token)->exists()) {
                    continue;
                }
                $closing = LeaveMonthFacts::balance($employeeId, $year, $token);
                // A yearly grant lapses above zero (LeaveTypeCredits::$yearEnd); every other type carries whole.
                $lapses = ($rules[$token]['rules'] ?? null)?->yearEnd === LeaveTypeCredits::YEAR_END_LAPSE;
                $carry = LeaveEarningRule::yearEnd($closing, $lapses);
                $lapsed = LeaveEarningRule::lapsed($closing, $lapses);
                $entryId = null;
                if ($lapsed > 0.0) {
                    $entryId = $this->credit($actor ?? Access::forGuest(), $employeeId, $year, $token, 'LAPSE', -$lapsed, "Lapsed at the end of {$year}");
                } elseif ($carry != 0.0) {
                    $entryId = $this->credit($actor ?? Access::forGuest(), $employeeId, $year + 1, $token, 'CARRY_FORWARD', $carry, "Carried from {$year}");
                }
                LeaveEarningPosting::create([
                    'employee_id' => $employeeId, 'period' => $period, 'request_type' => $token, 'policy_id' => null,
                    'days' => $carry, 'inputs' => ['closing' => $closing, 'carry' => $carry, 'lapsed' => $lapsed], 'entry_id' => $entryId,
                ]);
                $lines[] = $lapsed > 0.0 ? $token.' lapsed '.LeaveQuantity::format($lapsed) : $token.' carried '.LeaveQuantity::format($carry);
            }
        });
        if ($lines !== []) {
            $this->audit($actor, $employeeId, "Leave year {$year} closed: ".implode(' · ', $lines));
        }
    }

    /**
     * One ledger entry and the allocation it moves, under the lock the
     * caller holds; the allocation row is created on first use.
     *
     * @return string the entry's id
     */
    private function credit(Access $actor, string $employeeId, int $year, string $token, string $kind, float $days, string $note): string
    {
        return LeaveCreditWriter::move($actor, $employeeId, $year, $token, $kind, $days, $note);
    }

    private function audit(?Access $actor, string $employeeId, string $sentence): void
    {
        if ($actor !== null && $actor->userId !== null) {
            AuditService::record($actor, AuditEvent::LEAVE_EARNINGS_POSTED, 'leave_credits', $employeeId, $sentence);
        } else {
            AuditService::anonymous(self::SYSTEM_ACTOR, AuditEvent::LEAVE_EARNINGS_POSTED, 'leave_credits', $employeeId, $sentence);
        }
    }

    /** 'YYYY-MM' plus one month. */
    public static function nextMonth(string $month): string
    {
        return substr(CalendarDays::next(PayrollPeriod::calendarMonthRange($month.'-01')['to']), 0, 7);
    }
}
