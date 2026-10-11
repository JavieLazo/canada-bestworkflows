<?php

declare(strict_types=1);

use App\Models\EmployeeProfile;
use App\Models\PayrollIncentiveType;
use App\Models\PayrollRun;
use App\Models\PayrollRunMember;
use App\Models\PayrollRunMemberIncentive;
use App\Models\Payslip;
use App\Models\PayslipIncentive;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The three incentive tables (2026_09_26_0007 payroll_incentive_types,
 * _0008 payroll_run_member_incentives, _0009 payslip_incentives) roll back
 * newest first and re-apply cleanly, and survive a repeated up() or down()
 * — the cPanel cron re-running a half-applied deploy. Each comes back with
 * its uniques, indexes and foreign keys. ROLLBACK LOSES the catalog, the
 * draft lines and the posted itemisation; payslips and runs survive.
 *
 * THIS FILE ALWAYS ENDS MIGRATED, pass or fail: `finally` re-runs every up()
 * oldest first before anything else (the RosterLocationsMigrationTest
 * pattern). The CHECKs are MySQL/MariaDB only and belong to
 * tests/Integration/IncentiveTablesSchemaTest.
 */

/** @return array{types: mixed, lines: mixed, posted: mixed} the migrations, anonymous classes */
function incmigMigrations(): array
{
    return [
        'types' => require base_path('database/migrations/2026_09_26_0007_create_payroll_incentive_types_table.php'),
        'lines' => require base_path('database/migrations/2026_09_26_0008_create_payroll_run_member_incentives_table.php'),
        'posted' => require base_path('database/migrations/2026_09_26_0009_create_payslip_incentives_table.php'),
    ];
}

/** @param array{types: mixed, lines: mixed, posted: mixed} $m */
function incmigUp(array $m): void
{
    $m['types']->up();
    $m['lines']->up();
    $m['posted']->up();
}

/** @param array{types: mixed, lines: mixed, posted: mixed} $m */
function incmigDown(array $m): void
{
    $m['posted']->down();
    $m['lines']->down();
    $m['types']->down();
}

function incmigAssertApplied(bool $applied): void
{
    foreach (['payroll_incentive_types', 'payroll_run_member_incentives', 'payslip_incentives'] as $table) {
        expect(Schema::hasTable($table))->toBe($applied);
    }
    if (! $applied) {
        return;
    }
    expect(Schema::hasIndex('payroll_incentive_types', 'pit_code_unq', 'unique'))->toBeTrue()
        ->and(Schema::hasIndex('payroll_incentive_types', 'pit_name_unq', 'unique'))->toBeTrue()
        ->and(Schema::hasIndex('payroll_run_member_incentives', 'prmi_member_type_unq', 'unique'))->toBeTrue()
        ->and(Schema::hasIndex('payroll_run_member_incentives', 'prmi_type_idx'))->toBeTrue()
        ->and(Schema::hasForeignKey('payroll_run_member_incentives', ['member_id']))->toBeTrue()
        ->and(Schema::hasForeignKey('payroll_run_member_incentives', ['type_id']))->toBeTrue()
        ->and(Schema::hasIndex('payslip_incentives', 'psi_payslip_idx'))->toBeTrue()
        ->and(Schema::hasForeignKey('payslip_incentives', ['payslip_id']))->toBeTrue();
}

/**
 * Every CREATE, ALTER or DROP the callback sends, in order.
 *
 * @return list<string>
 */
function incmigDdlDuring(callable $callback): array
{
    DB::flushQueryLog();
    DB::enableQueryLog();
    try {
        $callback();
    } finally {
        DB::disableQueryLog();
    }

    return array_values(array_filter(
        array_column(DB::getQueryLog(), 'query'),
        static fn (string $sql): bool => preg_match('/^\s*(create|alter|drop)\b/i', $sql) === 1,
    ));
}

/** @return array{run: PayrollRun, member: PayrollRunMember, payslip: Payslip} */
function incmigPayroll(): array
{
    $employee = new EmployeeProfile;
    $employee->forceFill(['employee_code' => 'INM-'.random_int(1000, 9999), 'first_name' => 'Inc', 'last_name' => 'Mig',
        'employment_status' => 'Regular', 'date_hired' => '2024-01-01', 'is_active' => true])->save();
    $run = new PayrollRun;
    $run->forceFill(['code' => 'PR-INM-'.random_int(100, 999), 'name' => 'Incentive migration '.random_int(100, 999),
        'period_start' => '2026-08-01', 'period_end' => '2026-08-15', 'status' => 'DRAFT'])->save();
    $member = PayrollRunMember::create(['run_id' => $run->id, 'employee_id' => $employee->id, 'position' => 0,
        'period_start' => '2026-08-01', 'period_end' => '2026-08-15']);
    $payslip = new Payslip;
    $payslip->forceFill(['run_id' => $run->id, 'employee_id' => $employee->id, 'period_start' => '2026-08-01', 'period_end' => '2026-08-15',
        'gross_pay' => 1000, 'total_deductions' => 0, 'net_pay' => 1000])->save();

    return ['run' => $run, 'member' => $member, 'payslip' => $payslip];
}

it('rolls the three tables back and re-applies them twice, losing only the incentives', function () {
    $m = incmigMigrations();
    $isMySql = in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true);

    try {
        ['run' => $run, 'member' => $member, 'payslip' => $payslip] = incmigPayroll();
        $type = PayrollIncentiveType::create(['code' => '1', 'name' => 'Christmas Bonus', 'is_taxable' => false]);
        PayrollRunMemberIncentive::create(['member_id' => $member->id, 'type_id' => $type->id, 'amount' => 100]);
        PayslipIncentive::create(['payslip_id' => $payslip->id, 'position' => 0, 'type_code' => '1', 'name' => 'Christmas Bonus',
            'is_taxable' => false, 'amount' => 100, 'over_ceiling' => 0]);
        incmigAssertApplied(true);

        incmigDown($m);
        incmigAssertApplied(false);
        // A repeated rollback finds nothing to drop.
        incmigDown($m);
        incmigAssertApplied(false);

        incmigUp($m);
        incmigUp($m);
        incmigAssertApplied(true);

        // ROLLBACK LOSES the incentives; the run, its member and the payslip stay.
        expect(PayrollIncentiveType::query()->count())->toBe(0)
            ->and(PayrollRunMemberIncentive::query()->count())->toBe(0)
            ->and(PayslipIncentive::query()->count())->toBe(0)
            ->and(PayrollRunMember::query()->whereKey($member->id)->exists())->toBeTrue()
            ->and(Payslip::query()->whereKey($payslip->id)->exists())->toBeTrue();

        // And the uniques hold again.
        $again = PayrollIncentiveType::create(['code' => '1', 'name' => 'Christmas Bonus', 'is_taxable' => false]);
        expect(fn () => PayrollIncentiveType::create(['code' => '1', 'name' => 'Other', 'is_taxable' => true]))->toThrow(QueryException::class);
        PayrollRunMemberIncentive::create(['member_id' => $member->id, 'type_id' => $again->id, 'amount' => 5]);
        expect(fn () => PayrollRunMemberIncentive::create(['member_id' => $member->id, 'type_id' => $again->id, 'amount' => 6]))->toThrow(QueryException::class);
    } finally {
        incmigUp($m);
        if ($isMySql) {
            PayslipIncentive::query()->delete();
            PayrollRunMemberIncentive::query()->delete();
            PayrollIncentiveType::query()->delete();
            Payslip::query()->where('run_id', $run->id ?? '')->delete();
            PayrollRun::query()->whereKey($run->id ?? '')->delete();
        }
    }
});

it('runs no DDL on an up() that finds every table complete', function () {
    $m = incmigMigrations();

    try {
        incmigAssertApplied(true);

        $ddl = incmigDdlDuring(static fn () => incmigUp($m));

        // sqlite: nothing. MySQL/MariaDB: only each table's idempotent ROW_FORMAT restatement.
        $expected = in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)
            ? ['ALTER TABLE `payroll_incentive_types` ROW_FORMAT=DYNAMIC', 'ALTER TABLE `payroll_run_member_incentives` ROW_FORMAT=DYNAMIC', 'ALTER TABLE `payslip_incentives` ROW_FORMAT=DYNAMIC']
            : [];
        expect($ddl)->toBe($expected);
    } finally {
        incmigUp($m);
    }
});
