<?php

declare(strict_types=1);

use App\Models\EmployeeProfile;
use App\Models\PayrollFormula;
use App\Models\PayrollRun;
use App\Models\PayrollRunMember;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

function legacyUpgradeMigration(): mixed
{
    return require base_path('database/migrations/2026_09_16_0001_preserve_legacy_upgrade_values.php');
}

function legacyUpgradeValue(string $table, string $id, string $field): mixed
{
    return DB::table('legacy_upgrade_values')->where([
        'source_table' => $table, 'source_id' => $id, 'source_field' => $field,
    ])->value('original_value');
}

it('preserves old account names and unassigned-year leave balances before their source columns are dropped', function () {
    $names = require base_path('database/migrations/2026_10_08_0007_drop_name_from_users_table.php');
    $credits = require base_path('database/migrations/2026_09_25_0002_drop_leave_credits_from_employee_profiles_table.php');
    $names->down();
    $credits->down();

    try {
        $employee = EmployeeProfile::create([
            'employee_code' => 'COP-00042', 'first_name' => 'Synthetic', 'last_name' => 'Archive',
            'employment_status' => 'Regular', 'date_hired' => '2024-01-01',
        ]);
        DB::table('employee_profiles')->where('id', $employee->id)->update(['vacation_credits' => 12.5, 'sick_credits' => 7.25]);
        $user = User::factory()->create();
        DB::table('users')->where('id', $user->id)->update(['name' => 'Legacy account label']);

        $migration = legacyUpgradeMigration();
        $migration->up();
        $migration->up();
        $names->up();
        $credits->up();
        $migration->up();

        expect(Schema::hasColumn('users', 'name'))->toBeFalse()
            ->and(Schema::hasColumn('employee_profiles', 'vacation_credits'))->toBeFalse()
            ->and(legacyUpgradeValue('users', (string) $user->id, 'name'))->toBe('Legacy account label')
            ->and((float) legacyUpgradeValue('employee_profiles', $employee->id, 'vacation_credits'))->toBe(12.5)
            ->and((float) legacyUpgradeValue('employee_profiles', $employee->id, 'sick_credits'))->toBe(7.25)
            ->and(legacyUpgradeValue('employee_profiles', $employee->id, 'employee_code'))->toBe('COP-00042')
            ->and(DB::table('leave_credits')->where('employee_id', $employee->id)->exists())->toBeFalse()
            ->and(DB::table('legacy_upgrade_values')->where('source_table', 'users')->pluck('source_field')->all())->toBe(['name']);
    } finally {
        $names->up();
        $credits->up();
    }
});

it('retains the first exact SIL configuration and code through edits deletion and retries', function () {
    $formula = PayrollFormula::create([
        'code' => 'FML-002', 'key' => 'SIL', 'name' => 'Customized legacy SIL',
        'expression' => 'Legacy display only', 'params' => [['key' => 'days', 'value' => 9.5]],
    ]);
    $original = DB::table('payroll_formulas')->where('id', $formula->id)->first();
    $migration = legacyUpgradeMigration();
    $migration->up();
    $snapshot = DB::table('legacy_upgrade_values')->where('source_id', $formula->id)->orderBy('source_field')->get()->toArray();

    $formula->update(['code' => '2', 'params' => [['key' => 'days', 'value' => 1]]]);
    $migration->up();
    (require base_path('database/migrations/2026_10_10_0001_remove_sil_from_payroll_formulas_table.php'))->up();
    $migration->up();

    expect(legacyUpgradeValue('payroll_formulas', $formula->id, 'params'))->toBe($original->params)
        ->and(legacyUpgradeValue('payroll_formulas', $formula->id, 'code'))->toBe('FML-002')
        ->and(DB::table('legacy_upgrade_values')->where('source_id', $formula->id)->orderBy('source_field')->get()->toArray())->toEqual($snapshot)
        ->and(fn () => $migration->down())->toThrow(RuntimeException::class, 'Legacy upgrade evidence exists');
});

it('allows repeated rollback only while its archive is empty', function () {
    $migration = legacyUpgradeMigration();
    DB::table('legacy_upgrade_values')->delete();
    $migration->down();
    $migration->down();
    expect(Schema::hasTable('legacy_upgrade_values'))->toBeFalse();
    $migration->up();
    expect(Schema::hasTable('legacy_upgrade_values'))->toBeTrue();
});

it('archives exact draft statutory inputs before zero normalization without copying finalized members', function () {
    $employee = EmployeeProfile::create([
        'employee_code' => 'COP-00043', 'first_name' => 'Synthetic', 'last_name' => 'Payroll',
        'employment_status' => 'Regular', 'date_hired' => '2024-01-01',
    ]);
    $members = [];
    foreach (['DRAFT', 'FINALIZED'] as $status) {
        $start = $status === 'DRAFT' ? '2026-08-01' : '2026-08-16';
        $end = $status === 'DRAFT' ? '2026-08-15' : '2026-08-31';
        $run = PayrollRun::create([
            'code' => 'PR-'.$status, 'name' => 'Synthetic '.$status, 'status' => $status,
            'period_start' => $start, 'period_end' => $end,
        ]);
        $members[$status] = PayrollRunMember::create([
            'run_id' => $run->id, 'employee_id' => $employee->id, 'position' => 0,
            'period_start' => $start, 'period_end' => $end,
            'deduction_sss' => 0, 'deduction_philhealth' => null,
            'deduction_pagibig' => 25.5, 'deduction_withholding_tax' => 0,
        ]);
    }

    $migration = legacyUpgradeMigration();
    $migration->up();
    (require base_path('database/migrations/2026_09_26_0005_clear_zero_statutory_overrides_on_draft_payroll_run_members_table.php'))->up();
    $migration->up();

    expect((float) legacyUpgradeValue('payroll_run_members', $members['DRAFT']->id, 'deduction_sss'))->toBe(0.0)
        ->and(legacyUpgradeValue('payroll_run_members', $members['DRAFT']->id, 'deduction_philhealth'))->toBeNull()
        ->and((float) legacyUpgradeValue('payroll_run_members', $members['DRAFT']->id, 'deduction_pagibig'))->toBe(25.5)
        ->and($members['DRAFT']->fresh()?->deduction_sss)->toBeNull()
        ->and(DB::table('legacy_upgrade_values')->where('source_table', 'payroll_run_members')->where('source_id', $members['FINALIZED']->id)->exists())->toBeFalse();
});
