<?php

declare(strict_types=1);

use App\Models\EmployeeProfile;
use App\Models\Payslip;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The benefit-columns migration (2026_09_26_0004) rolls back and re-applies
 * cleanly and survives a repeated up() or down() — the cPanel cron re-running
 * a half-applied deploy. The three columns come back with the shape the
 * ledger relies on: exempt_benefits and taxable_benefits_excess NOT NULL
 * DEFAULT 0, taxable_compensation nullable with no default. A payslip posted
 * before them reads 0, 0 and null, and ROLLBACK LOSES the figures a payslip
 * carried (the migration's docblock says so; this pins it).
 *
 * THIS FILE ALWAYS ENDS MIGRATED, pass or fail: each `finally` re-runs up()
 * first, then deletes the rows seeded here by key (on MySQL/MariaDB the DDL
 * commits RefreshDatabase's transaction, which then cannot roll them back).
 * `payslips` has no child table, so the sqlite rebuild a column drop causes
 * cascades into nothing.
 */

/** The migration under test — an anonymous class, so untyped here. */
function psbcmigMigration(): mixed
{
    return require base_path('database/migrations/2026_09_26_0004_add_benefit_columns_to_payslips_table.php');
}

/** @return array{employee: string, payslip: string} */
function psbcmigSeed(string $suffix, float $exempt): array
{
    $employee = new EmployeeProfile;
    $employee->forceFill([
        'employee_code' => 'PSBC-'.$suffix,
        'first_name' => 'Benefit',
        'last_name' => 'Column '.$suffix,
        'employment_status' => 'Regular',
        'date_hired' => '2024-01-01',
        'monthly_rate' => 30000,
        'is_active' => true,
    ])->save();

    $payslip = new Payslip;
    $payslip->forceFill([
        'employee_id' => $employee->getKey(),
        'period_start' => '2026-08-01',
        'period_end' => '2026-08-15',
        'gross_pay' => 15_000,
        'total_deductions' => 0,
        'net_pay' => 15_000,
    ] + (Schema::hasColumn('payslips', 'exempt_benefits') ? ['exempt_benefits' => $exempt, 'taxable_benefits_excess' => 12.34, 'taxable_compensation' => 14_000.0] : []))->save();

    return ['employee' => (string) $employee->getKey(), 'payslip' => (string) $payslip->getKey()];
}

/** @param array<string, array{employee: string, payslip: string}> $seeded */
function psbcmigCleanup(array $seeded): void
{
    if (! in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
        return;
    }
    foreach ($seeded as $rows) {
        Payslip::query()->whereKey($rows['payslip'])->delete();
        EmployeeProfile::query()->whereKey($rows['employee'])->delete();
    }
}

/**
 * A payslips column as the schema reports it, or null when absent.
 *
 * @return array<string, mixed>|null
 */
function psbcmigColumn(string $name): ?array
{
    foreach (Schema::getColumns('payslips') as $column) {
        if ($column['name'] === $name) {
            return $column;
        }
    }

    return null;
}

it('rolls the three columns back and re-applies them twice, leaving an older payslip at 0, 0 and null', function () {
    $migration = psbcmigMigration();
    $seeded = [];

    try {
        $seeded['posted'] = psbcmigSeed('A', 5_000.0);
        expect((float) DB::table('payslips')->where('id', $seeded['posted']['payslip'])->value('exempt_benefits'))->toBe(5_000.0);

        $migration->down();
        foreach (['exempt_benefits', 'taxable_benefits_excess', 'taxable_compensation'] as $column) {
            expect(Schema::hasColumn('payslips', $column))->toBeFalse();
        }

        // A repeated rollback finds nothing to drop and does nothing.
        $migration->down();

        $migration->up();
        // The retry: every column already exists, so every step is skipped.
        $migration->up();

        foreach (['exempt_benefits', 'taxable_benefits_excess'] as $name) {
            $column = psbcmigColumn($name);
            expect($column)->not->toBeNull()
                ->and($column['nullable'])->toBeFalse()
                ->and((float) trim((string) $column['default'], "'"))->toBe(0.0);
        }
        $base = psbcmigColumn('taxable_compensation');
        expect($base)->not->toBeNull()
            ->and($base['nullable'])->toBeTrue()
            ->and(in_array($base['default'], [null, 'NULL'], true))->toBeTrue();

        // ROLLBACK LOSES the figures: the posted payslip now reads the
        // defaults, and the rest of the row is untouched.
        $row = Payslip::query()->findOrFail($seeded['posted']['payslip']);
        expect($row->exempt_benefits)->toBe(0.0)
            ->and($row->taxable_benefits_excess)->toBe(0.0)
            ->and($row->taxable_compensation)->toBeNull()
            ->and($row->net_pay)->toBe(15_000.0);

        // And the columns take their figures again once they are back.
        $row->forceFill(['exempt_benefits' => 1_500.25, 'taxable_benefits_excess' => 99.75, 'taxable_compensation' => 13_250.5])->save();
        $fresh = $row->fresh();
        expect($fresh?->exempt_benefits)->toBe(1_500.25)
            ->and($fresh?->taxable_benefits_excess)->toBe(99.75)
            ->and($fresh?->taxable_compensation)->toBe(13_250.5);
    } finally {
        // Re-migrate FIRST, even when an expectation failed between down()
        // and up(). Every step is guarded, so a passing run makes this a no-op.
        $migration->up();
        psbcmigCleanup($seeded);
    }
});

it('runs no DDL on an up() that finds every column already there', function () {
    $migration = psbcmigMigration();

    try {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $migration->up();
        DB::disableQueryLog();

        $ddl = array_values(array_filter(
            array_column(DB::getQueryLog(), 'query'),
            static fn (string $sql): bool => preg_match('/^\s*(create|alter|drop)\b/i', $sql) === 1,
        ));
        expect($ddl)->toBe([]);
    } finally {
        $migration->up();
    }
});
