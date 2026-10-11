<?php

declare(strict_types=1);

use App\Domain\Attendance\CalendarDays;
use App\Domain\Attendance\Shift as DomainShift;
use App\Domain\Attendance\ShiftResolver;
use App\Models\AuditLog;
use App\Models\EmployeeProfile;
use App\Models\Role;
use App\Models\RoleModuleGrant;
use App\Models\RosterDayOverride;
use App\Models\RosterLocation;
use App\Models\RosterScheduleChange;
use App\Models\RosterShift;
use App\Models\Shift;
use App\Models\ShiftType;
use App\Models\User;
use App\Modules\Timekeeping\Application\Commands\AssignShift;
use App\Modules\Timekeeping\Application\Commands\SaveRosterDate;
use App\Modules\Timekeeping\Application\Commands\SaveRosterShifts;
use App\Modules\Timekeeping\Application\Commands\SaveRosterWeek;
use App\Modules\Timekeeping\Application\Commands\SaveShiftType;
use App\Modules\Timekeeping\Application\ShiftTypeDraft;
use App\Modules\Timekeeping\Contracts\PostedPayrollDates;
use App\Modules\Timekeeping\Infrastructure\RosterVersions;
use App\Modules\Timekeeping\Infrastructure\ShiftRoster;
use App\Support\Access;
use App\Support\ManilaClock;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

/**
 * Real commits keep weekly writes atomic and serialize pattern edits with
 * weekday picks. Every roster write is a dated change that takes payroll's
 * membership row FIRST, then the pattern, then the employee row, and
 * refuses a past date — so the clock is frozen (Monday 2026-09-28, before
 * the 22:00 shift starts) in this process and in every worker.
 */
const RSHIFT_NOW = '2026-09-28T06:00:00+08:00';

/** @return array{actor: Access, user: User, employee: EmployeeProfile, pattern: ShiftType} */
function rshiftcommitFixture(): array
{
    $role = new Role;
    $role->forceFill(['name' => 'Weekly commit', 'all_data' => true, 'is_system' => false])->save();
    foreach (['ROSTER', 'RECORDS'] as $module) {
        $grant = new RoleModuleGrant;
        $grant->forceFill(['role_id' => $role->id, 'module' => $module])->save();
    }
    $employee = EmployeeProfile::query()->create(['employee_code' => 'RS-COMMIT', 'first_name' => 'Roster', 'last_name' => 'Commit', 'date_hired' => '2024-01-01', 'employment_status' => 'Regular', 'is_active' => true]);
    $user = User::factory()->create(['role_id' => $role->id, 'employee_id' => $employee->id]);
    test()->actingAs($user);
    app()->forgetInstance(Access::class);
    $pattern = ShiftType::query()->create(['code' => 'RS-COMMIT', 'name' => 'Weekly pattern', 'mode' => 'FIXED', 'start_time' => '22:00:00', 'end_time' => '06:00:00', 'work_days' => [1, 2, 3, 4, 5], 'grace_minutes' => 0, 'break_minutes' => 0, 'requires_break_punch' => false]);

    return ['actor' => app(Access::class), 'user' => $user, 'employee' => $employee, 'pattern' => $pattern];
}

function rshiftcommitDraft(): ShiftTypeDraft
{
    return new ShiftTypeDraft('Changed pattern', 'FIXED', [1, 2, 3, 4, 5], '21:00', '05:00', '0', '8', false, '00:00', '01:00', '0', false);
}

/** @return array<string, string> */
function rshiftcommitEnvironment(): array
{
    return [
        'APP_ENV' => 'testing', 'APP_KEY' => (string) config('app.key'),
        'DB_CONNECTION' => 'mysql', 'DB_DATABASE' => 'ems_testing', 'DB_USERNAME' => 'ems_testing',
        'DB_HOST' => '127.0.0.1', 'DB_PORT' => (string) DB::connection()->getConfig('port'),
        'DB_PASSWORD' => (string) DB::connection()->getConfig('password'), 'DB_URL' => '', 'DB_SOCKET' => '',
        'EMS_MARIADB_TESTS' => 'disposable', 'BROADCAST_CONNECTION' => 'null', 'MAIL_MAILER' => 'array',
        'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'LOG_CHANNEL' => 'null', 'QUEUE_CONNECTION' => 'sync',
    ];
}

beforeEach(function () {
    $this->travelTo(new DateTimeImmutable(RSHIFT_NOW));
});

it('rolls pattern and standing hours back if copying onto a weekday fails', function () {
    ['actor' => $actor, 'employee' => $employee, 'pattern' => $pattern] = rshiftcommitFixture();
    app(AssignShift::class)->handle($actor, $employee->id, $pattern->id);
    app(SaveRosterShifts::class)->handle($actor, $employee->id, [1 => $pattern->id]);
    // The edit copies itself as a new dated weekday row.
    DB::unprepared("CREATE TRIGGER reject_weekly_copy BEFORE INSERT ON roster_shifts FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Synthetic weekday storage failure'");
    try {
        expect(fn () => app(SaveShiftType::class)->handle($actor, $pattern->id, rshiftcommitDraft()))->toThrow(QueryException::class);
        expect($pattern->fresh()?->start_time)->toBe('22:00:00')
            ->and(Shift::query()->sole()->start_time)->toBe('22:00:00')
            ->and(RosterShift::query()->sole()->shift_data['start_time'])->toBe('22:00:00')
            ->and(RosterScheduleChange::query()->where('source', 'PATTERN_EDIT')->count())->toBe(0)
            ->and(AuditLog::query()->where('action', 'SHIFT_TYPE_UPDATED')->count())->toBe(0);
    } finally {
        DB::unprepared('DROP TRIGGER IF EXISTS reject_weekly_copy');
    }
    app(SaveShiftType::class)->handle($actor, $pattern->id, rshiftcommitDraft());
    $week = app(RosterVersions::class)->weekdaysAsOf([$employee->id], ManilaClock::todayKey())[$employee->id] ?? [];
    expect($week[1]->shift_data['start_time'] ?? null)->toBe('21:00:00')
        ->and(RosterShift::query()->count())->toBe(2);
});

it('rolls hours and deferred audit back after a location write fails and permits a clean retry', function () {
    ['actor' => $actor, 'employee' => $employee, 'pattern' => $pattern] = rshiftcommitFixture();
    DB::unprepared("CREATE TRIGGER reject_weekly_location BEFORE INSERT ON roster_locations FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Synthetic location failure'");
    try {
        expect(fn () => app(SaveRosterWeek::class)->handle($actor, $employee->id, [1 => $pattern->id], [1 => 'WFH']))->toThrow(QueryException::class);
        expect(RosterShift::query()->count())->toBe(0)->and(RosterLocation::query()->count())->toBe(0)->and(AuditLog::query()->count())->toBe(0);
    } finally {
        DB::unprepared('DROP TRIGGER IF EXISTS reject_weekly_location');
    }
    app(SaveRosterWeek::class)->handle($actor, $employee->id, [1 => $pattern->id], [1 => 'WFH']);
    expect(RosterShift::query()->count())->toBe(1)->and(RosterLocation::query()->count())->toBe(1)->and(AuditLog::query()->count())->toBe(2);
});

it('copies the edited pattern after a concurrent roster assignment waits on its pattern lock', function (string $kind) {
    ['actor' => $actor, 'user' => $user, 'employee' => $employee, 'pattern' => $pattern] = rshiftcommitFixture();
    $worker = new Process([PHP_BINARY, base_path('tests/Support/weekly-roster-worker.php'), (string) $user->id, $employee->id, $pattern->id, $kind, '2026-09-29', RSHIFT_NOW], base_path(), rshiftcommitEnvironment(), timeout: 20);
    DB::beginTransaction();
    try {
        // In the order every dated change takes them: payroll's membership
        // row, then the pattern. Holding the pattern alone would deadlock
        // against a worker that already holds the membership row.
        app(PostedPayrollDates::class)->lock();
        ShiftType::query()->whereKey($pattern->id)->lockForUpdate()->first();
        $worker->start();
        $deadline = microtime(true) + 10;
        while (! str_contains($worker->getOutput(), "ready\n") && $worker->isRunning() && microtime(true) < $deadline) {
            usleep(10000);
        }
        expect($worker->getOutput())->toContain("ready\n");
        expect($worker->isRunning())->toBeTrue();
        app(SaveShiftType::class)->handle($actor, $pattern->id, rshiftcommitDraft());
        DB::commit();
        $worker->wait();
        expect($worker->isSuccessful())->toBeTrue($worker->getErrorOutput());
        $saved = $kind === 'weekly' ? RosterShift::query()->sole() : RosterDayOverride::query()->sole();
        expect($worker->getOutput())->toContain('saved')
            ->and($saved->shift_data['start_time'] ?? null)->toBe('21:00:00')
            ->and($saved->shift_name)->toBe('Changed pattern');
    } finally {
        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        if ($worker->isRunning()) {
            $worker->stop(1);
        }
    }
})->with(['weekly', 'date']);

it('discards a date exception and its deferred audit when its outer transaction rolls back', function () {
    ['actor' => $actor, 'employee' => $employee, 'pattern' => $pattern] = rshiftcommitFixture();
    expect(fn () => DB::transaction(function () use ($actor, $employee, $pattern): void {
        app(SaveRosterDate::class)->handle($actor, $employee->id, '2026-09-29', $pattern->id, 'WFH');
        expect(RosterDayOverride::query()->count())->toBe(1)->and(AuditLog::query()->count())->toBe(0);
        throw new RuntimeException('Synthetic outer rollback');
    }))->toThrow(RuntimeException::class, 'Synthetic outer rollback');
    expect(RosterDayOverride::query()->count())->toBe(0)->and(AuditLog::query()->count())->toBe(0);
});

it('serializes two rest-date edits so exactly one preserves the final working day in a month', function () {
    ['user' => $user, 'employee' => $employee, 'pattern' => $pattern] = rshiftcommitFixture();
    foreach (CalendarDays::each('2026-09-01', '2026-09-30') as $date) {
        if (in_array(CalendarDays::dayOfWeek($date), [1, 2, 3, 4, 5], true) && ! in_array($date, ['2026-09-28', '2026-09-29'], true)) {
            RosterDayOverride::query()->create(['employee_id' => $employee->id, 'date' => $date, 'shift_name' => 'Rest day', 'is_rest_day' => true, 'shift_data' => DomainShift::restDay()->toArray()]);
        }
    }
    $workers = [];
    DB::beginTransaction();
    try {
        EmployeeProfile::query()->whereKey($employee->id)->lockForUpdate()->first();
        foreach (['2026-09-28', '2026-09-29'] as $date) {
            $worker = new Process([PHP_BINARY, base_path('tests/Support/weekly-roster-worker.php'), (string) $user->id, $employee->id, $pattern->id, 'rest', $date, RSHIFT_NOW], base_path(), rshiftcommitEnvironment(), timeout: 20);
            $workers[] = $worker;
            $worker->start();
        }
        $deadline = microtime(true) + 10;
        foreach ($workers as $worker) {
            while (! str_contains($worker->getOutput(), "ready\n") && $worker->isRunning() && microtime(true) < $deadline) {
                usleep(10000);
            }
            expect($worker->getOutput())->toContain("ready\n");
            expect($worker->isRunning())->toBeTrue();
        }
        DB::commit();
        $saved = 0;
        $refused = 0;
        foreach ($workers as $worker) {
            $worker->wait();
            expect($worker->isSuccessful())->toBeTrue($worker->getErrorOutput());
            $saved += str_contains($worker->getOutput(), "saved\n") ? 1 : 0;
            $refused += str_contains($worker->getOutput(), 'refused: Keep at least one working day in 2026-09') ? 1 : 0;
        }
        expect($saved)->toBe(1)->and($refused)->toBe(1)
            ->and(ShiftResolver::scheduledWorkDaysForShift('2026-09-01', '2026-09-30', app(ShiftRoster::class)->scheduleForEmployee($employee->id)))->toBe(1)
            ->and(AuditLog::query()->where('action', 'ROSTER_DATE_SET')->count())->toBe(1);
    } finally {
        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        foreach ($workers as $worker) {
            if ($worker->isRunning()) {
                $worker->stop(1);
            }
        }
    }
});
