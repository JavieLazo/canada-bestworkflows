<?php

declare(strict_types=1);

use App\Models\EmployeeProfile;
use App\Models\LeaveTravelRequest;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/** MariaDB retains date-cancellation evidence and resumes every independently guarded DDL step. */
function lrcSchemaMigration(): mixed
{
    return require base_path('database/migrations/2026_10_10_0002_create_leave_request_cancellations_table.php');
}

function lrcSchemaAssertApplied(): void
{
    expect(in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true))->toBeTrue()
        ->and(Schema::hasColumns('leave_request_cancellations', [
            'id', 'leave_travel_request_id', 'date', 'cancelled_by', 'cancelled_at', 'reason', 'created_at',
        ]))->toBeTrue()
        ->and(Schema::hasColumn('leave_request_cancellations', 'updated_at'))->toBeFalse()
        ->and(Schema::hasIndex('leave_request_cancellations', 'lrc_request_date_unq', 'unique'))->toBeTrue()
        ->and(Schema::hasIndex('leave_request_cancellations', 'lrc_cancelled_by_idx'))->toBeTrue()
        ->and(Schema::hasForeignKey('leave_request_cancellations', ['leave_travel_request_id']))->toBeTrue()
        ->and(Schema::hasForeignKey('leave_request_cancellations', ['cancelled_by']))->toBeTrue();
    $status = DB::selectOne("SELECT ROW_FORMAT AS row_format FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'leave_request_cancellations'");
    expect(strtolower((string) $status->row_format))->toBe('dynamic');
}

/** @return array{LeaveTravelRequest, User} */
function lrcSchemaRecords(): array
{
    $employee = new EmployeeProfile;
    $employee->forceFill([
        'employee_code' => 'LRC-'.Str::random(6), 'first_name' => 'Cancellation', 'last_name' => 'Evidence',
        'date_hired' => '2024-01-01', 'employment_status' => 'Regular', 'is_active' => true,
    ])->save();
    $request = new LeaveTravelRequest;
    $request->forceFill([
        'code' => 'LRC-'.Str::random(6), 'employee_id' => $employee->id, 'request_type' => 'VACATION_LEAVE',
        'day_type' => 'WHOLE_DAY', 'status' => 'APPROVED', 'is_paid' => true,
        'start_date' => '2026-10-12', 'end_date' => '2026-10-14',
    ])->save();

    return [$request, User::factory()->create()];
}

/** @return array<string, mixed> */
function lrcSchemaRow(string $requestId, int $userId, string $date = '2026-10-12'): array
{
    return [
        'id' => (string) Str::ulid(), 'leave_travel_request_id' => $requestId, 'date' => $date,
        'cancelled_by' => $userId, 'cancelled_at' => '2026-10-10 02:00:00.123', 'reason' => 'Plans changed',
        'created_at' => '2026-10-10 02:00:00.123',
    ];
}

it('replays leave cancellation migration and rollback without rewriting request evidence', function () {
    $migration = lrcSchemaMigration();
    [$request, $user] = lrcSchemaRecords();
    DB::table('leave_request_cancellations')->insert(lrcSchemaRow($request->id, $user->id));
    $migration->up();
    $migration->up();
    lrcSchemaAssertApplied();
    expect(DB::table('leave_request_cancellations')->count())->toBe(1);

    try {
        $migration->down();
        $migration->down();
        expect(Schema::hasTable('leave_request_cancellations'))->toBeFalse()
            ->and($request->fresh()->status)->toBe('APPROVED');
    } finally {
        $migration->up();
        $migration->up();
    }
    lrcSchemaAssertApplied();
    expect(DB::table('leave_request_cancellations')->count())->toBe(0);
});

it('resumes a leave cancellation schema missing each index or foreign key', function () {
    $migration = lrcSchemaMigration();
    foreach ([
        ['lrc_request_fk', 'lrc_request_date_unq'],
        ['lrc_cancelled_by_fk', 'lrc_cancelled_by_idx'],
    ] as [$foreign, $index]) {
        Schema::table('leave_request_cancellations', static function (Blueprint $table) use ($foreign): void {
            $table->dropForeign($foreign);
        });
        $migration->up();
        lrcSchemaAssertApplied();
        Schema::table('leave_request_cancellations', static function (Blueprint $table) use ($foreign): void {
            $table->dropForeign($foreign);
        });
        Schema::table('leave_request_cancellations', static function (Blueprint $table) use ($index): void {
            $table->dropIndex($index);
        });
        $migration->up();
        $migration->up();
        lrcSchemaAssertApplied();
    }

    try {
        $migration->down();
        Schema::create('leave_request_cancellations', static function (Blueprint $t): void {
            $t->engine = 'InnoDB';
            $t->char('id', 26)->primary();
            $t->char('leave_travel_request_id', 26);
            $t->date('date');
            $t->unsignedBigInteger('cancelled_by');
            $t->dateTime('cancelled_at', 3);
            $t->string('reason', 1000);
            $t->dateTime('created_at', 3);
        });
    } finally {
        $migration->up();
        $migration->up();
    }
    lrcSchemaAssertApplied();
});

it('enforces one cancellation per request date and retains both parent request and deciding account', function () {
    [$request, $user] = lrcSchemaRecords();
    DB::table('leave_request_cancellations')->insert(lrcSchemaRow($request->id, $user->id));
    expect(fn () => DB::table('leave_request_cancellations')->insert(lrcSchemaRow($request->id, $user->id)))
        ->toThrow(QueryException::class, 'lrc_request_date_unq');
    expect(fn () => $request->delete())->toThrow(QueryException::class, 'lrc_request_fk');
    expect(fn () => $user->delete())->toThrow(QueryException::class, 'lrc_cancelled_by_fk');
    expect(fn () => DB::table('leave_request_cancellations')->insert(lrcSchemaRow((string) Str::ulid(), $user->id)))
        ->toThrow(QueryException::class, 'lrc_request_fk');
    expect(fn () => DB::table('leave_request_cancellations')->insert(lrcSchemaRow($request->id, $user->id + 99999, '2026-10-13')))
        ->toThrow(QueryException::class, 'lrc_cancelled_by_fk');
    expect(DB::table('leave_request_cancellations')->count())->toBe(1)
        ->and(DB::table('leave_request_cancellations')->value('cancelled_at'))->toBe('2026-10-10 02:00:00.123');
});
