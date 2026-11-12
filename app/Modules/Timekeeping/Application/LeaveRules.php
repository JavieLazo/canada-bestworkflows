<?php

declare(strict_types=1);

namespace App\Modules\Timekeeping\Application;

use App\Models\LeaveAcquisition;
use App\Models\LeaveRule;
use App\Modules\Timekeeping\Application\Queries\LeaveTypeCreditRecords;
use App\Modules\Timekeeping\Domain\Credits\LeavePolicy;
use App\Support\HrisFormat;
use Illuminate\Support\Facades\Schema;

/**
 * The leave rules as the rest of Timekeeping reads them: the book of
 * approved versions (LeaveRuleBook), and the TERMS an employee is under on
 * a date (LeaveTerms) — the rules' one policy for everybody, with the days
 * that employee acquired the leave types that wait and became eligible for
 * the types not everyone may use (LeaveEligibilities). The rules are set on
 * the leave type and nothing is set per employee, so before any rule is in
 * force nobody has terms: nothing earns and nothing is charged to credits.
 * The earning job, payroll's charge read, filing and the cash-out limit
 * all ask here.
 *
 * Before the tables exist (the window before the cron migrates
 * 2026_11_06_0001) there are no rules.
 */
final class LeaveRules
{
    /** What the terms name as their source: the leave rules, the same for everybody. */
    public const string SOURCE = 'LEAVE-RULES';

    private function __construct() {}

    public static function ready(): bool
    {
        return once(static fn (): bool => Schema::hasTable('leave_rules') && Schema::hasTable('leave_acquisitions'));
    }

    /** Whether the earning postings table exists yet (the window before the cron migrates 2026_10_31_0005). */
    public static function postingsReady(): bool
    {
        return once(static fn (): bool => Schema::hasTable('leave_earning_postings'));
    }

    /**
     * Every approved version of a leave type's rule and the leave types'
     * credit rules, read once. A version whose scope names no leave type
     * (no type carries that token) is not read.
     */
    public static function book(): LeaveRuleBook
    {
        $types = LeaveTypeCreditRecords::rules();
        $versions = [];
        if (self::ready()) {
            $rows = LeaveRule::query()->where('status', LeaveRule::APPROVED)->orderBy('effective_from')->orderBy('id')->get(['id', 'scope', 'effective_from', 'params']);
            foreach ($rows as $row) {
                if (isset($types[$row->scope])) {
                    $versions[$row->scope][] = ['id' => $row->id, 'from' => $row->effective_from->format('Y-m-d'), 'params' => $row->params];
                }
            }
        }

        return new LeaveRuleBook($versions, $types);
    }

    /**
     * The terms each of these employees is under on `$date`: the same
     * policy for all of them, each with their own acquired days. Empty
     * while no rule is in force on that date.
     *
     * @param  list<string>  $employeeIds
     * @return array<string, LeaveTerms>
     */
    public static function terms(array $employeeIds, string $date, ?LeaveRuleBook $book = null): array
    {
        if ($employeeIds === []) {
            return [];
        }
        $book ??= self::book();
        if (! $book->activeOn($date)) {
            return [];
        }
        $days = $book->gated($date) === [] ? [] : self::acquired($employeeIds);
        $eligible = self::eligibleFrom($employeeIds);
        $out = [];
        foreach ($employeeIds as $employeeId) {
            $out[$employeeId] = self::under($book, $date, $days[$employeeId] ?? [], $eligible[$employeeId] ?? []);
        }

        return $out;
    }

    /**
     * The day each employee became eligible for each leave type not
     * everyone may use (LeaveEligibilities), null while they are not.
     *
     * @param  list<string>  $employeeIds
     * @param  array<string, array{date_hired: string, employment_status: ?string, employment_status_id: ?string, sex: ?string}>|null  $facts  EmployeeLeaveFacts rows the caller already holds
     * @return array<string, array<string, ?string>> employee id => token => day
     */
    public static function eligibleFrom(array $employeeIds, ?array $facts = null): array
    {
        $out = [];
        foreach (LeaveEligibilities::forEmployees($employeeIds, $facts) as $employeeId => $types) {
            foreach ($types as $token => $standing) {
                $out[$employeeId][$token] = $standing['since'];
            }
        }

        return $out;
    }

    /**
     * One employee's terms on `$date`, from the days that employee acquired
     * the types that wait — for a caller that already holds them and walks
     * many dates (the earning job). The date must be one the rules are in
     * force on.
     *
     * @param  array<string, string>  $acquiredDays  token => 'Y-m-d'
     * @param  array<string, ?string>  $eligibleFrom  token => the day the employee became eligible, null while not — the restricted types only (eligibleFrom())
     */
    public static function under(LeaveRuleBook $book, string $date, array $acquiredDays, array $eligibleFrom = []): LeaveTerms
    {
        $acquired = [];
        foreach ($book->gated($date) as $token) {
            $acquired[$token] = $acquiredDays[$token] ?? null;
        }

        return new LeaveTerms(self::SOURCE, $book->policyOn($date), $acquired, $eligibleFrom);
    }

    /** One employee's terms on `$date`, or null while no rule is in force. */
    public static function termsFor(string $employeeId, string $date): ?LeaveTerms
    {
        return self::terms([$employeeId], $date)[$employeeId] ?? null;
    }

    /** The policy one employee is under on `$date`; with no rule in force, nothing earns and nothing is charged. */
    public static function policyFor(string $employeeId, string $date): LeavePolicy
    {
        $terms = self::termsFor($employeeId, $date);

        return $terms === null ? LeavePolicy::none() : $terms->policy;
    }

    /**
     * What the rules say about FILING a leave type for an employee from
     * `$start`: why it is refused — the employee may not use the type
     * (LeaveEligibilities), or it waits and is not theirs on that day — and
     * the leave type its credits may be topped up from when they run out.
     * One reading for the command that files, the hint the filing forms
     * show and the reviewer's dialog, so the three never disagree.
     *
     * @return array{refusal: ?string, fallback: ?string, fallback_name: ?string}
     */
    public static function filing(string $employeeId, string $type, string $start, ?LeaveRuleBook $book = null): array
    {
        $none = ['refusal' => null, 'fallback' => null, 'fallback_name' => null];
        // Who may use the type is a setting of the type, not a rule: it
        // holds whether or not any rule is in force.
        $refusal = LeaveEligibilities::refusal($employeeId, $type, $start);
        if ($refusal !== null) {
            return [...$none, 'refusal' => $refusal];
        }
        $book ??= self::book();
        $terms = self::terms([$employeeId], $start, $book)[$employeeId] ?? null;
        if ($terms === null) {
            return $none;
        }
        $names = $book->names();
        $rule = $book->typeRule($type, $start);
        if (! $terms->open($type, $start)) {
            $name = $names[$type] ?? $type;
            $day = $terms->acquiredOn($type);
            $months = (int) $rule->waitingMonths();

            return [...$none, 'refusal' => $day === null
                ? "{$name} is not acquired yet: it becomes available after {$months} ".($months === 1 ? 'month' : 'months').' of service.'
                : "{$name} is available from ".HrisFormat::date($day).'. Choose dates from that day on.'];
        }
        $fallback = $rule->fallback();
        if ($fallback === null || ! isset($names[$fallback]) || ! $terms->open($fallback, $start)) {
            return $none;
        }

        return ['refusal' => null, 'fallback' => $fallback, 'fallback_name' => $names[$fallback]];
    }

    /**
     * The days these employees acquired their waiting leave types.
     *
     * @param  list<string>  $employeeIds
     * @return array<string, array<string, string>> employee id => token => 'Y-m-d'
     */
    public static function acquired(array $employeeIds): array
    {
        if ($employeeIds === [] || ! self::ready()) {
            return [];
        }
        $out = [];
        foreach (LeaveAcquisition::query()->whereIn('employee_id', $employeeIds)->get(['employee_id', 'request_type', 'acquired_on']) as $row) {
            $out[$row->employee_id][$row->request_type] = $row->acquired_on->format('Y-m-d');
        }

        return $out;
    }
}
