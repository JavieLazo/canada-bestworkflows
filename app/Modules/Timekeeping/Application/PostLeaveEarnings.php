<?php

declare(strict_types=1);

namespace App\Modules\Timekeeping\Application;

use App\Domain\Attendance\CalendarDays;
use App\Domain\Attendance\HolidaySetting;
use App\Domain\Enums\AuditEvent;
use App\Domain\Enums\ModuleCode;
use App\Domain\Payroll\PayrollPeriod;
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
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * THE MONTH-END EARNING. WHO IT WALKS: EVERY employee, once any leave rule
 * is in force (LeaveRules) — the rules are set on the leave type and are
 * the same for all of them; with none in force it posts nothing. The terms
 * of a month are those in force on its last day (LeaveTerms).
 *
 * A leave type with a WAITING PERIOD is acquired first
 * (LeaveAcquisitions): the day is recorded once, with the credits the rule
 * grants on it, and the type earns from the month that day falls in — that
 * month counted from the day, so it takes the part-month rule.
 *
 * A leave type NOT EVERYONE MAY USE (LeaveEligibilities) earns only from
 * the day the employee became eligible, judged on their record today: a
 * month that ends before that day, or while they are not eligible, is
 * closed with a posting of nothing, so a later change of status never earns
 * those months back. The month they became eligible in is counted from the
 * day.
 *
 * Every month from the start to the last month whose posting day has passed
 * is posted once: each leave type that earns under the month's terms gets
 * an ACCRUAL ledger entry for what LeaveEarningRule gives it from the
 * month's facts (LeaveMonthFacts), and a `leave_earning_postings` row
 * records the facts, the rule version and the entry — the row exists even
 * when the month earns nothing, so the month is never looked at twice. Once
 * a year is over and
 * its December is posted (or earns nothing), the year is CLOSED: every leave
 * type's whole closing balance — a debt below zero included — becomes a
 * CARRY_FORWARD entry in the new year, recorded under the period `YYYY-YE`.
 * A carrying type's credits never lapse. Years before the rules that still hold hand-entered
 * rows are closed first, oldest first, so a balance chains into the present
 * and a payroll always charges the live year (LeaveCharges::liveYear).
 *
 * Idempotent by the unique key on (employee, period, type): the schedule
 * runs it every half hour and a run that finds everything posted writes
 * nothing. Each employee-month is one transaction under the leave-credits
 * lock, the lock every credit writer takes first. The schedule's runs post
 * as "System (scheduled)", and the schedule is its only caller: no screen
 * runs it. Hired and left dates come from Workforce's
 * EmployeeLeaveFacts; an employee who left earns through the month they
 * left, and an inactive record with no Date Resigned is skipped and named
 * in the run's `skipped` list. A type earns only while its current credit
 * rules say it earns (LeaveTypeCreditRecords), whatever the policy says.
 */
final class PostLeaveEarnings
{
    public const string SYSTEM_ACTOR = 'System (scheduled)';

    /** The cache key holding when the schedule last ran the job (a UTC timestamp). */
    private const string LAST_RUN = 'leave-earning:last-run';

    /**
     * Records that the schedule ran the job just now — also when it posted
     * nothing, which is the usual run. A screen has no way to run the job,
     * so this is how a leave manager sees that it is running at all.
     */
    public static function markRun(): void
    {
        Cache::forever(self::LAST_RUN, CarbonImmutable::now('UTC')->format('Y-m-d H:i:s'));
    }

    /** When the schedule last ran the job, in UTC; null when it has not run since the cache was last cleared. */
    public static function lastRun(): ?CarbonImmutable
    {
        $at = Cache::get(self::LAST_RUN);

        return is_string($at) && $at !== '' ? CarbonImmutable::parse($at, 'UTC') : null;
    }

    /**
     * Posts every month that is due as of `$today` (Manila, 'Y-m-d').
     *
     * @param  list<string>|null  $employeeIds  null for everyone the job walks
     * @return array{posted: int, employees: int, skipped: list<string>, acquired: int}
     */
    public function run(string $today, ?Access $actor = null, ?array $employeeIds = null): array
    {
        // The schedule runs with no actor; a person running it must manage
        // leave for everyone, as every other credit writer requires.
        if ($actor !== null && ! $actor->mayReadAllData(ModuleCode::LEAVES)) {
            throw new RuntimeException('Your role cannot manage organization-wide data.');
        }
        if (! LeaveRules::postingsReady() || ! LeaveTypeCreditRecords::ready()) {
            return ['posted' => 0, 'employees' => 0, 'skipped' => [], 'acquired' => 0];
        }
        // With no rule in force there is nothing to post: nobody has terms.
        // Once one is, the job walks EVERY employee — the rules are the
        // same for all of them.
        $book = LeaveRules::book();
        $rulesFrom = $book->startsOn();
        if ($rulesFrom === null || $rulesFrom > $today) {
            return ['posted' => 0, 'employees' => 0, 'skipped' => [], 'acquired' => 0];
        }
        $facts = app(EmployeeLeaveFacts::class)->forPosting($employeeIds);
        $ids = $employeeIds ?? array_keys($facts);
        if ($ids === []) {
            return ['posted' => 0, 'employees' => 0, 'skipped' => [], 'acquired' => 0];
        }
        $holidays = app(HolidayCalendar::class)->all();
        $earning = [];
        foreach (LeaveTypeCreditRecords::rules() as $token => $entry) {
            if ($entry['rules']->earnsCredits) {
                $earning[] = $token;
            }
        }
        // The day each employee became eligible for each leave type not
        // everyone may use, judged on their record today.
        $eligible = LeaveRules::eligibleFrom(array_map(strval(...), $ids), $facts);
        $posted = 0;
        $employees = 0;
        $acquisitions = 0;
        $skipped = [];
        foreach ($ids as $employeeId) {
            $employeeId = (string) $employeeId;
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
            // The leave types that wait are acquired first, so a month's
            // earning already sees the day.
            $acquired = 0;
            foreach (app(LeaveAcquisitions::class)->settle($employee, $today, $holidays, $book, $actor, $eligible[$employeeId] ?? []) as $sentence) {
                $acquired++;
                $this->audit($actor, $employeeId, $sentence);
            }
            // The months begin where the rules did, or in the month hired.
            $start = max(substr($rulesFrom, 0, 7), substr($employee['date_hired'], 0, 7));
            $count = $this->postEmployee($employeeId, $employee, $start, $today, $holidays, $actor, $earning, $book, $eligible[$employeeId] ?? []);
            if ($count > 0 || $acquired > 0) {
                $employees++;
                $posted += $count;
                $acquisitions += $acquired;
            }
        }

        return ['posted' => $posted, 'employees' => $employees, 'skipped' => $skipped, 'acquired' => $acquisitions];
    }

    /**
     * @param  array{id: string, date_hired: string, date_resigned: ?string, is_active: bool}  $employee
     * @param  list<HolidaySetting>  $holidays
     * @param  list<string>  $earning  the types whose CURRENT credit rules earn
     * @param  array<string, ?string>  $eligibleFrom  token => the day the employee became eligible, null while not — the types not everyone may use
     * @return int months posted
     */
    private function postEmployee(string $employeeId, array $employee, string $startMonth, string $today, array $holidays, ?Access $actor, array $earning, LeaveRuleBook $book, array $eligibleFrom): int
    {
        $posted = 0;
        // Read once for the whole walk: the days this employee acquired the
        // types that wait, and the months already posted, by type.
        $acquired = LeaveRules::acquired([$employeeId])[$employeeId] ?? [];
        $postedTypes = [];
        foreach (LeaveEarningPosting::query()->where('employee_id', $employeeId)->get(['period', 'request_type']) as $row) {
            $postedTypes[$row->period][] = $row->request_type;
        }
        // Years before the rules that still hold rows (hand-entered
        // credits) close first, oldest first, so their balances chain
        // forward into the years the job posts.
        $this->closeEarlierYears($employeeId, (int) substr($startMonth, 0, 4), $today, $actor);
        $lastMonth = $employee['date_resigned'] !== null ? min(substr($employee['date_resigned'], 0, 7), substr($today, 0, 7)) : substr($today, 0, 7);
        for ($month = $startMonth; $month <= $lastMonth; $month = self::nextMonth($month)) {
            $range = PayrollPeriod::calendarMonthRange($month.'-01');
            // The terms on the month's last day; a month that ended before
            // the rules began has none.
            if (! $book->activeOn($range['to'])) {
                continue;
            }
            $terms = LeaveRules::under($book, $range['to'], $acquired, $eligibleFrom);
            $policy = $terms->policy;
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
                // A type the employee may not use earns nothing in a month
                // that ends before they became eligible — or while they are
                // not — and that month is CLOSED with a posting of nothing,
                // so becoming eligible later never goes back over it.
                $closed = array_values(array_filter($tokens, static fn (string $token): bool => $terms->closedFor($token, $range['to'])));
                $dueClosed = array_values(array_diff($closed, $postedTypes[$month] ?? []));
                if ($dueClosed !== []) {
                    $this->closeIneligible($employeeId, $month, $dueClosed, $book, $range['to']);
                }
                // A type that waits earns from the month it is acquired in:
                // one not acquired by the month's last day posts nothing, and
                // the month stays open for the day it is.
                $tokens = array_values(array_filter($tokens, static fn (string $token): bool => $terms->open($token, $range['to'])));
                if ($tokens === []) {
                    continue;
                }
                $due = array_values(array_diff($tokens, $postedTypes[$month] ?? []));
                if ($due !== []) {
                    $attendance = app(AttendanceInputQuery::class)->forPosting([$employeeId], $range['from'], $range['to'])[$employeeId];
                    // The month is counted from the day the employee held
                    // the type: the date hired, the day they became
                    // eligible, or the day it was acquired, so that month
                    // takes the part-month rule.
                    $countsFor = [];
                    $versionFor = [];
                    $countsSince = [];
                    foreach ($due as $token) {
                        $since = max($employee['date_hired'], (string) $terms->openFrom($token));
                        $countsFor[$token] = $countsSince[$since] ??= LeaveMonthFacts::count($attendance, $range, $since, $employee['date_resigned'], $holidays, $policy->unpaidCounts());
                        $versionFor[$token] = $book->version($token, $range['to'])['id'] ?? null;
                    }
                    $lines = [];
                    DB::transaction(function () use ($employeeId, $month, $year, $policy, $versionFor, $due, $countsFor, $actor, &$lines): void {
                        LeaveCreditWriter::lock();
                        foreach ($due as $token) {
                            if (LeaveEarningPosting::query()->where('employee_id', $employeeId)->where('period', $month)->where('request_type', $token)->exists()) {
                                continue;
                            }
                            $rule = $policy->earning()[$token];
                            $counts = $countsFor[$token];
                            $before = LeaveMonthFacts::balance($employeeId, $year, $token);
                            $earned = LeaveEarningRule::month($rule, LeaveMonthFacts::forType($counts, $before));
                            $entryId = null;
                            if ($earned['days'] > 0) {
                                $entryId = $this->credit($actor ?? Access::forGuest(), $employeeId, $year, $token, 'ACCRUAL', $earned['days'], "Credits earned for {$month}: {$earned['basis']}");
                            }
                            LeaveEarningPosting::create([
                                'employee_id' => $employeeId, 'period' => $month, 'request_type' => $token, 'policy_id' => $versionFor[$token],
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
     * Closes a month for the earning types the employee may not use in it:
     * a posting of nothing each, recording why, under the leave-credits
     * lock and re-checked there like every posting. No ledger entry and no
     * audit line: nothing was earned.
     *
     * @param  list<string>  $tokens
     */
    private function closeIneligible(string $employeeId, string $month, array $tokens, LeaveRuleBook $book, string $monthEnd): void
    {
        DB::transaction(static function () use ($employeeId, $month, $tokens, $book, $monthEnd): void {
            LeaveCreditWriter::lock();
            foreach ($tokens as $token) {
                if (LeaveEarningPosting::query()->where('employee_id', $employeeId)->where('period', $month)->where('request_type', $token)->exists()) {
                    continue;
                }
                LeaveEarningPosting::create([
                    'employee_id' => $employeeId, 'period' => $month, 'request_type' => $token, 'policy_id' => $book->version($token, $monthEnd)['id'] ?? null,
                    'days' => 0.0, 'inputs' => ['basis' => 'Not eligible for this leave type'], 'entry_id' => null,
                ]);
            }
        });
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
                    $entryId = $this->credit($actor ?? Access::forGuest(), $employeeId, $year, $token, 'LAPSE', -$lapsed, "Expired at the end of {$year}");
                } elseif ($carry != 0.0) {
                    $entryId = $this->credit($actor ?? Access::forGuest(), $employeeId, $year + 1, $token, 'CARRY_FORWARD', $carry, "Carried forward from {$year}");
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
