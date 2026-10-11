<?php

declare(strict_types=1);

use App\Models\EmployeeProfile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * On the production engine, the statutory-override clean-up
 * (2026_09_26_0005) is four UPDATEs whose subquery reads `payroll_runs` —
 * never the table being updated, which MySQL/MariaDB would refuse (error
 * 1093) — so each runs as written: it nulls the 0.00 statutory overrides of
 * DRAFT runs, leaves FINALIZED runs and every other value alone, and a rerun
 * (the every-minute cron) changes no row. DatabaseMigrations rebuilds the
 * schema around each test, so the migrator has already run it once here.
 */
function stcleanschemaMigration(): mixed
{
    return require base_path('database/migrations/2026_09_26_0005_clear_zero_statutory_overrides_on_draft_payroll_run_members_table.php');
}

/**
 * A run in `$status` with one member row carrying `$columns`, written straight to the tables.
 *
 * @param  array<string, mixed>  $columns
 */
function stcleanschemaMember(string $status, array $columns): string
{
    $employee = new EmployeeProfile;
    $employee->forceFill([
        'employee_code' => 'CLNS-'.Str::upper(Str::random(5)), 'first_name' => 'Clean', 'last_name' => 'Schema',
        'employment_status' => 'Regular', 'date_hired' => '2024-01-01', 'monthly_rate' => 30000, 'is_active' => true,
    ])->save();

    $runId = (string) Str::ulid();
    DB::table('payroll_runs')->insert([
        'id' => $runId, 'code' => 'CLNS-'.Str::upper(Str::random(6)), 'name' => 'Cleanup '.Str::random(6),
        'period_start' => '2026-08-01', 'period_end' => '2026-08-15', 'status' => $status,
        'created_at' => '2026-08-16 01:00:00.000',
    ]);

    $memberId = (string) Str::ulid();
    DB::table('payroll_run_members')->insert(array_merge([
        'id' => $memberId, 'run_id' => $runId, 'employee_id' => $employee->getKey(), 'position' => 0,
        'period_start' => '2026-08-01', 'period_end' => '2026-08-15', 'created_at' => '2026-08-16 01:00:00.000',
    ], $columns));

    return $memberId;
}

/** @return array<string, string|null> the four statutory columns and other, as MariaDB returns them */
function stcleanschemaRow(string $memberId): array
{
    $row = DB::table('payroll_run_members')->where('id', $memberId)
        ->first(['deduction_sss', 'deduction_philhealth', 'deduction_pagibig', 'deduction_withholding_tax', 'deduction_other']);

    return array_map(static fn (mixed $v): ?string => $v === null ? null : (string) $v, (array) $row);
}

it('nulls the zero statutory overrides of a draft on MariaDB and leaves a finalized run alone', function () {
    expect(in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true))->toBeTrue();

    $draft = stcleanschemaMember('DRAFT', [
        'deduction_sss' => 0, 'deduction_philhealth' => 150.25, 'deduction_withholding_tax' => 0.00, 'deduction_other' => 0,
    ]);
    $finalized = stcleanschemaMember('FINALIZED', ['deduction_sss' => 0, 'deduction_pagibig' => 0]);

    stcleanschemaMigration()->up();

    expect(stcleanschemaRow($draft))->toBe([
        'deduction_sss' => null, 'deduction_philhealth' => '150.25', 'deduction_pagibig' => null,
        'deduction_withholding_tax' => null, 'deduction_other' => '0.00',
    ])->and(stcleanschemaRow($finalized))->toBe([
        'deduction_sss' => '0.00', 'deduction_philhealth' => null, 'deduction_pagibig' => '0.00',
        'deduction_withholding_tax' => null, 'deduction_other' => null,
    ]);
});

it('changes no row on a rerun', function () {
    $draft = stcleanschemaMember('DRAFT', ['deduction_sss' => 0, 'deduction_pagibig' => 75]);
    $migration = stcleanschemaMigration();
    $migration->up();
    $after = stcleanschemaRow($draft);

    $migration->up();
    expect(stcleanschemaRow($draft))->toBe($after)
        ->and($after['deduction_sss'])->toBeNull()
        ->and($after['deduction_pagibig'])->toBe('75.00');
});
