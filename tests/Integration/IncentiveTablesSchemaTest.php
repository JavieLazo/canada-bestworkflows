<?php

declare(strict_types=1);

use App\Models\EmployeeProfile;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * On the production engine, the incentive tables (2026_09_26_0007-0009) are
 * retry-safe step by step: their CHECKs exist exactly once and refuse a
 * non-positive amount and an over-ceiling part that does not fit the line;
 * a type a payroll line names cannot be deleted (RESTRICT) while the lines
 * go with their member and a posted line with its payslip (CASCADE); and a
 * run killed between statements resumes with only the missing piece.
 *
 * sqlite creates no CHECK, so only this suite sees them. DatabaseMigrations
 * rolls the whole schema back after each test, so EVERY TEST ENDS MIGRATED.
 */

/** @return array{types: mixed, lines: mixed, posted: mixed} */
function incschemaMigrations(): array
{
    return [
        'types' => require base_path('database/migrations/2026_09_26_0007_create_payroll_incentive_types_table.php'),
        'lines' => require base_path('database/migrations/2026_09_26_0008_create_payroll_run_member_incentives_table.php'),
        'posted' => require base_path('database/migrations/2026_09_26_0009_create_payslip_incentives_table.php'),
    ];
}

function incschemaChecks(string $table, string $name): int
{
    $row = DB::selectOne(
        "SELECT COUNT(*) AS n FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ? AND CONSTRAINT_TYPE = 'CHECK'",
        [$table, $name],
    );

    return is_object($row) && isset($row->n) ? (int) $row->n : 0;
}

/**
 * Every CREATE, ALTER or DROP the callback sends, in order.
 *
 * @return list<string>
 */
function incschemaDdlDuring(callable $callback): array
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

function incschemaAssertChecks(): void
{
    expect(incschemaChecks('payroll_run_member_incentives', 'prmi_amount_chk'))->toBe(1)
        ->and(incschemaChecks('payslip_incentives', 'psi_amount_chk'))->toBe(1)
        ->and(incschemaChecks('payslip_incentives', 'psi_over_ceiling_chk'))->toBe(1);
}

/** @return array{type: string, member: string, payslip: string} ids of one type, one DRAFT member and one payslip */
function incschemaRows(): array
{
    $employee = new EmployeeProfile;
    $employee->forceFill(['employee_code' => 'INS-'.Str::upper(Str::random(5)), 'first_name' => 'Inc', 'last_name' => 'Schema',
        'employment_status' => 'Regular', 'date_hired' => '2024-01-01', 'is_active' => true])->save();
    $now = '2026-09-26 01:00:00.000';
    $type = (string) Str::ulid();
    DB::table('payroll_incentive_types')->insert(['id' => $type, 'code' => '1', 'name' => 'Christmas Bonus', 'description' => null,
        'is_taxable' => 0, 'created_at' => $now, 'updated_at' => null]);
    $run = (string) Str::ulid();
    DB::table('payroll_runs')->insert(['id' => $run, 'code' => 'PR-INS', 'name' => 'Schema run', 'period_start' => '2026-08-01',
        'period_end' => '2026-08-15', 'status' => 'DRAFT', 'created_at' => $now, 'updated_at' => null]);
    $member = (string) Str::ulid();
    DB::table('payroll_run_members')->insert(['id' => $member, 'run_id' => $run, 'employee_id' => $employee->id, 'position' => 0,
        'period_start' => '2026-08-01', 'period_end' => '2026-08-15', 'created_at' => $now, 'updated_at' => null]);
    $payslip = (string) Str::ulid();
    DB::table('payslips')->insert(['id' => $payslip, 'run_id' => $run, 'employee_id' => $employee->id, 'period_start' => '2026-08-01',
        'period_end' => '2026-08-15', 'created_at' => $now]);

    return ['type' => $type, 'member' => $member, 'payslip' => $payslip];
}

function incschemaLine(string $member, string $type, float $amount): void
{
    DB::table('payroll_run_member_incentives')->insert(['id' => (string) Str::ulid(), 'member_id' => $member, 'type_id' => $type,
        'amount' => $amount, 'created_at' => '2026-09-26 01:00:00.000']);
}

function incschemaPosted(string $payslip, bool $taxable, float $amount, float $over): void
{
    DB::table('payslip_incentives')->insert(['id' => (string) Str::ulid(), 'payslip_id' => $payslip, 'position' => 0, 'type_code' => '1',
        'name' => 'Christmas Bonus', 'is_taxable' => $taxable ? 1 : 0, 'amount' => $amount, 'over_ceiling' => $over,
        'created_at' => '2026-09-26 01:00:00.000']);
}

it('applies the three migrations twice on top of a migrated schema, with each CHECK exactly once', function () {
    expect(in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true))->toBeTrue();
    incschemaAssertChecks();
    $m = incschemaMigrations();

    $ddl = incschemaDdlDuring(static function () use ($m): void {
        foreach ([1, 2] as $run) {
            $m['types']->up();
            $m['lines']->up();
            $m['posted']->up();
        }
    });

    expect(array_values(array_unique($ddl)))->toBe([
        'ALTER TABLE `payroll_incentive_types` ROW_FORMAT=DYNAMIC',
        'ALTER TABLE `payroll_run_member_incentives` ROW_FORMAT=DYNAMIC',
        'ALTER TABLE `payslip_incentives` ROW_FORMAT=DYNAMIC',
    ])->and($ddl)->toHaveCount(6);
    incschemaAssertChecks();
});

it('refuses amounts and over-ceiling parts outside the constraints', function () {
    ['type' => $type, 'member' => $member, 'payslip' => $payslip] = incschemaRows();

    expect(fn () => incschemaLine($member, $type, 0))->toThrow(QueryException::class, 'prmi_amount_chk');
    expect(fn () => incschemaLine($member, $type, -1))->toThrow(QueryException::class, 'prmi_amount_chk');
    incschemaLine($member, $type, 100.5);
    expect(fn () => incschemaLine($member, $type, 50))->toThrow(QueryException::class, 'prmi_member_type_unq');

    expect(fn () => incschemaPosted($payslip, false, 0, 0))->toThrow(QueryException::class, 'psi_amount_chk');
    expect(fn () => incschemaPosted($payslip, false, 100, 101))->toThrow(QueryException::class, 'psi_over_ceiling_chk');
    expect(fn () => incschemaPosted($payslip, false, 100, -1))->toThrow(QueryException::class, 'psi_over_ceiling_chk');
    // A taxable line has nothing over the ceiling.
    expect(fn () => incschemaPosted($payslip, true, 100, 10))->toThrow(QueryException::class, 'psi_over_ceiling_chk');
    incschemaPosted($payslip, false, 100, 40);
    incschemaPosted($payslip, true, 100, 0);
    expect(DB::table('payslip_incentives')->count())->toBe(2);
});

it('keeps a type a payroll line names, and lets the lines go with their member and payslip', function () {
    ['type' => $type, 'member' => $member, 'payslip' => $payslip] = incschemaRows();
    incschemaLine($member, $type, 100);
    incschemaPosted($payslip, false, 100, 0);

    // RESTRICT: MySQL error 1451.
    try {
        DB::table('payroll_incentive_types')->where('id', $type)->delete();
        test()->fail('Deleting a type a payroll line names must be refused by the foreign key.');
    } catch (QueryException $e) {
        expect((int) ($e->errorInfo[1] ?? 0))->toBe(1451);
    }

    DB::table('payroll_run_members')->where('id', $member)->delete();
    DB::table('payslips')->where('id', $payslip)->delete();
    expect(DB::table('payroll_run_member_incentives')->count())->toBe(0)
        ->and(DB::table('payslip_incentives')->count())->toBe(0);

    // With no line naming it, the type can go.
    DB::table('payroll_incentive_types')->where('id', $type)->delete();
    expect(DB::table('payroll_incentive_types')->count())->toBe(0);
});

it('resumes each migration from a run killed between its statements', function () {
    $m = incschemaMigrations();

    // The line table, stripped back to its CREATE: FKs, then indexes, then the CHECK.
    DB::statement('ALTER TABLE `payroll_run_member_incentives` DROP FOREIGN KEY `payroll_run_member_incentives_member_id_foreign`');
    DB::statement('ALTER TABLE `payroll_run_member_incentives` DROP FOREIGN KEY `payroll_run_member_incentives_type_id_foreign`');
    DB::statement('ALTER TABLE `payroll_run_member_incentives` DROP INDEX `prmi_member_type_unq`');
    DB::statement('ALTER TABLE `payroll_run_member_incentives` DROP INDEX `prmi_type_idx`');
    DB::statement('ALTER TABLE `payroll_run_member_incentives` DROP CONSTRAINT `prmi_amount_chk`');

    // The posted table, the same way.
    DB::statement('ALTER TABLE `payslip_incentives` DROP FOREIGN KEY `payslip_incentives_payslip_id_foreign`');
    DB::statement('ALTER TABLE `payslip_incentives` DROP INDEX `psi_payslip_idx`');
    DB::statement('ALTER TABLE `payslip_incentives` DROP CONSTRAINT `psi_amount_chk`');
    DB::statement('ALTER TABLE `payslip_incentives` DROP CONSTRAINT `psi_over_ceiling_chk`');

    // The catalog, killed after its CREATE.
    DB::statement('ALTER TABLE `payroll_incentive_types` DROP INDEX `pit_code_unq`');
    DB::statement('ALTER TABLE `payroll_incentive_types` DROP INDEX `pit_name_unq`');

    $m['types']->up();
    $m['lines']->up();
    $m['posted']->up();

    expect(Schema::hasIndex('payroll_incentive_types', 'pit_code_unq', 'unique'))->toBeTrue()
        ->and(Schema::hasIndex('payroll_incentive_types', 'pit_name_unq', 'unique'))->toBeTrue()
        ->and(Schema::hasIndex('payroll_run_member_incentives', 'prmi_member_type_unq', 'unique'))->toBeTrue()
        ->and(Schema::hasForeignKey('payroll_run_member_incentives', ['member_id']))->toBeTrue()
        ->and(Schema::hasForeignKey('payroll_run_member_incentives', ['type_id']))->toBeTrue()
        ->and(Schema::hasForeignKey('payslip_incentives', ['payslip_id']))->toBeTrue();
    incschemaAssertChecks();
});
