<?php

declare(strict_types=1);

use App\Models\PayrollPayRule;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * The Pay Basis & Rates migration on MariaDB: it resumes from any step,
 * seeds the company's first version once — from the Formulas rows as they
 * stand — and its unique index and foreign key hold.
 */
function payRulesMigration(): mixed
{
    return require base_path('database/migrations/2026_10_12_0002_create_payroll_pay_rules_table.php');
}

it('creates the table once, seeds one company version and resumes from a partial run', function () {
    expect(in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true))->toBeTrue();
    $migration = payRulesMigration();

    $migration->up();
    $migration->up();
    $seeded = PayrollPayRule::query()->sole();
    expect($seeded->scope)->toBe('COMPANY')
        ->and($seeded->effective_from->format('Y-m-d'))->toBe('2025-01-01')
        ->and($seeded->params['basis'])->toBe('DAILY')
        ->and($seeded->params['daily_rate'])->toEqual(['method' => 'MONTH_WORKING_DAYS', 'value' => 0.0])
        ->and($seeded->params['night'])->toEqual(['rate' => 0.1, 'max_hours' => 8.0, 'start' => 1320, 'end' => 1800, 'on_overtime' => false])
        ->and($seeded->params['matrix']['REGULAR'])->toEqual(['worked' => null, 'overtime' => null, 'night' => null, 'unworked' => 0.0])
        ->and(Schema::hasIndex('payroll_pay_rules', 'ppr_scope_from_unq'))->toBeTrue()
        ->and(Schema::hasForeignKey('payroll_pay_rules', ['created_by']))->toBeTrue();

    // A run killed after the create and before the rest resumes, and the seeded row is not inserted twice.
    $migration->down();
    expect(Schema::hasTable('payroll_pay_rules'))->toBeFalse();
    $migration->up();
    DB::statement('ALTER TABLE `payroll_pay_rules` DROP FOREIGN KEY `payroll_pay_rules_created_by_foreign`');
    $migration->up();
    expect(PayrollPayRule::query()->count())->toBe(1)
        ->and(Schema::hasForeignKey('payroll_pay_rules', ['created_by']))->toBeTrue();

    // The seeded version carries the Formulas rows' values as they stand on the installation.
    PayrollPayRule::query()->delete();
    DB::table('payroll_formulas')->where('key', 'OT')->delete();
    DB::table('payroll_formulas')->insert([
        'id' => (string) Str::ulid(), 'code' => '901', 'key' => 'OT', 'name' => 'Overtime', 'expression' => 'x',
        'params' => json_encode([['key' => 'percentage', 'value' => 1.5], ['key' => 'max_ot', 'value' => 4]], JSON_THROW_ON_ERROR),
        'created_at' => now('UTC')->format('Y-m-d H:i:s.v'), 'updated_at' => null,
    ]);
    $migration->up();
    expect(PayrollPayRule::query()->sole()->params['overtime'])->toEqual(['rate' => 1.5, 'max_hours' => 4.0]);

    // A company that already has a version is left alone by a re-run.
    $migration->up();
    expect(PayrollPayRule::query()->count())->toBe(1);
});

it('refuses a second version of one scope on the same date', function () {
    expect(in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true))->toBeTrue();
    $row = static fn (string $scope, string $from): array => [
        'id' => (string) Str::ulid(), 'scope' => $scope, 'effective_from' => $from, 'reason' => null, 'params' => '{}',
        'created_by' => null, 'created_by_name' => null,
        'created_at' => now('UTC')->format('Y-m-d H:i:s.v'), 'updated_at' => null,
    ];

    expect(fn () => DB::table('payroll_pay_rules')->insert($row('COMPANY', '2025-01-01')))->toThrow(QueryException::class);
    DB::table('payroll_pay_rules')->insert($row('COMPANY', '2027-01-01'));
    DB::table('payroll_pay_rules')->insert($row('01ARZ3NDEKTSV4RRFFQ69G5FAV', '2027-01-01'));
    expect(PayrollPayRule::query()->count())->toBe(3);
});
