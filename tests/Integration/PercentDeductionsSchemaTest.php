<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * The percentage-deductions migration on MariaDB: it resumes from any
 * step, the rule column holds JSON on a typed row, and the posted copies
 * gain the employer share.
 */
function percentDeductionsMigration(): mixed
{
    return require base_path('database/migrations/2026_10_12_0004_add_percent_to_payroll_deductions_table.php');
}

it('adds the rule and employer-share columns once and resumes from a partial run', function () {
    expect(in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true))->toBeTrue();
    $migration = percentDeductionsMigration();

    $migration->down();
    expect(Schema::hasColumn('payroll_deductions', 'percent'))->toBeFalse()
        ->and(Schema::hasColumn('payslip_deductions', 'employer_amount'))->toBeFalse();
    $migration->up();
    $migration->up();
    expect(Schema::hasColumn('payroll_deductions', 'percent'))->toBeTrue()
        ->and(Schema::hasColumn('payslip_deductions', 'employer_amount'))->toBeTrue();

    // A run killed between the two columns resumes.
    Schema::table('payslip_deductions', static fn ($t) => $t->dropColumn('employer_amount'));
    $migration->up();
    expect(Schema::hasColumn('payslip_deductions', 'employer_amount'))->toBeTrue();

    $id = (string) Str::ulid();
    DB::table('payroll_deductions')->insert([
        'id' => $id, 'code' => '901', 'name' => 'Provident Fund', 'description' => null, 'calculation' => null,
        'percent' => json_encode(['employee' => 3.0, 'employer' => 3.0, 'base' => 'MONTHLY', 'frequency' => 'EVERY', 'cap' => null, 'before_tax' => false], JSON_THROW_ON_ERROR),
        'created_at' => now('UTC')->format('Y-m-d H:i:s.v'), 'updated_at' => null,
    ]);
    // A whole figure comes back from JSON as an int.
    expect(json_decode((string) DB::table('payroll_deductions')->where('id', $id)->value('percent'), true)['employee'])->toEqual(3)
        ->and(DB::table('payroll_deductions')->whereNotNull('percent')->count())->toBe(1);
});
