<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * The credited-minutes column on MariaDB (2026_11_02_0016): a decision's
 * credit stays between 0 and its approved minutes, the migration runs
 * twice, and it rolls back the column with its CHECK.
 */
function otcredMigration(): mixed
{
    return require base_path('database/migrations/2026_11_02_0016_add_credited_minutes_to_overtime_approvals_table.php');
}

/** @return array<string, mixed> */
function otcredRow(string $employeeId, string $date, int $approved, int $credited): array
{
    return ['id' => (string) Str::ulid(), 'employee_id' => $employeeId, 'date' => $date, 'decision' => 'APPROVED',
        'computed_minutes' => 120, 'approved_minutes' => $approved, 'credited_minutes' => $credited, 'note' => null, 'category' => null, 'reviewed_by' => null,
        'created_at' => now('UTC')->format('Y-m-d H:i:s.v'), 'updated_at' => null];
}

it('keeps the credit within the approval, runs twice and rolls back', function () {
    expect(in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true))->toBeTrue();
    $migration = otcredMigration();
    $migration->up();
    $migration->up();
    expect(Schema::hasColumn('overtime_approvals', 'credited_minutes'))->toBeTrue();

    $employeeId = (string) Str::ulid();
    DB::table('employee_profiles')->insert(['id' => $employeeId, 'employee_code' => 'OTCR-INT', 'first_name' => 'Synthetic', 'last_name' => 'Credit', 'date_hired' => '2024-01-01',
        'is_active' => true, 'created_at' => now('UTC')->format('Y-m-d H:i:s.v'), 'updated_at' => null]);
    DB::table('overtime_approvals')->insert(otcredRow($employeeId, '2026-09-08', 120, 0));
    DB::table('overtime_approvals')->insert(otcredRow($employeeId, '2026-09-09', 120, 120));
    expect(fn () => DB::table('overtime_approvals')->insert(otcredRow($employeeId, '2026-09-10', 120, 121)))->toThrow(QueryException::class)
        ->and(DB::table('overtime_approvals')->where('employee_id', $employeeId)->count())->toBe(2);

    $migration->down();
    expect(Schema::hasColumn('overtime_approvals', 'credited_minutes'))->toBeFalse();
    $migration->up();
    expect(Schema::hasColumn('overtime_approvals', 'credited_minutes'))->toBeTrue();
});
