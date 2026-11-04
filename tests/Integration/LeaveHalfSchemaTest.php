<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The leave half column on MariaDB (2026_11_02_0015): `half` is NULL, AM
 * or PM, the migration runs twice and rolls back.
 */
function leaveHalfMigration(): mixed
{
    return require base_path('database/migrations/2026_11_02_0015_add_half_to_leave_travel_requests_table.php');
}

it('adds the half once, pins its values, and rolls back', function () {
    expect(in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true))->toBeTrue();
    $migration = leaveHalfMigration();
    $migration->up();
    $migration->up();
    expect(Schema::hasColumn('leave_travel_requests', 'half'))->toBeTrue();

    $request = DB::table('leave_travel_requests')->first(['id']);
    if ($request !== null) {
        DB::table('leave_travel_requests')->where('id', $request->id)->update(['half' => 'AM']);
        DB::table('leave_travel_requests')->where('id', $request->id)->update(['half' => 'PM']);
        expect(fn () => DB::table('leave_travel_requests')->where('id', $request->id)->update(['half' => 'XX']))->toThrow(QueryException::class);
        DB::table('leave_travel_requests')->where('id', $request->id)->update(['half' => null]);
    }

    $migration->down();
    expect(Schema::hasColumn('leave_travel_requests', 'half'))->toBeFalse();
    $migration->up();
    expect(Schema::hasColumn('leave_travel_requests', 'half'))->toBeTrue();
});
