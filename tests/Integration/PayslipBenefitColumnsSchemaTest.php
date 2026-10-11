<?php

declare(strict_types=1);

use App\Models\EmployeeProfile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * On the production engine, the PHP 90,000 ledger columns on `payslips`
 * (2026_09_26_0004) are three retry-safe ALTERs: re-running the migration on
 * a migrated schema sends no DDL, each ALTER is appended without AFTER and is
 * accepted with ALGORITHM=INSTANT (so a growing append-only table is never
 * copied), a run killed between two of them resumes with only the missing
 * ones, and a full rollback removes all three while every posted payslip
 * survives with its amounts. sqlite rebuilds tables for a column drop, so
 * only this suite sees the real statements. DatabaseMigrations rolls the
 * whole schema back after each test, so EVERY TEST ENDS MIGRATED.
 */
function psbcschemaMigration(): mixed
{
    return require base_path('database/migrations/2026_09_26_0004_add_benefit_columns_to_payslips_table.php');
}

/**
 * Every CREATE, ALTER or DROP the callback sends, in order.
 *
 * @return list<string>
 */
function psbcschemaDdlDuring(callable $callback): array
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

/**
 * The three columns as information_schema describes them, keyed by name.
 *
 * @return array<string, array{type: string, nullable: string, default: mixed}>
 */
function psbcschemaColumns(): array
{
    $rows = DB::select(
        "SELECT COLUMN_NAME AS name, COLUMN_TYPE AS type, IS_NULLABLE AS nullable, COLUMN_DEFAULT AS dflt FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payslips' AND COLUMN_NAME IN ('exempt_benefits', 'taxable_benefits_excess', 'taxable_compensation')",
    );
    $columns = [];
    foreach ($rows as $row) {
        $columns[(string) $row->name] = ['type' => strtolower((string) $row->type), 'nullable' => (string) $row->nullable, 'default' => $row->dflt];
    }
    ksort($columns);

    return $columns;
}

function psbcschemaAssertApplied(): void
{
    $columns = psbcschemaColumns();
    expect(array_keys($columns))->toBe(['exempt_benefits', 'taxable_benefits_excess', 'taxable_compensation']);
    foreach (['exempt_benefits', 'taxable_benefits_excess'] as $name) {
        expect($columns[$name]['type'])->toBe('decimal(12,2)')
            ->and($columns[$name]['nullable'])->toBe('NO')
            ->and((float) $columns[$name]['default'])->toBe(0.0);
    }
    expect($columns['taxable_compensation']['type'])->toBe('decimal(12,2)')
        ->and($columns['taxable_compensation']['nullable'])->toBe('YES')
        ->and($columns['taxable_compensation']['default'] === null || $columns['taxable_compensation']['default'] === 'NULL')->toBeTrue();
}

/** A posted payslip written straight to the table, without the ledger columns. */
function psbcschemaPayslip(): string
{
    $employee = new EmployeeProfile;
    $employee->forceFill([
        'employee_code' => 'PSBCS-'.Str::upper(Str::random(5)),
        'first_name' => 'Ledger',
        'last_name' => 'Schema',
        'employment_status' => 'Regular',
        'date_hired' => '2024-01-01',
        'monthly_rate' => 30000,
        'is_active' => true,
    ])->save();

    $id = (string) Str::ulid();
    DB::table('payslips')->insert([
        'id' => $id,
        'employee_id' => $employee->getKey(),
        'period_start' => '2026-08-01',
        'period_end' => '2026-08-15',
        'gross_pay' => 15000.00,
        'total_deductions' => 1234.56,
        'net_pay' => 13765.44,
        'created_at' => '2026-08-16 01:00:00.000',
    ]);

    return $id;
}

it('applies the migration twice on top of a migrated schema without sending any DDL', function () {
    expect(in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true))->toBeTrue();
    psbcschemaAssertApplied();
    $migration = psbcschemaMigration();

    expect(psbcschemaDdlDuring(static function () use ($migration): void {
        $migration->up();
        $migration->up();
    }))->toBe([]);

    psbcschemaAssertApplied();
});

it('adds each column in its own ALTER with no AFTER clause, which MariaDB runs instantly', function () {
    $migration = psbcschemaMigration();
    $migration->down();
    expect(psbcschemaColumns())->toBe([]);

    $ddl = psbcschemaDdlDuring(static fn () => $migration->up());

    expect($ddl)->toBe([
        "alter table `payslips` add `exempt_benefits` decimal(12, 2) not null default '0'",
        "alter table `payslips` add `taxable_benefits_excess` decimal(12, 2) not null default '0'",
        'alter table `payslips` add `taxable_compensation` decimal(12, 2) null',
    ]);
    psbcschemaAssertApplied();

    // The same statements with ALGORITHM=INSTANT are refused outright where
    // the engine would have to copy the table, so this proves each ADD is instant.
    $migration->down();
    foreach ($ddl as $statement) {
        DB::statement($statement.', ALGORITHM=INSTANT');
    }
    psbcschemaAssertApplied();
});

it('resumes a run killed between two of its ALTERs with only the missing columns', function () {
    $migration = psbcschemaMigration();

    // Killed after the first ALTER: exempt_benefits is there, the other two are not.
    DB::statement('ALTER TABLE `payslips` DROP COLUMN `taxable_compensation`');
    DB::statement('ALTER TABLE `payslips` DROP COLUMN `taxable_benefits_excess`');

    $ddl = psbcschemaDdlDuring(static fn () => $migration->up());
    expect($ddl)->toHaveCount(2)
        ->and($ddl[0])->toContain('`taxable_benefits_excess`')
        ->and($ddl[1])->toContain('`taxable_compensation`');
    psbcschemaAssertApplied();
});

it('removes all three on a full rollback, tolerates a second one, and restores them with every payslip intact', function () {
    $payslip = psbcschemaPayslip();
    DB::table('payslips')->where('id', $payslip)->update(['exempt_benefits' => 5000.25, 'taxable_benefits_excess' => 10.5, 'taxable_compensation' => 12000]);
    $migration = psbcschemaMigration();

    $migration->down();
    expect(psbcschemaColumns())->toBe([]);
    expect(psbcschemaDdlDuring(static fn () => $migration->down()))->toBe([]);

    // The payslip and its posted amounts are untouched by the drop.
    $row = DB::table('payslips')->where('id', $payslip)->first();
    expect($row)->not->toBeNull()
        ->and((string) $row?->net_pay)->toBe('13765.44')
        ->and((string) $row?->total_deductions)->toBe('1234.56');

    $migration->up();
    psbcschemaAssertApplied();

    // ROLLBACK LOSES the ledger: the restored columns read their defaults.
    $row = DB::table('payslips')->where('id', $payslip)->first();
    expect((float) $row?->exempt_benefits)->toBe(0.0)
        ->and((float) $row?->taxable_benefits_excess)->toBe(0.0)
        ->and($row?->taxable_compensation)->toBeNull()
        ->and((string) $row?->net_pay)->toBe('13765.44');
});
