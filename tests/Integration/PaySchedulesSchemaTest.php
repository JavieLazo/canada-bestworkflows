<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * The pay schedules migration on MariaDB (2026_11_02_0004): one default
 * schedule is seeded once, a run's released mark is refused on a draft and
 * a contribution month must be `YYYY-MM`, a deleted schedule leaves the
 * run's dates (SET NULL), and the migration rolls back and resumes from
 * any step.
 */
function paySchedulesMigration(): mixed
{
    return require base_path('database/migrations/2026_11_02_0004_create_payroll_pay_schedules_table.php');
}

/** @return array<string, mixed> */
function paySchedulesRun(string $status, ?string $releasedAt, ?string $month, ?string $scheduleId = null): array
{
    return [
        'id' => (string) Str::ulid(), 'code' => 'PR-'.Str::upper(Str::random(4)), 'name' => 'Run '.Str::random(6), 'period_start' => '2026-11-01', 'period_end' => '2026-11-15',
        'status' => $status, 'kind' => 'REGULAR', 'schedule_id' => $scheduleId, 'pay_date' => '2026-11-15', 'contribution_month' => $month,
        'released_at' => $releasedAt, 'released_by' => null, 'released_by_name' => null,
        'finalized_at' => $status === 'FINALIZED' ? now('UTC')->format('Y-m-d H:i:s.v') : null, 'finalized_by' => null,
        'created_at' => now('UTC')->format('Y-m-d H:i:s.v'), 'updated_at' => null,
    ];
}

it('seeds one default schedule, keeps the released mark to finalized runs and the contribution month well formed, and unlinks a deleted schedule', function () {
    expect(in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true))->toBeTrue();
    $migration = paySchedulesMigration();
    $migration->up();
    $migration->up();
    // This migration seeds its schedule once; the two-week default beside it comes from a later one.
    expect(DB::table('payroll_pay_schedules')->where('name', 'Semi-monthly, paid on the last day')->count())->toBe(1)
        ->and(DB::table('payroll_pay_schedules')->count())->toBe(2)
        ->and(DB::table('payroll_pay_schedules')->where('is_default', true)->count())->toBe(1);
    $scheduleId = (string) DB::table('payroll_pay_schedules')->where('name', 'Semi-monthly, paid on the last day')->value('id');

    $stamp = now('UTC')->format('Y-m-d H:i:s.v');
    DB::table('payroll_runs')->insert(paySchedulesRun('FINALIZED', $stamp, '2026-11', $scheduleId));
    DB::table('payroll_runs')->insert(paySchedulesRun('DRAFT', null, null));
    expect(fn () => DB::table('payroll_runs')->insert(paySchedulesRun('DRAFT', $stamp, '2026-11')))->toThrow(QueryException::class)
        ->and(fn () => DB::table('payroll_runs')->insert(paySchedulesRun('DRAFT', null, '2026-13')))->toThrow(QueryException::class)
        ->and(fn () => DB::table('payroll_runs')->insert(paySchedulesRun('DRAFT', null, 'Nov 26')))->toThrow(QueryException::class)
        ->and(DB::table('payroll_runs')->count())->toBe(2);

    // The schedule goes; the run keeps its pay date and loses only the link.
    DB::table('payroll_pay_schedules')->where('id', $scheduleId)->delete();
    $run = DB::table('payroll_runs')->where('schedule_id', null)->where('status', 'FINALIZED')->first();
    expect($run)->not->toBeNull()->and(substr((string) $run->pay_date, 0, 10))->toBe('2026-11-15');
});

it('rolls back the schedules and the run columns, and resumes from a partial run', function () {
    expect(in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true))->toBeTrue();
    $migration = paySchedulesMigration();
    $migration->up();

    $migration->down();
    expect(Schema::hasTable('payroll_pay_schedules'))->toBeFalse()
        ->and(Schema::hasColumn('payroll_runs', 'pay_date'))->toBeFalse()
        ->and(Schema::hasColumn('payroll_runs', 'released_by'))->toBeFalse();

    // A run stopped after the table and the first run column, before the rest, resumes.
    Schema::create('payroll_pay_schedules', static function ($t): void {
        $t->char('id', 26)->primary();
        $t->string('name', 120);
        $t->json('params');
        $t->boolean('is_default')->default(false);
        $t->unsignedBigInteger('created_by')->nullable();
        $t->string('created_by_name', 200)->nullable();
        $t->dateTime('created_at', 3);
        $t->dateTime('updated_at', 3)->nullable();
    });
    Schema::table('payroll_runs', static fn ($t) => $t->char('schedule_id', 26)->nullable());
    $migration->up();
    $migration->up();
    expect(Schema::hasIndex('payroll_pay_schedules', 'pps_name_unq'))->toBeTrue()
        ->and(Schema::hasForeignKey('payroll_runs', ['schedule_id']))->toBeTrue()
        ->and(Schema::hasColumns('payroll_runs', ['pay_date', 'contribution_month', 'released_at', 'released_by', 'released_by_name']))->toBeTrue()
        ->and(DB::table('payroll_pay_schedules')->count())->toBe(1);
});
