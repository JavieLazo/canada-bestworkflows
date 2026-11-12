<?php

declare(strict_types=1);

namespace App\Modules\Timekeeping\Application\Queries;

use App\Domain\Enums\ModuleCode;
use App\Domain\Payroll\PayrollPeriod;
use App\Models\LeaveAcquisition;
use App\Models\LeaveRule;
use App\Modules\Timekeeping\Application\LeaveEligibilities;
use App\Modules\Timekeeping\Application\LeaveRules;
use App\Modules\Timekeeping\Application\LeaveSettingsAccess;
use App\Modules\Timekeeping\Application\PostLeaveEarnings;
use App\Modules\Timekeeping\Contracts\Views\LeaveRuleView;
use App\Modules\Timekeeping\Domain\Credits\LeaveAcquisitionRule;
use App\Modules\Timekeeping\Domain\Credits\LeaveEligibility;
use App\Modules\Timekeeping\Domain\Credits\LeaveTypeRule;
use App\Modules\Workforce\Contracts\EmployeeLeaveFacts;
use App\Platform\ReadModels\Snapshot;
use App\Support\Access;
use App\Support\HrisFormat;
use App\Support\ManilaClock;

/**
 * The leave rules as Leave Settings shows them — the one rule each leave
 * type has today; company-wide settings with no employee column, readable
 * by whoever may open the settings, with how many employees each type's
 * "who may use it" reaches — and, for Leave Management, where each employee
 * stands with the leave types that wait (the day acquired, or the day
 * expected) and with the types not everyone may use. Those reads are leave
 * data and stay behind the leave list's row scope (LEAVES).
 */
final class LeaveRuleRecords
{
    public static function ready(): bool
    {
        return LeaveRules::ready();
    }

    /**
     * The rule each leave type has today, for the settings page and the
     * form that changes it: token => the version in force. A type with no
     * version has no entry: it waits for nothing and earns nothing.
     *
     * @return array<string, LeaveRuleView>
     */
    public function inForce(Access $access): array
    {
        if (! self::ready() || ! LeaveSettingsAccess::mayView($access)) {
            return [];
        }
        $today = ManilaClock::todayKey();
        $book = LeaveRules::book();
        $ids = [];
        foreach ($book->scopes() as $scope) {
            $id = $book->version($scope, $today)['id'] ?? null;
            if ($id !== null) {
                $ids[] = $id;
            }
        }
        if ($ids === []) {
            return [];
        }
        $names = $book->names();
        $out = [];
        foreach (LeaveRule::query()->whereKey($ids)->get() as $row) {
            $out[$row->scope] = Snapshot::of($row, LeaveRuleView::class, [
                'summary' => LeaveTypeRule::fromParams($row->params)->summary($names),
            ]);
        }

        return $out;
    }

    /**
     * When the schedule last ran the earning job, as a screen shows it —
     * "Nov 07, 2026, 2:30 PM" in Manila time — or null when it has not run
     * since the cache was last cleared. For whoever may open the leave
     * credit settings or leave management.
     */
    public function lastRun(Access $access): ?string
    {
        if (! $access->hasModule(ModuleCode::RECORDS, ModuleCode::LEAVES)) {
            return null;
        }

        return PostLeaveEarnings::lastRun()?->setTimezone(ManilaClock::TZ)->format('M d, Y, g:i A');
    }

    /**
     * Where these employees stand with the leave types that wait, and with
     * the types not everyone may use, for the Leave Management list:
     * employee id => one line per type — "Vacation Leave from Jul 20, 2026"
     * once acquired, "Vacation Leave not acquired yet · Jul 15, 2026 at the
     * earliest" before, "Vacation Leave is not available to Job Order
     * employees." for a type they may not use. The expected day is the date
     * hired — or the day they became eligible — plus the months waited,
     * before any day without pay moves it.
     *
     * @param  list<string>  $employeeIds
     * @return array<string, list<string>>
     */
    public function standing(Access $access, array $employeeIds): array
    {
        // The leave list's row scope, before anything is read: everyone for
        // an all-data holder, the actor's own employee otherwise.
        $employeeIds = match ($access->rowVisibility(ModuleCode::LEAVES)) {
            Access::ROWS_ALL => $employeeIds,
            Access::ROWS_OWN => array_values(array_intersect($employeeIds, [$access->ownEmployeeId()])),
            Access::ROWS_NONE => [],
        };
        if (! self::ready() || $employeeIds === []) {
            return [];
        }
        $book = LeaveRules::book();
        $tokens = $book->waitingTokens();
        $restricted = LeaveTypeCreditRecords::restricted();
        if ($tokens === [] && $restricted === []) {
            return [];
        }
        $facts = app(EmployeeLeaveFacts::class)->forPosting($employeeIds);
        $hiredById = array_map(static fn (array $row): string => $row['date_hired'], $facts);
        $eligibility = LeaveEligibilities::forEmployees(array_keys($hiredById), $facts, $restricted);
        $names = $book->names();
        $settings = LeaveTypeCreditRecords::rules();
        $held = [];
        $rows = LeaveAcquisition::query()->toEmployee($access, 'employee_id', ModuleCode::LEAVES)->whereIn('employee_id', array_keys($hiredById))->get(['employee_id', 'request_type', 'acquired_on']);
        foreach ($rows as $row) {
            $held[$row->employee_id][$row->request_type] = $row->acquired_on->format('Y-m-d');
        }
        $out = [];
        foreach ($hiredById as $employeeId => $hired) {
            // The tab is about credits: a line only for a type filed against
            // them that the employee may not use.
            foreach ($restricted as $token => $rule) {
                $standing = $eligibility[$employeeId][$token] ?? null;
                if ($standing !== null && $standing['since'] === null && ($settings[$token]['rules'] ?? null)?->filedAgainstCredits() === true) {
                    $out[$employeeId][] = LeaveEligibilities::notEligible($names[$token] ?? $token, $rule, $standing['refused_by'], $facts[$employeeId] ?? null);
                }
            }
            foreach ($tokens as $token) {
                $name = $names[$token] ?? $token;
                if (isset($held[$employeeId][$token])) {
                    $out[$employeeId][] = "{$name} from ".HrisFormat::date($held[$employeeId][$token]);

                    continue;
                }
                // A type they may not use waits on nothing; one they became
                // eligible for waits from that day.
                $standing = $eligibility[$employeeId][$token] ?? null;
                if ($standing !== null && $standing['since'] === null) {
                    continue;
                }
                $from = max($hired, (string) ($standing['since'] ?? ''));
                $expected = $hired === '' ? null : LeaveAcquisitionRule::expected($from, $book->waitingVersions($token));
                if ($expected !== null) {
                    $out[$employeeId][] = "{$name} not acquired yet · ".HrisFormat::date($expected).' at the earliest';
                }
            }
        }

        return $out;
    }

    /**
     * The leave types each of these employees may NOT use today
     * (LeaveEligibilities), with why — what a filing form leaves out of its
     * type list and the Set credits dialog warns about. The actor's own
     * employee, or any employee for a holder who reads all leave data;
     * anyone else is told nothing.
     *
     * @param  list<string>  $employeeIds
     * @return array<string, array<string, string>> employee id => token => the sentence saying why
     */
    public function ineligible(Access $access, array $employeeIds): array
    {
        $employeeIds = match ($access->rowVisibility(ModuleCode::LEAVES, ModuleCode::PORTAL)) {
            Access::ROWS_ALL => $access->mayReadAllData(ModuleCode::LEAVES)
                ? $employeeIds
                : ($access->hasEmployeeLink() ? array_values(array_intersect($employeeIds, [$access->ownEmployeeId()])) : []),
            Access::ROWS_OWN => array_values(array_intersect($employeeIds, [$access->ownEmployeeId()])),
            Access::ROWS_NONE => [],
        };
        $restricted = LeaveTypeCreditRecords::restricted();
        if ($employeeIds === [] || $restricted === []) {
            return [];
        }
        $facts = app(EmployeeLeaveFacts::class)->forPosting($employeeIds);
        $names = array_map(static fn (array $entry): string => $entry['name'], LeaveTypeCreditRecords::rules());
        $out = [];
        foreach (LeaveEligibilities::forEmployees($employeeIds, $facts, $restricted) as $employeeId => $types) {
            foreach ($types as $token => $standing) {
                if ($standing['since'] === null) {
                    $out[$employeeId][$token] = LeaveEligibilities::notEligible($names[$token] ?? $token, $restricted[$token], $standing['refused_by'], $facts[$employeeId] ?? null);
                }
            }
        }

        return $out;
    }

    /**
     * Who may use each leave type, for Leave Settings: how many active
     * employees the choice in a draft (or a saved type) reaches, and how many
     * it leaves out — by employment status, and those whose record names no
     * status on the list or no sex. Counts only, never who; read by whoever
     * may open the settings.
     *
     * @return array{eligible: int, excluded: array<string, int>, no_status: int, no_sex: int}
     */
    public function reach(Access $access, LeaveEligibility $rule): array
    {
        $none = ['eligible' => 0, 'excluded' => [], 'no_status' => 0, 'no_sex' => 0];
        if (! LeaveSettingsAccess::mayView($access)) {
            return $none;
        }
        $out = $none;
        foreach (app(EmployeeLeaveFacts::class)->forPosting() as $employee) {
            if (! $employee['is_active']) {
                continue;
            }
            $refused = $rule->refusedBy($employee['employment_status_id'], $employee['sex']);
            if ($refused === null) {
                $out['eligible']++;
            } elseif ($refused === LeaveEligibility::BY_SEX) {
                if ($employee['sex'] === null) {
                    $out['no_sex']++;
                } else {
                    $sex = strtolower($employee['sex']);
                    $out['excluded'][$sex] = ($out['excluded'][$sex] ?? 0) + 1;
                }
            } elseif ($employee['employment_status_id'] === null) {
                $out['no_status']++;
            } else {
                $status = (string) $employee['employment_status'];
                $out['excluded'][$status] = ($out['excluded'][$status] ?? 0) + 1;
            }
        }
        ksort($out['excluded'], SORT_NATURAL | SORT_FLAG_CASE);

        return $out;
    }

    /**
     * What the rules say about filing a leave type for one employee from
     * `$start` (LeaveRules::filing), for a screen: the actor's own employee,
     * or any employee for a holder who reads all leave data. Anyone else is
     * told nothing.
     *
     * @return array{refusal: ?string, fallback: ?string, fallback_name: ?string}
     */
    public function filing(Access $access, string $employeeId, string $type, string $start): array
    {
        $allowed = match ($access->rowVisibility(ModuleCode::LEAVES, ModuleCode::PORTAL)) {
            Access::ROWS_ALL => $access->mayReadAllData(ModuleCode::LEAVES) || ($access->hasEmployeeLink() && $access->ownEmployeeId() === $employeeId),
            Access::ROWS_OWN => $access->ownEmployeeId() === $employeeId,
            Access::ROWS_NONE => false,
        };
        if (! $allowed || ! self::ready() || $employeeId === '' || trim($type) === '' || ! PayrollPeriod::isPayrollDate($start)) {
            return ['refusal' => null, 'fallback' => null, 'fallback_name' => null];
        }

        return LeaveRules::filing($employeeId, $type, $start);
    }
}
