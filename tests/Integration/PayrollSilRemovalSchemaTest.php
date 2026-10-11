<?php

declare(strict_types=1);

use App\Models\PayrollFormula;
use Database\Seeders\PayrollFormulaSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** The production engine removes only the SIL row and tolerates retries and an absent formula table. */
function silRemovalSchemaMigration(): mixed
{
    return require base_path('database/migrations/2026_10_10_0001_remove_sil_from_payroll_formulas_table.php');
}

it('removes SIL on MariaDB without changing active formula rows or recreating it on rollback', function () {
    expect(in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true))->toBeTrue();
    $this->seed(PayrollFormulaSeeder::class);
    $active = PayrollFormula::query()->orderBy('code')->get()->toArray();
    PayrollFormula::create([
        'code' => '2', 'key' => 'SIL', 'name' => 'Service Incentive Leave (SIL)',
        'expression' => 'Legacy SIL expression',
        'params' => [['key' => 'max_leave', 'label' => 'Max No. of Leave', 'value' => 9]],
    ]);

    $migration = silRemovalSchemaMigration();
    foreach (['up', 'up', 'down', 'up'] as $method) {
        $migration->$method();
        expect(PayrollFormula::query()->orderBy('code')->get()->toArray())->toBe($active);
    }

    $this->seed(PayrollFormulaSeeder::class);
    expect(PayrollFormula::query()->orderBy('code')->pluck('key', 'code')->all())->toBe([
        1 => 'OT', 3 => 'NSD', 4 => 'REST_DAY',
    ]);
});

it('safely skips SIL cleanup when the formula table is absent', function () {
    $schema = require base_path('database/migrations/2026_09_01_0021_create_payroll_formulas_table.php');
    $schema->down();

    try {
        $migration = silRemovalSchemaMigration();
        $migration->up();
        $migration->up();
        $migration->down();

        expect(Schema::hasTable('payroll_formulas'))->toBeFalse();
    } finally {
        $schema->up();
    }
});
