<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * The salary versions migration on MariaDB (2026_11_02_0008): each
 * employee with a rate is seeded one approved version once, a version's
 * status and stamp agree, its rates are not negative and one of them is
 * set, the employee key keeps the version, and the migration rolls back
 * and runs twice.
 */
function salaryRatesMigration(): mixed
{
    return require base_path('database/migrations/2026_11_02_0008_create_employee_salary_rates_table.php');
}

/** @return array<string, mixed> */
function salaryRatesRow(string $employeeId, string $status, ?float $monthly, ?float $daily, ?string $approvedAt): array
{
    return ['id' => (string) Str::ulid(), 'employee_id' => $employeeId, 'effective_from' => '2026-11-10', 'monthly_rate' => $monthly, 'daily_rate' => $daily,
        'reason' => 'Synthetic', 'reference' => null, 'status' => $status, 'prepared_by' => null, 'prepared_by_name' => null,
        'approved_by' => null, 'approved_by_name' => null, 'approved_at' => $approvedAt, 'review_note' => null,
        'created_at' => now('UTC')->format('Y-m-d H:i:s.v'), 'updated_at' => null];
}

it('seeds one version per employee with a rate, keeps a version consistent and retains the employee', function () {
    expect(in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true))->toBeTrue();
    $employeeId = (string) Str::ulid();
    DB::table('employee_profiles')->insert(['id' => $employeeId, 'employee_code' => 'ESR-INT', 'first_name' => 'Synthetic', 'last_name' => 'Salary', 'date_hired' => '2024-01-01',
        'monthly_rate' => 30000, 'is_active' => true, 'created_at' => now('UTC')->format('Y-m-d H:i:s.v'), 'updated_at' => null]);
    $migration = salaryRatesMigration();
    $migration->up();
    $migration->up();
    expect(DB::table('employee_salary_rates')->where('employee_id', $employeeId)->count())->toBe(1)
        ->and(DB::table('employee_salary_rates')->where('employee_id', $employeeId)->value('effective_from'))->toBe('2024-01-01')
        ->and((float) DB::table('employee_salary_rates')->where('employee_id', $employeeId)->value('monthly_rate'))->toBe(30000.0);

    $stamp = now('UTC')->format('Y-m-d H:i:s.v');
    DB::table('employee_salary_rates')->insert(salaryRatesRow($employeeId, 'PENDING', 31000.0, null, null));
    DB::table('employee_salary_rates')->insert(salaryRatesRow($employeeId, 'APPROVED', null, 1500.0, $stamp));
    expect(fn () => DB::table('employee_salary_rates')->insert(salaryRatesRow($employeeId, 'PENDING', 31000.0, null, $stamp)))->toThrow(QueryException::class)
        ->and(fn () => DB::table('employee_salary_rates')->insert(salaryRatesRow($employeeId, 'APPROVED', 31000.0, null, null)))->toThrow(QueryException::class)
        ->and(fn () => DB::table('employee_salary_rates')->insert(salaryRatesRow($employeeId, 'DONE', 31000.0, null, $stamp)))->toThrow(QueryException::class)
        ->and(fn () => DB::table('employee_salary_rates')->insert(salaryRatesRow($employeeId, 'APPROVED', -1.0, null, $stamp)))->toThrow(QueryException::class)
        ->and(fn () => DB::table('employee_salary_rates')->insert(salaryRatesRow($employeeId, 'APPROVED', null, null, $stamp)))->toThrow(QueryException::class)
        ->and(fn () => DB::table('employee_profiles')->where('id', $employeeId)->delete())->toThrow(QueryException::class)
        ->and(DB::table('employee_salary_rates')->where('employee_id', $employeeId)->count())->toBe(3);
});

it('rolls back the table and runs again on a bare table', function () {
    expect(in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true))->toBeTrue();
    $migration = salaryRatesMigration();
    $migration->up();
    $migration->down();
    expect(Schema::hasTable('employee_salary_rates'))->toBeFalse();

    Schema::create('employee_salary_rates', static function ($t): void {
        $t->char('id', 26)->primary();
        $t->char('employee_id', 26);
        $t->date('effective_from');
        $t->decimal('monthly_rate', 12, 2)->nullable();
        $t->decimal('daily_rate', 12, 2)->nullable();
        $t->string('reason', 160);
        $t->string('reference', 120)->nullable();
        $t->string('status', 10);
        $t->unsignedBigInteger('prepared_by')->nullable();
        $t->string('prepared_by_name', 200)->nullable();
        $t->unsignedBigInteger('approved_by')->nullable();
        $t->string('approved_by_name', 200)->nullable();
        $t->dateTime('approved_at', 3)->nullable();
        $t->string('review_note', 500)->nullable();
        $t->dateTime('created_at', 3);
        $t->dateTime('updated_at', 3)->nullable();
    });
    $migration->up();
    $migration->up();
    expect(Schema::hasIndex('employee_salary_rates', 'esr_employee_from_idx'))->toBeTrue()
        ->and(Schema::hasForeignKey('employee_salary_rates', ['employee_id']))->toBeTrue();
});
