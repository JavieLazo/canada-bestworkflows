<?php

declare(strict_types=1);

use App\Models\PayrollStatutoryTable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * The Contribution & Tax Tables migration on MariaDB: it resumes from any
 * step, seeds each kind's built-in version once, and its CHECKs, unique
 * index and foreign key hold.
 */
function statutoryTablesMigration(): mixed
{
    return require base_path('database/migrations/2026_10_12_0001_create_payroll_statutory_tables_table.php');
}

it('creates the table once, seeds one built-in version per kind and resumes from a partial run', function () {
    expect(in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true))->toBeTrue();
    $migration = statutoryTablesMigration();

    $migration->up();
    $migration->up();
    expect(PayrollStatutoryTable::query()->count())->toBe(4)
        ->and(PayrollStatutoryTable::query()->pluck('kind')->sort()->values()->all())->toBe(['PAGIBIG', 'PHILHEALTH', 'SSS', 'WITHHOLDING_TAX'])
        ->and(PayrollStatutoryTable::query()->where('kind', 'SSS')->sole()->params)->toEqual(['msc_min' => 5000.0, 'msc_max' => 35000.0, 'step' => 500.0, 'offset' => 250.0, 'ee_rate' => 0.05, 'er_rate' => 0.1, 'ec_threshold' => 14500.0, 'ec_low' => 10.0, 'ec_high' => 30.0])
        ->and(PayrollStatutoryTable::query()->where('kind', 'SSS')->sole()->effective_from->format('Y-m-d'))->toBe('2025-01-01')
        ->and(PayrollStatutoryTable::query()->where('kind', 'SSS')->sole()->covered_through->format('Y-m-d'))->toBe('2026-12-31');

    // MariaDB adds its own json_valid CHECK, named after the JSON column.
    $checks = DB::table('information_schema.TABLE_CONSTRAINTS')
        ->whereRaw('CONSTRAINT_SCHEMA = DATABASE()')
        ->where('TABLE_NAME', 'payroll_statutory_tables')
        ->where('CONSTRAINT_TYPE', 'CHECK')
        ->where('CONSTRAINT_NAME', 'like', 'pst\_%')
        ->pluck('CONSTRAINT_NAME')->sort()->values()->all();
    expect($checks)->toBe(['pst_kind_chk', 'pst_span_chk'])
        ->and(Schema::hasIndex('payroll_statutory_tables', 'pst_kind_from_unq'))->toBeTrue()
        ->and(Schema::hasForeignKey('payroll_statutory_tables', ['created_by']))->toBeTrue();

    // A run killed after the create and before everything else resumes,
    // and the seeded rows are not inserted twice.
    $migration->down();
    expect(Schema::hasTable('payroll_statutory_tables'))->toBeFalse();
    $migration->up();
    DB::statement('ALTER TABLE `payroll_statutory_tables` DROP CONSTRAINT `pst_span_chk`');
    DB::statement('ALTER TABLE `payroll_statutory_tables` DROP FOREIGN KEY `payroll_statutory_tables_created_by_foreign`');
    $migration->up();
    expect(PayrollStatutoryTable::query()->count())->toBe(4)
        ->and(Schema::hasForeignKey('payroll_statutory_tables', ['created_by']))->toBeTrue();

    // A kind that already has a version is left alone by a re-run.
    PayrollStatutoryTable::query()->where('kind', 'SSS')->delete();
    $custom = new PayrollStatutoryTable;
    $custom->fill(['kind' => 'SSS', 'effective_from' => '2026-01-01', 'covered_through' => '2026-12-31', 'params' => ['msc_min' => 5000.0, 'msc_max' => 35000.0, 'step' => 500.0, 'offset' => 250.0, 'ee_rate' => 0.05, 'er_rate' => 0.1, 'ec_threshold' => 14500.0, 'ec_low' => 10.0, 'ec_high' => 30.0]]);
    $custom->save();
    $migration->up();
    expect(PayrollStatutoryTable::query()->where('kind', 'SSS')->count())->toBe(1)
        ->and(PayrollStatutoryTable::query()->where('kind', 'SSS')->sole()->effective_from->format('Y-m-d'))->toBe('2026-01-01');
});

it('refuses an unknown kind, a coverage that ends before it starts and a second version on the same date', function () {
    expect(in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true))->toBeTrue();
    $row = static fn (string $kind, string $from, string $through): array => [
        'id' => (string) Str::ulid(), 'kind' => $kind, 'effective_from' => $from, 'covered_through' => $through,
        'reference' => null, 'params' => '{}', 'created_by' => null, 'created_by_name' => null,
        'created_at' => now('UTC')->format('Y-m-d H:i:s.v'), 'updated_at' => null,
    ];

    expect(fn () => DB::table('payroll_statutory_tables')->insert($row('GSIS', '2027-01-01', '2027-12-31')))->toThrow(QueryException::class);
    expect(fn () => DB::table('payroll_statutory_tables')->insert($row('SSS', '2027-01-01', '2026-12-31')))->toThrow(QueryException::class);
    expect(fn () => DB::table('payroll_statutory_tables')->insert($row('SSS', '2025-01-01', '2027-12-31')))->toThrow(QueryException::class);
    DB::table('payroll_statutory_tables')->insert($row('SSS', '2027-01-01', '2027-12-31'));
    expect(PayrollStatutoryTable::query()->where('kind', 'SSS')->count())->toBe(2);
});
