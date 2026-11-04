<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * The overtime category column on MariaDB (2026_11_02_0006): a decision
 * carries no category or SEAPORT and nothing else, the migration runs
 * twice, and it rolls back the column with its CHECK.
 */
function otcatMigration(): mixed
{
    return require base_path('database/migrations/2026_11_02_0006_add_category_to_overtime_approvals_table.php');
}

/** @return array<string, mixed> */
function otcatRow(string $employeeId, string $date, ?string $category): array
{
    return ['id' => (string) Str::ulid(), 'employee_id' => $employeeId, 'date' => $date, 'decision' => 'APPROVED',
        'computed_minutes' => 120, 'approved_minutes' => 120, 'note' => null, 'category' => $category, 'reviewed_by' => null,
        'created_at' => now('UTC')->format('Y-m-d H:i:s.v'), 'updated_at' => null];
}

it('admits no category or SEAPORT alone, runs twice and rolls back', function () {
    expect(in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true))->toBeTrue();
    $migration = otcatMigration();
    $migration->up();
    $migration->up();
    expect(Schema::hasColumn('overtime_approvals', 'category'))->toBeTrue();

    $employeeId = (string) Str::ulid();
    DB::table('employee_profiles')->insert(['id' => $employeeId, 'employee_code' => 'OTC-INT', 'first_name' => 'Synthetic', 'last_name' => 'Category', 'date_hired' => '2024-01-01',
        'is_active' => true, 'created_at' => now('UTC')->format('Y-m-d H:i:s.v'), 'updated_at' => null]);
    DB::table('overtime_approvals')->insert(otcatRow($employeeId, '2026-09-08', null));
    DB::table('overtime_approvals')->insert(otcatRow($employeeId, '2026-09-09', 'SEAPORT'));
    expect(fn () => DB::table('overtime_approvals')->insert(otcatRow($employeeId, '2026-09-10', 'HARBOR')))->toThrow(QueryException::class)
        ->and(DB::table('overtime_approvals')->where('employee_id', $employeeId)->count())->toBe(2);

    $migration->down();
    expect(Schema::hasColumn('overtime_approvals', 'category'))->toBeFalse();
    $migration->up();
    expect(Schema::hasColumn('overtime_approvals', 'category'))->toBeTrue();
});
