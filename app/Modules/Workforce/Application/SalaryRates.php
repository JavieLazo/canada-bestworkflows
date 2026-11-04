<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Application;

use App\Domain\Enums\AuditEvent;
use App\Domain\Payroll\PayrollPeriod;
use App\Models\EmployeeProfile;
use App\Models\EmployeeSalaryRate;
use App\Support\Access;
use App\Support\AuditService;
use App\Support\EmployeeName;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * An employee's salary as dated versions (`employee_salary_rates`): the
 * store the commands write and the record, the cron and payroll read.
 *
 * THE PROFILE COLUMNS ARE THE RATES IN FORCE TODAY. A version that has
 * taken effect is copied onto `employee_profiles.monthly_rate` /
 * `daily_rate` — when it is recorded or approved on or before its date,
 * else by `salary:apply-due` on its date (`applyDue`) — so every reader of
 * the record (the lists, the CSV, the 201 sheet, Employment History's
 * snapshots) keeps reading what it always read. Payroll prices each DAY
 * by the version in force on it (`segments`, PayslipCalculator divergence
 * 18), never by the columns alone.
 *
 * A record edit that changes the rates on Employee Details still works:
 * it becomes a version dated today (`recordProfileEdit`), so the history
 * is one. Before the table exists (the window before the cron migrates
 * 2026_11_02_0008) the columns stand on their own.
 */
final class SalaryRates
{
    public const int REASON_MAX = 160;

    public const int REFERENCE_MAX = 120;

    private function __construct() {}

    public static function ready(): bool
    {
        return once(static fn (): bool => Schema::hasTable('employee_salary_rates'));
    }

    /** Why a rate pair cannot be a version, or null: numbers at or above zero, at least one of them. */
    public static function ratesError(mixed $monthly, mixed $daily): ?string
    {
        foreach (['monthly' => $monthly, 'daily' => $daily] as $label => $value) {
            if ($value !== null && (! is_numeric($value) || (float) $value < 0.0 || (float) $value > 99_999_999.99)) {
                return $label === 'monthly' ? 'Rate Per Month: enter an amount, or leave it blank.' : 'Rate Per Day: enter an amount, or leave it blank.';
            }
        }
        if ($monthly === null && $daily === null) {
            return 'Enter a Rate Per Month or a Rate Per Day.';
        }

        return null;
    }

    /** Why a date cannot start a version, or null. */
    public static function dateError(string $effectiveFrom): ?string
    {
        return PayrollPeriod::isPayrollDate($effectiveFrom) ? null : 'Enter the date the salary takes effect.';
    }

    /** Why a reason cannot carry a version, or null. */
    public static function reasonError(string $reason): ?string
    {
        $reason = trim($reason);
        if ($reason === '') {
            return 'Enter the reason for the salary change.';
        }

        return mb_strlen($reason) > self::REASON_MAX ? 'The reason must be at most 160 characters.' : null;
    }

    /**
     * The approved versions pricing each employee over a period: the one in
     * force on `$start` first (dated as it is), then each later one through
     * `$end`, ascending. An employee with no approved version on or before
     * the start has no first segment and is priced by their profile until
     * the first version inside the period. Employees with nothing at all
     * are absent.
     *
     * @param  list<string>  $employeeIds
     * @return array<string, list<array{from: string, monthly_rate: ?float, daily_rate: ?float}>>
     */
    public static function segments(array $employeeIds, string $start, string $end): array
    {
        if ($employeeIds === [] || ! self::ready()) {
            return [];
        }
        $rows = EmployeeSalaryRate::query()
            ->whereIn('employee_id', $employeeIds)
            ->where('status', EmployeeSalaryRate::APPROVED)
            ->whereDate('effective_from', '<=', $end)
            ->orderBy('effective_from')->orderBy('id')
            ->get();
        $out = [];
        foreach ($rows as $row) {
            $from = $row->effective_from->format('Y-m-d');
            $segment = ['from' => $from, 'monthly_rate' => $row->monthly_rate, 'daily_rate' => $row->daily_rate];
            $list = $out[$row->employee_id] ?? [];
            if ($from <= $start && $list !== [] && $list[0]['from'] <= $start) {
                // A later version still before the start replaces the first.
                $list[0] = $segment;
            } else {
                $list[] = $segment;
            }
            $out[$row->employee_id] = $list;
        }

        return $out;
    }

    /**
     * Copies every version that has taken effect by `$today` onto its
     * employee's profile where the profile still shows older rates: the
     * cron's job on the morning a future-dated change starts. Returns the
     * employees changed.
     *
     * @return list<string>
     */
    public static function applyDue(string $today): array
    {
        if (! self::ready()) {
            return [];
        }
        $applied = [];
        $latest = [];
        $rows = EmployeeSalaryRate::query()
            ->where('status', EmployeeSalaryRate::APPROVED)
            ->whereDate('effective_from', '<=', $today)
            ->orderBy('effective_from')->orderBy('id')
            ->get();
        foreach ($rows as $row) {
            $latest[$row->employee_id] = $row;
        }
        foreach ($latest as $employeeId => $row) {
            $changed = DB::transaction(static function () use ($employeeId, $row): ?EmployeeProfile {
                $employee = EmployeeProfile::query()->whereKey($employeeId)->lockForUpdate()->first();
                if ($employee === null || ! self::differs($employee, $row)) {
                    return null;
                }
                self::copy($employee, $row);

                return $employee;
            });
            if ($changed !== null) {
                $applied[] = (string) $employeeId;
                AuditService::anonymous('System (scheduled)', AuditEvent::SALARY_CHANGE_APPLIED, 'employee_profiles', $changed->id,
                    EmployeeName::display($changed).' — salary version from '.$row->effective_from->format('M d, Y').' applied to the record');
            }
        }

        return $applied;
    }

    /** Whether the profile's rates differ from the version's. */
    public static function differs(EmployeeProfile $employee, EmployeeSalaryRate $row): bool
    {
        return self::money($employee->monthly_rate) !== self::money($row->monthly_rate)
            || self::money($employee->daily_rate) !== self::money($row->daily_rate);
    }

    /** Writes the version's rates onto the profile (the caller holds the row lock). */
    public static function copy(EmployeeProfile $employee, EmployeeSalaryRate $row): void
    {
        $employee->forceFill(['monthly_rate' => $row->monthly_rate, 'daily_rate' => $row->daily_rate])->save();
    }

    /**
     * An Employee Details edit that changed the rates, recorded as the
     * version dated today — approved by whoever made the edit, the way the
     * record always took it — so the history stays one. A version already
     * dated today is brought to the new rates instead. Called inside the
     * edit's transaction, after the profile row is written.
     */
    public static function recordProfileEdit(Access $actor, EmployeeProfile $employee, ?float $monthlyBefore, ?float $dailyBefore, string $today): void
    {
        if (! self::ready()) {
            return;
        }
        if (self::money($monthlyBefore) === self::money($employee->monthly_rate) && self::money($dailyBefore) === self::money($employee->daily_rate)) {
            return;
        }
        // The rates the record held before this edit are its first version
        // when none exists yet, so the history starts whole.
        self::ensureBaseline($employee, $monthlyBefore, $dailyBefore);
        $row = EmployeeSalaryRate::query()
            ->where('employee_id', $employee->id)
            ->whereDate('effective_from', $today)
            ->where('status', '!=', EmployeeSalaryRate::REJECTED)
            ->lockForUpdate()
            ->first() ?? new EmployeeSalaryRate;
        $row->fill([
            'employee_id' => $employee->id,
            'effective_from' => $today,
            'monthly_rate' => $employee->monthly_rate,
            'daily_rate' => $employee->daily_rate,
            'reason' => 'Changed on Employee Details',
            'reference' => null,
            'status' => EmployeeSalaryRate::APPROVED,
            'review_note' => null,
        ]);
        $row->prepared_by = $actor->userId === null ? null : (int) $actor->userId;
        $row->prepared_by_name = $actor->userId === null ? null : mb_substr($actor->fullName, 0, 200);
        $row->approved_by = $row->prepared_by;
        $row->approved_by_name = $row->prepared_by_name;
        $row->approved_at = CarbonImmutable::now('UTC');
        $row->save();
    }

    /**
     * One approved version from the hire date holding these rates, for an
     * employee with no version at all (hired before the versions existed,
     * or seeded with no rate) — the migration's seed for one record. Writes
     * nothing when a version exists or both rates are blank or zero. The
     * caller holds the employee's row lock.
     */
    public static function ensureBaseline(EmployeeProfile $employee, ?float $monthly, ?float $daily): void
    {
        if (! self::ready() || (($monthly ?? 0.0) <= 0.0 && ($daily ?? 0.0) <= 0.0)) {
            return;
        }
        if (EmployeeSalaryRate::query()->where('employee_id', $employee->id)->lockForUpdate()->exists()) {
            return;
        }
        $row = new EmployeeSalaryRate([
            'employee_id' => $employee->id,
            'effective_from' => $employee->date_hired->format('Y-m-d'),
            'monthly_rate' => $monthly,
            'daily_rate' => $daily,
            'reason' => 'Rates as recorded',
            'reference' => null,
            'status' => EmployeeSalaryRate::APPROVED,
            'review_note' => null,
        ]);
        $row->approved_at = CarbonImmutable::now('UTC');
        $row->save();
    }

    /** A rate as a comparable text, two decimals, null when blank. */
    public static function money(mixed $value): ?string
    {
        return $value === null || $value === '' ? null : number_format((float) $value, 2, '.', '');
    }
}
