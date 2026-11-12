<?php

declare(strict_types=1);

namespace App\Modules\Timekeeping\Application;

use App\Domain\Attendance\HolidaySetting;
use App\Domain\Payroll\PayrollPeriod;
use App\Models\LeaveAcquisition;
use App\Modules\Timekeeping\Domain\Credits\LeaveAcquisitionRule;
use App\Modules\Timekeeping\Domain\Credits\LeaveQuantity;
use App\Modules\Timekeeping\Infrastructure\AttendanceInputQuery;
use App\Support\Access;
use Illuminate\Support\Facades\DB;

/**
 * RECORDS THE DAY AN EMPLOYEE ACQUIRES A LEAVE TYPE THAT WAITS. For each
 * leave type any approved rule gives a waiting period, an employee who
 * holds no acquisition yet is measured by LeaveAcquisitionRule — the date
 * hired (or, for a type not everyone may use, the day the employee became
 * eligible, LeaveEligibilities; one they may not use is never acquired),
 * the months waited, and the days without pay that extend the wait,
 * read from the same Daily Time Record the month-end earning counts — and,
 * once the day has come, gets one `leave_acquisitions` row and, where the
 * rule grants the credits of the months waited, one ACCRUAL ledger entry.
 *
 * A day is recorded ONCE and never moves: a punch corrected afterwards
 * does not shift a date the earning and payroll already acted on. The
 * earning job calls this for every employee on every run; the unique key
 * on (employee, type), re-checked under the leave-credits lock, makes a
 * second run write nothing.
 *
 * Days without pay are counted only from the day the type's first rule
 * took effect: a rule applies from its date forward, and attendance from
 * before the rules is not judged by them.
 */
final class LeaveAcquisitions
{
    public function __construct(private readonly AttendanceInputQuery $attendance) {}

    /**
     * @param  array{id: string, date_hired: string, date_resigned: ?string, is_active: bool}  $employee
     * @param  list<HolidaySetting>  $holidays
     * @param  array<string, ?string>  $eligibleFrom  token => the day the employee became eligible, null while not — the types not everyone may use (LeaveRules::eligibleFrom)
     * @return list<string> a sentence for each type acquired on this run
     */
    public function settle(array $employee, string $today, array $holidays, LeaveRuleBook $book, ?Access $actor, array $eligibleFrom = []): array
    {
        $tokens = $book->waitingTokens();
        if ($tokens === []) {
            return [];
        }
        $employeeId = $employee['id'];
        $hired = $employee['date_hired'];
        $last = $employee['date_resigned'];
        $held = LeaveRules::acquired([$employeeId])[$employeeId] ?? [];
        $names = $book->names();
        $unpaid = [];
        $lines = [];
        foreach ($tokens as $token) {
            if (isset($held[$token])) {
                continue;
            }
            // A type the employee may not use is never acquired; one they
            // became eligible for waits from that day, not from the date hired.
            $start = $hired;
            if (array_key_exists($token, $eligibleFrom)) {
                if ($eligibleFrom[$token] === null) {
                    continue;
                }
                $start = max($hired, $eligibleFrom[$token]);
            }
            $versions = $book->waitingVersions($token);
            // Days without pay only ever push the day back, so a type not
            // reached without them is not reached with them: the Daily Time
            // Record is read only for an employee whose wait may be over.
            if (LeaveAcquisitionRule::resolve($start, $versions, [], $today, $last) === null) {
                continue;
            }
            $countFrom = max($start, $versions[0]['from']);
            $days = [];
            if (array_filter($versions, static fn (array $version): bool => $version['extends']) !== []) {
                $unpaid[$countFrom] ??= $this->unpaid($employeeId, $countFrom, $last, $today, $holidays);
                $days = $unpaid[$countFrom];
            }
            $found = LeaveAcquisitionRule::resolve($start, $versions, $days, $today, $last);
            if ($found === null) {
                continue;
            }
            $rule = $book->ruleOf($token, $found['version_id']);
            $grant = $rule !== null && $book->earns($token) ? $rule->grant() : 0.0;
            $name = $names[$token] ?? $token;
            $written = DB::transaction(static function () use ($employeeId, $token, $found, $grant, $hired, $start, $countFrom, $rule, $name, $actor): bool {
                LeaveCreditWriter::lock();
                if (LeaveAcquisition::query()->where('employee_id', $employeeId)->where('request_type', $token)->exists()) {
                    return false;
                }
                $entryId = null;
                if ($grant > 0.0) {
                    $amount = $rule?->earning()['amount'] ?? 0.0;
                    $entryId = LeaveCreditWriter::move($actor ?? Access::forGuest(), $employeeId, LeaveBalances::liveYear($employeeId, $found['date']), $token, 'ACCRUAL', $grant,
                        "Granted on acquiring {$name}: {$found['months']} months × ".LeaveQuantity::format($amount));
                }
                LeaveAcquisition::create([
                    'employee_id' => $employeeId, 'request_type' => $token, 'acquired_on' => $found['date'], 'rule_id' => $found['version_id'],
                    'basis' => ['hired' => $hired, ...($start !== $hired ? ['eligible_from' => $start] : []), 'months' => $found['months'], 'extension_days' => $found['extension_days'], 'unpaid_days' => $found['unpaid_days'], 'counted_from' => $countFrom],
                    'granted' => $grant, 'entry_id' => $entryId,
                ]);

                return true;
            });
            if ($written) {
                $lines[] = "{$name} acquired on {$found['date']} ({$found['months']} months from {$start}".($start !== $hired ? ', the day eligible' : '')
                    .($found['extension_days'] > 0 ? ", extended {$found['extension_days']} ".($found['extension_days'] === 1 ? 'day' : 'days').' without pay' : '').')'
                    .($grant > 0.0 ? ' · '.LeaveQuantity::format($grant).' granted' : '');
            }
        }

        return $lines;
    }

    /**
     * The employee's days without pay from `$from` to yesterday (or their
     * last day), by date.
     *
     * @param  list<HolidaySetting>  $holidays
     * @return array<string, float>
     */
    private function unpaid(string $employeeId, string $from, ?string $last, string $today, array $holidays): array
    {
        $to = PayrollPeriod::addCalendarDays($today, -1);
        if ($last !== null && $last < $to) {
            $to = $last;
        }
        if ($from > $to) {
            return [];
        }
        $facts = $this->attendance->forPosting([$employeeId], $from, $to)[$employeeId] ?? null;

        return $facts === null ? [] : LeaveMonthFacts::unpaidByDate($facts, $from, $to, $holidays);
    }
}
