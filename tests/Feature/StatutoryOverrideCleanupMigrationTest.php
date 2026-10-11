<?php

declare(strict_types=1);

use App\Models\EmployeeProfile;
use App\Models\PayrollRun;
use App\Models\PayrollRunMember;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The statutory-override clean-up (2026_09_26_0005) turns a stored 0.00 in
 * the four statutory override columns back into NULL — "use the computed
 * figure" — on DRAFT runs only, because the build that typed contributions
 * by hand stored an emptied cell as 0.00. It leaves every non-zero figure,
 * `deduction_other`, and every FINALIZED run exactly as they were; a second
 * run changes nothing and sends no DDL; down() restores nothing, because the
 * old build priced NULL and 0.00 the same.
 */
function stcleanMigration(): mixed
{
    return require base_path('database/migrations/2026_09_26_0005_clear_zero_statutory_overrides_on_draft_payroll_run_members_table.php');
}

function stcleanEmployee(string $code): EmployeeProfile
{
    $employee = new EmployeeProfile;
    $employee->forceFill([
        'employee_code' => $code, 'first_name' => 'Clean', 'last_name' => $code,
        'employment_status' => 'Regular', 'date_hired' => '2024-01-01', 'monthly_rate' => 30000, 'is_active' => true,
    ])->save();

    return $employee;
}

/**
 * @param  array<string, float|null>  $columns
 */
function stcleanMember(string $status, EmployeeProfile $employee, array $columns): PayrollRunMember
{
    $run = new PayrollRun;
    $run->forceFill([
        'code' => 'CLN-'.Str::upper(Str::random(6)), 'name' => 'Cleanup '.Str::random(6),
        'period_start' => '2026-08-01', 'period_end' => '2026-08-15', 'status' => $status,
    ])->save();

    return PayrollRunMember::create(array_merge([
        'run_id' => $run->id, 'employee_id' => $employee->getKey(), 'position' => 0,
        'period_start' => '2026-08-01', 'period_end' => '2026-08-15',
    ], $columns));
}

/** @return array<string, float|null> */
function stcleanColumns(PayrollRunMember $member): array
{
    $row = DB::table('payroll_run_members')->where('id', $member->id)->first();
    $read = static fn (mixed $v): ?float => $v === null ? null : (float) $v;

    return [
        'deduction_sss' => $read($row?->deduction_sss),
        'deduction_philhealth' => $read($row?->deduction_philhealth),
        'deduction_pagibig' => $read($row?->deduction_pagibig),
        'deduction_withholding_tax' => $read($row?->deduction_withholding_tax),
        'deduction_other' => $read($row?->deduction_other),
    ];
}

it('clears only the zero statutory overrides of draft runs', function () {
    $draft = stcleanMember('DRAFT', stcleanEmployee('CLN-001'), [
        'deduction_sss' => 0, 'deduction_philhealth' => 150.25, 'deduction_pagibig' => null,
        'deduction_withholding_tax' => 0, 'deduction_other' => 0,
    ]);
    $finalized = stcleanMember('FINALIZED', stcleanEmployee('CLN-002'), [
        'deduction_sss' => 0, 'deduction_philhealth' => 0, 'deduction_pagibig' => 0,
        'deduction_withholding_tax' => 0, 'deduction_other' => 0,
    ]);

    stcleanMigration()->up();

    // The draft's typed zeros read "computed" again; a real figure, an absent
    // one and the always-typed other deductions stay as they were.
    expect(stcleanColumns($draft))->toBe([
        'deduction_sss' => null, 'deduction_philhealth' => 150.25, 'deduction_pagibig' => null,
        'deduction_withholding_tax' => null, 'deduction_other' => 0.0,
    ]);
    // A finalized run's members are history and are never touched.
    expect(stcleanColumns($finalized))->toBe([
        'deduction_sss' => 0.0, 'deduction_philhealth' => 0.0, 'deduction_pagibig' => 0.0,
        'deduction_withholding_tax' => 0.0, 'deduction_other' => 0.0,
    ]);
});

it('changes nothing and sends no DDL on a second run, and restores nothing on the way down', function () {
    $member = stcleanMember('DRAFT', stcleanEmployee('CLN-011'), ['deduction_sss' => 0, 'deduction_pagibig' => 75]);
    $migration = stcleanMigration();
    $migration->up();
    $after = stcleanColumns($member);

    DB::flushQueryLog();
    DB::enableQueryLog();
    $migration->up();
    DB::disableQueryLog();

    $ddl = array_filter(array_column(DB::getQueryLog(), 'query'), static fn (string $sql): bool => preg_match('/^\s*(create|alter|drop)\b/i', $sql) === 1);
    expect($ddl)->toBe([])
        ->and(stcleanColumns($member))->toBe($after);

    // down() is a documented no-op: the old build priced NULL and 0.00 alike.
    $migration->down();
    expect(stcleanColumns($member))->toBe($after)
        ->and($after['deduction_sss'])->toBeNull()
        ->and($after['deduction_pagibig'])->toBe(75.0);
});
