<?php

declare(strict_types=1);

use App\Models\EmployeeProfile;
use App\Models\User;
use App\Platform\Records\AccountEmployeeLinks;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** MariaDB name removal preserves account identity and approval eligibility locks the current link. */
it('replays the account-name drop and restoration without losing credential or employee links', function () {
    expect(DB::selectOne('select version() as version')->version)->toContain('MariaDB');
    $employee = EmployeeProfile::create(['employee_code' => 'IDENTITY-001', 'first_name' => 'Official', 'last_name' => 'Person', 'date_hired' => '2024-01-01', 'employment_status' => 'Regular']);
    $user = User::factory()->create(['employee_id' => $employee->id]);
    $password = $user->password;
    $migration = require base_path('database/migrations/2026_10_08_0007_drop_name_from_users_table.php');
    $migration->down();
    $migration->down();
    DB::table('users')->where('id', $user->id)->update(['name' => 'Independent label']);
    $migration->up();
    $migration->up();
    expect(Schema::hasColumn('users', 'name'))->toBeFalse()
        ->and($user->fresh()->employee_id)->toBe($employee->id)
        ->and($user->fresh()->password)->toBe($password)
        ->and($user->fresh()->name)->toBe('Official Person');
});

it('holds the current account link until a record-approval transaction finishes', function () {
    $employee = EmployeeProfile::create(['employee_code' => 'IDENTITY-002', 'first_name' => 'Linked', 'last_name' => 'Person', 'date_hired' => '2024-01-01', 'employment_status' => 'Regular']);
    $user = User::factory()->create(['employee_id' => $employee->id]);
    config(['database.connections.identity_observer' => config('database.connections.'.config('database.default'))]);
    $other = DB::connection('identity_observer');
    $other->statement('SET SESSION innodb_lock_wait_timeout = 1');
    DB::beginTransaction();
    try {
        expect(app(AccountEmployeeLinks::class)->isActiveLinked($user->id, $employee->id))->toBeTrue();
        expect(fn () => $other->table('users')->where('id', $user->id)->update(['employee_id' => null]))
            ->toThrow(QueryException::class);
    } finally {
        DB::rollBack();
    }
    $other->table('users')->where('id', $user->id)->update(['employee_id' => null]);
    expect(app(AccountEmployeeLinks::class)->isActiveLinked($user->id, $employee->id))->toBeFalse();
    DB::purge('identity_observer');
});
