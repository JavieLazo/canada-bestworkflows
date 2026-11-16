<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * The biometric kiosk tables on MariaDB (2026_11_04_0001): both are created
 * once and resume from a partial run; a bearer hash and a device's punch id
 * are each kept once; a device secret goes with its account; a receipt
 * outlives the attendance row it names; and the migration rolls back and runs
 * again.
 */
function emschemaMigration(): mixed
{
    return require base_path('database/migrations/2026_11_04_0001_create_edtr_mobile_tables.php');
}

/** @return array<string, mixed> */
function emschemaToken(int $userId, string $hash): array
{
    return ['id' => (string) Str::ulid(), 'user_id' => $userId, 'device_id' => (string) Str::uuid(), 'ability' => 'kiosk',
        'token_hash' => $hash, 'created_at' => now('UTC')->format('Y-m-d H:i:s.v')];
}

/** @return array<string, mixed> */
function emschemaReceipt(string $clientId, ?string $logId): array
{
    return ['id' => (string) Str::ulid(), 'client_id' => $clientId, 'attendance_log_id' => $logId, 'message' => 'TIME IN recorded.',
        'created_at' => now('UTC')->format('Y-m-d H:i:s.v')];
}

it('creates the kiosk tables once, resumes a partial run and keeps a secret and a punch id once', function () {
    expect(in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true))->toBeTrue();
    $migration = emschemaMigration();
    $migration->down();
    expect(Schema::hasTable('edtr_mobile_tokens'))->toBeFalse()
        ->and(Schema::hasTable('edtr_mobile_punch_receipts'))->toBeFalse();

    // A cron killed after the first create, before its indexes, its key and the second table.
    Schema::create('edtr_mobile_tokens', static function ($t): void {
        $t->engine = 'InnoDB';
        $t->char('id', 26)->primary();
        $t->unsignedBigInteger('user_id');
        $t->string('device_id', 64);
        $t->string('ability', 16);
        $t->char('token_hash', 64);
        $t->dateTime('last_used_at', 3)->nullable();
        $t->dateTime('revoked_at', 3)->nullable();
        $t->dateTime('created_at', 3);
    });
    $migration->up();
    $migration->up();

    expect(Schema::hasIndex('edtr_mobile_tokens', 'emt_hash_uq'))->toBeTrue()
        ->and(Schema::hasIndex('edtr_mobile_tokens', 'emt_device_idx'))->toBeTrue()
        ->and(Schema::hasForeignKey('edtr_mobile_tokens', ['user_id']))->toBeTrue()
        ->and(Schema::hasTable('edtr_mobile_punch_receipts'))->toBeTrue()
        ->and(Schema::hasIndex('edtr_mobile_punch_receipts', 'empr_client_uq'))->toBeTrue()
        ->and(Schema::hasForeignKey('edtr_mobile_punch_receipts', ['attendance_log_id']))->toBeTrue();

    $user = User::factory()->create();
    $hash = hash('sha256', 'synthetic-secret');
    DB::table('edtr_mobile_tokens')->insert(emschemaToken((int) $user->getKey(), $hash));
    $clientId = (string) Str::uuid();
    DB::table('edtr_mobile_punch_receipts')->insert(emschemaReceipt($clientId, null));

    expect(fn () => DB::table('edtr_mobile_tokens')->insert(emschemaToken((int) $user->getKey(), $hash)))->toThrow(QueryException::class)
        // A secret needs an account, and a receipt may name only a real attendance row.
        ->and(fn () => DB::table('edtr_mobile_tokens')->insert(emschemaToken(999_999_999, hash('sha256', 'no-account'))))->toThrow(QueryException::class)
        ->and(fn () => DB::table('edtr_mobile_punch_receipts')->insert(emschemaReceipt($clientId, null)))->toThrow(QueryException::class)
        ->and(fn () => DB::table('edtr_mobile_punch_receipts')->insert(emschemaReceipt((string) Str::uuid(), (string) Str::ulid())))->toThrow(QueryException::class);
});

it('drops a device secret with its account and keeps a receipt when its attendance row goes', function () {
    emschemaMigration()->up();
    $user = User::factory()->create();
    DB::table('edtr_mobile_tokens')->insert(emschemaToken((int) $user->getKey(), hash('sha256', 'goes-with-the-account')));

    $employeeId = (string) Str::ulid();
    DB::table('employee_profiles')->insert(['id' => $employeeId, 'employee_code' => 'EMT-INT', 'first_name' => 'Synthetic', 'last_name' => 'Kiosk',
        'date_hired' => '2024-01-01', 'is_active' => true, 'created_at' => now('UTC')->format('Y-m-d H:i:s.v'), 'updated_at' => null]);
    $logId = (string) Str::ulid();
    DB::table('attendance_logs')->insert(['id' => $logId, 'employee_id' => $employeeId, 'log_type' => 'TIME_IN', 'source' => 'BIOMETRIC',
        'logged_at' => now('UTC')->format('Y-m-d H:i:s.v')]);
    $clientId = (string) Str::uuid();
    DB::table('edtr_mobile_punch_receipts')->insert(emschemaReceipt($clientId, $logId));

    DB::table('users')->where('id', $user->getKey())->delete();
    expect(DB::table('edtr_mobile_tokens')->count())->toBe(0);

    // A DTR correction removes the row; the device must not be able to record that punch again.
    DB::table('attendance_logs')->where('id', $logId)->delete();
    $receipt = DB::table('edtr_mobile_punch_receipts')->where('client_id', $clientId)->first();
    expect($receipt)->not->toBeNull()
        ->and($receipt->attendance_log_id)->toBeNull();
});

it('rolls the kiosk tables back and creates them again', function () {
    $migration = emschemaMigration();
    $migration->up();

    $migration->down();
    expect(Schema::hasTable('edtr_mobile_tokens'))->toBeFalse()
        ->and(Schema::hasTable('edtr_mobile_punch_receipts'))->toBeFalse();
    $migration->down();

    $migration->up();
    expect(Schema::hasTable('edtr_mobile_tokens'))->toBeTrue()
        ->and(Schema::hasIndex('edtr_mobile_tokens', 'emt_hash_uq'))->toBeTrue()
        ->and(Schema::hasTable('edtr_mobile_punch_receipts'))->toBeTrue()
        ->and(Schema::hasForeignKey('edtr_mobile_punch_receipts', ['attendance_log_id']))->toBeTrue();
});
