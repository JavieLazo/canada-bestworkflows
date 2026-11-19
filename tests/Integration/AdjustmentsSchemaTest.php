<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * The adjustment ledger migration on MariaDB (2026_11_02_0005): the
 * starting categories are seeded once, a category's tax agrees with its
 * kind, a line's amount is positive, its status and stamps agree and only
 * a POSTED line names a payslip, a posted line's adjustment and category
 * cannot go, and the migration rolls back and resumes from any step.
 */
function adjustmentsMigration(): mixed
{
    return require base_path('database/migrations/2026_11_02_0005_create_payroll_adjustments_tables.php');
}

/** @return array<string, mixed> */
function adjustmentsCategoryRow(string $kind, ?string $tax, string $name): array
{
    return ['id' => (string) Str::ulid(), 'name' => $name, 'kind' => $kind, 'tax' => $tax, 'created_by' => null, 'created_by_name' => null,
        'created_at' => now('UTC')->format('Y-m-d H:i:s.v'), 'updated_at' => null];
}

/** @return array<string, mixed> */
function adjustmentsLineRow(string $employeeId, string $categoryId, float $amount, string $status, ?string $approvedAt, ?string $payslipId = null): array
{
    return ['id' => (string) Str::ulid(), 'employee_id' => $employeeId, 'category_id' => $categoryId, 'amount' => $amount, 'reason' => 'Synthetic', 'reference' => null,
        'source' => 'MANUAL', 'status' => $status, 'target_run_id' => null, 'payslip_id' => $payslipId, 'prepared_by' => null, 'prepared_by_name' => null,
        'approved_by' => null, 'approved_by_name' => null, 'approved_at' => $approvedAt, 'review_note' => null,
        'created_at' => now('UTC')->format('Y-m-d H:i:s.v'), 'updated_at' => null];
}

it('seeds the categories once and keeps a category, a line and its posting consistent', function () {
    expect(in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true))->toBeTrue();
    $migration = adjustmentsMigration();
    $migration->up();
    $migration->up();
    expect(DB::table('payroll_adjustment_categories')->count())->toBe(6);

    expect(fn () => DB::table('payroll_adjustment_categories')->insert(adjustmentsCategoryRow('EARNING', null, 'No tax')))->toThrow(QueryException::class)
        ->and(fn () => DB::table('payroll_adjustment_categories')->insert(adjustmentsCategoryRow('DEDUCTION', 'TAXABLE', 'Taxed deduction')))->toThrow(QueryException::class)
        ->and(fn () => DB::table('payroll_adjustment_categories')->insert(adjustmentsCategoryRow('GIFT', null, 'Gift')))->toThrow(QueryException::class);

    $employeeId = (string) Str::ulid();
    DB::table('employee_profiles')->insert(['id' => $employeeId, 'employee_code' => 'ADJ-INT', 'first_name' => 'Synthetic', 'last_name' => 'Adjustment', 'date_hired' => '2024-01-01',
        'is_active' => true, 'created_at' => now('UTC')->format('Y-m-d H:i:s.v'), 'updated_at' => null]);
    $categoryId = (string) DB::table('payroll_adjustment_categories')->where('name', 'Tax refund')->value('id');
    $stamp = now('UTC')->format('Y-m-d H:i:s.v');
    DB::table('payroll_adjustments')->insert(adjustmentsLineRow($employeeId, $categoryId, 100.0, 'PENDING', null));
    DB::table('payroll_adjustments')->insert(adjustmentsLineRow($employeeId, $categoryId, 100.0, 'APPROVED', $stamp));
    expect(fn () => DB::table('payroll_adjustments')->insert(adjustmentsLineRow($employeeId, $categoryId, 0.0, 'PENDING', null)))->toThrow(QueryException::class)
        ->and(fn () => DB::table('payroll_adjustments')->insert(adjustmentsLineRow($employeeId, $categoryId, 100.0, 'PENDING', $stamp)))->toThrow(QueryException::class)
        ->and(fn () => DB::table('payroll_adjustments')->insert(adjustmentsLineRow($employeeId, $categoryId, 100.0, 'APPROVED', null)))->toThrow(QueryException::class)
        ->and(fn () => DB::table('payroll_adjustments')->insert(adjustmentsLineRow($employeeId, $categoryId, 100.0, 'POSTED', $stamp, null)))->toThrow(QueryException::class)
        ->and(fn () => DB::table('payroll_adjustments')->insert(adjustmentsLineRow($employeeId, $categoryId, 100.0, 'DONE', $stamp)))->toThrow(QueryException::class)
        // The lines keep the category and the employee.
        ->and(fn () => DB::table('payroll_adjustment_categories')->where('id', $categoryId)->delete())->toThrow(QueryException::class)
        ->and(fn () => DB::table('employee_profiles')->where('id', $employeeId)->delete())->toThrow(QueryException::class)
        ->and(DB::table('payroll_adjustments')->count())->toBe(2);
});

it('rolls back the ledger and the posted-line columns, and resumes from a partial run', function () {
    expect(in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true))->toBeTrue();
    $migration = adjustmentsMigration();
    $migration->up();

    $migration->down();
    expect(Schema::hasTable('payroll_adjustments'))->toBeFalse()
        ->and(Schema::hasTable('payroll_adjustment_categories'))->toBeFalse()
        ->and(Schema::hasColumn('payslip_incentives', 'adjustment_id'))->toBeFalse()
        ->and(Schema::hasColumn('payslip_deductions', 'adjustment_id'))->toBeFalse();

    // A run stopped after the categories table, before the rest, resumes.
    Schema::create('payroll_adjustment_categories', static function ($t): void {
        $t->char('id', 26)->primary();
        $t->string('name', 120);
        $t->string('kind', 10);
        $t->string('tax', 10)->nullable();
        $t->unsignedBigInteger('created_by')->nullable();
        $t->string('created_by_name', 200)->nullable();
        $t->dateTime('created_at', 3);
        $t->dateTime('updated_at', 3)->nullable();
    });
    $migration->up();
    $migration->up();
    expect(Schema::hasIndex('payroll_adjustment_categories', 'pac_name_unq'))->toBeTrue()
        ->and(Schema::hasTable('payroll_adjustments'))->toBeTrue()
        ->and(Schema::hasForeignKey('payroll_adjustments', ['payslip_id']))->toBeTrue()
        ->and(Schema::hasForeignKey('payslip_deductions', ['adjustment_id']))->toBeTrue()
        ->and(DB::table('payroll_adjustment_categories')->count())->toBe(6);
});

it('refuses an earning category with no tax once the CHECK spells it out, settles a stored one first and resumes from a partial run', function () {
    expect(in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true))->toBeTrue();
    $migration = require base_path('database/migrations/2026_11_19_0001_require_tax_on_earning_adjustment_categories_table.php');
    $clause = static fn (): string => strtolower((string) DB::table('information_schema.CHECK_CONSTRAINTS')
        ->whereRaw('CONSTRAINT_SCHEMA = DATABASE()')->where('TABLE_NAME', 'payroll_adjustment_categories')
        ->where('CONSTRAINT_NAME', 'pac_tax_chk')->value('CHECK_CLAUSE'));
    $checks = static fn (): int => DB::table('information_schema.TABLE_CONSTRAINTS')
        ->whereRaw('CONSTRAINT_SCHEMA = DATABASE()')->where('TABLE_NAME', 'payroll_adjustment_categories')
        ->where('CONSTRAINT_NAME', 'pac_tax_chk')->where('CONSTRAINT_TYPE', 'CHECK')->count();
    $ddlDuring = static function (callable $callback): array {
        DB::flushQueryLog();
        DB::enableQueryLog();
        try {
            $callback();
        } finally {
            DB::disableQueryLog();
        }

        return array_values(array_filter(array_column(DB::getQueryLog(), 'query'), static fn (string $sql): bool => preg_match('/^\s*(create|alter|drop)\b/i', $sql) === 1));
    };

    // On the migrated schema the CHECK refuses the row, and a re-run sends no DDL.
    expect(fn () => DB::table('payroll_adjustment_categories')->insert(adjustmentsCategoryRow('EARNING', null, 'No tax')))->toThrow(QueryException::class, 'pac_tax_chk')
        ->and($clause())->toContain('is not null')
        ->and($ddlDuring(static fn () => $migration->up()))->toBe([]);

    // Rolled back, the earlier wording lets the row in: the gap this migration closes.
    $migration->down();
    $migration->down();
    expect($clause())->not->toContain('is not null')->and($checks())->toBe(1);
    DB::table('payroll_adjustment_categories')->insert(adjustmentsCategoryRow('EARNING', null, 'No tax'));

    // Up again: the stored row is written down as payroll read it, and only then can the CHECK return.
    $migration->up();
    expect(DB::table('payroll_adjustment_categories')->where('name', 'No tax')->value('tax'))->toBe('NOT_TAXED')
        ->and($clause())->toContain('is not null')
        ->and(fn () => DB::table('payroll_adjustment_categories')->insert(adjustmentsCategoryRow('EARNING', null, 'Still no tax')))->toThrow(QueryException::class, 'pac_tax_chk');

    // A run killed between the drop and the add resumes at the add.
    DB::statement('ALTER TABLE `payroll_adjustment_categories` DROP CONSTRAINT `pac_tax_chk`');
    $ddl = $ddlDuring(static fn () => $migration->up());
    expect($ddl)->toHaveCount(1)->and($ddl[0])->toStartWith('ALTER TABLE `payroll_adjustment_categories` ADD CONSTRAINT `pac_tax_chk` CHECK')
        ->and($checks())->toBe(1);

    // What was valid stays valid, and the other half of the rule still holds.
    DB::table('payroll_adjustment_categories')->insert(adjustmentsCategoryRow('EARNING', 'TAXABLE', 'Taxed earning'));
    DB::table('payroll_adjustment_categories')->insert(adjustmentsCategoryRow('DEDUCTION', null, 'Plain deduction'));
    expect(fn () => DB::table('payroll_adjustment_categories')->insert(adjustmentsCategoryRow('DEDUCTION', 'TAXABLE', 'Taxed deduction')))->toThrow(QueryException::class, 'pac_tax_chk')
        ->and(DB::table('payroll_adjustment_categories')->count())->toBe(9);
});
