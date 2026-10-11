<?php

declare(strict_types=1);

use App\Models\AttendanceLog;
use App\Models\AuditLog;
use App\Models\EmployeeProfile;
use App\Models\Role;
use App\Models\RoleModuleGrant;
use App\Models\RosterDayOverride;
use App\Models\Shift;
use App\Models\ShiftType;
use App\Models\User;
use App\Models\WorkSite;
use App\Modules\Timekeeping\Application\ShiftPolicy;
use App\Modules\Timekeeping\Infrastructure\ShiftRoster;
use App\Support\Access;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

/**
 * Schedule changes and punches for ONE employee serialize on the real
 * database: two temporary schedules saved at once for the same dates end
 * as one saved and one refused as an overlap, a punch that arrives while a
 * schedule change holds the employee row lands after it on the schedule
 * that applies, and nothing deadlocks. Each competitor is its own process
 * on its own connection, with the same frozen clock.
 */
const SCC_NOW = '2026-10-07T07:30:00+08:00';

/** @return array{user: User, employee: EmployeeProfile, day: ShiftType, late: ShiftType} */
function sccFixture(): array
{
    $role = new Role;
    $role->forceFill(['name' => 'Schedule concurrency', 'all_data' => true, 'is_system' => false])->save();
    foreach (['ROSTER', 'EDTR'] as $module) {
        $grant = new RoleModuleGrant;
        $grant->forceFill(['role_id' => $role->id, 'module' => $module])->save();
    }
    $site = WorkSite::query()->create(['name' => 'Concurrency Site', 'latitude' => 14.5995, 'longitude' => 120.9842, 'radius_meters' => 150, 'geofence_exempt' => true]);
    $employee = EmployeeProfile::query()->create(['employee_code' => 'SC-CONC', 'first_name' => 'Sched', 'last_name' => 'Race',
        'date_hired' => '2024-01-01', 'employment_status' => 'Regular', 'is_active' => true, 'work_site_id' => $site->id]);
    $user = User::factory()->create(['role_id' => $role->id, 'employee_id' => $employee->id]);
    test()->actingAs($user);
    app()->forgetInstance(Access::class);
    $day = ShiftType::query()->create(['code' => 'SC-DAY', 'name' => 'Day', 'mode' => 'FIXED', 'start_time' => '08:00:00', 'end_time' => '17:00:00',
        'work_days' => [1, 2, 3, 4, 5], 'grace_minutes' => 0, 'break_minutes' => 0, 'requires_break_punch' => false]);
    $late = ShiftType::query()->create(['code' => 'SC-LATE', 'name' => 'Late', 'mode' => 'FIXED', 'start_time' => '13:00:00', 'end_time' => '22:00:00',
        'work_days' => [1, 2, 3, 4, 5], 'grace_minutes' => 0, 'break_minutes' => 0, 'requires_break_punch' => false]);
    Shift::query()->create(ShiftPolicy::attributesFromPattern($day) + ['employee_id' => $employee->id]);

    return ['user' => $user, 'employee' => $employee, 'day' => $day, 'late' => $late];
}

/** @return array<string, string> */
function sccEnvironment(): array
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

function sccWorker(string $kind, User $user, EmployeeProfile $employee, string $patternId, string $from, string $to): Process
{
    return new Process([PHP_BINARY, base_path('tests/Support/schedule-change-worker.php'), $kind, (string) $user->id, $employee->id, $patternId, $from, $to, SCC_NOW], base_path(), sccEnvironment(), timeout: 30);
}

/**
 * Start the workers while the test holds the employee row, wait until each
 * has reached a lock it queues on, release the row, and collect their last
 * lines.
 *
 * @param  list<Process>  $workers
 * @return list<string>
 */
function sccRace(EmployeeProfile $employee, array $workers): array
{
    DB::beginTransaction();
    try {
        EmployeeProfile::query()->whereKey($employee->id)->lockForUpdate()->first();
        foreach ($workers as $worker) {
            $worker->start();
        }
        $deadline = microtime(true) + 15;
        foreach ($workers as $worker) {
            while (! str_contains($worker->getOutput(), "ready\n") && $worker->isRunning() && microtime(true) < $deadline) {
                usleep(10000);
            }
            expect($worker->getOutput())->toContain("ready\n");
            expect($worker->isRunning())->toBeTrue($worker->getErrorOutput().$worker->getOutput());
        }
        // Let every worker actually queue behind the row before it is freed.
        usleep(300000);
        DB::commit();
        $results = [];
        foreach ($workers as $worker) {
            $worker->wait();
            expect($worker->isSuccessful())->toBeTrue($worker->getErrorOutput());
            $lines = explode("\n", trim($worker->getOutput()));
            $results[] = (string) end($lines);
        }

        return $results;
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
}

beforeEach(function () {
    $this->travelTo(new DateTimeImmutable(SCC_NOW));
});

it('saves one of two temporary schedules raced for the same employee and dates, and refuses the other as an overlap', function () {
    ['user' => $user, 'employee' => $employee, 'day' => $day, 'late' => $late] = sccFixture();

    $results = sccRace($employee, [
        sccWorker('assignment', $user, $employee, $late->id, '2026-10-08', '2026-10-09'),
        sccWorker('assignment', $user, $employee, $day->id, '2026-10-09', '2026-10-10'),
    ]);
    sort($results);

    expect($results)->toBe(['overlap: 1', 'saved'])
        ->and(RosterDayOverride::query()->where('employee_id', $employee->id)->where('status', RosterDayOverride::STATUS_ACTIVE)->count())->toBe(1)
        ->and(AuditLog::query()->where('action', 'ROSTER_PERIOD_SET')->count())->toBe(1);
});

it('lands a punch that arrived during a schedule change after it, on the schedule that applies, with nothing deadlocked', function () {
    ['user' => $user, 'employee' => $employee, 'late' => $late] = sccFixture();

    // The change is for tomorrow: today's 08:00 shift is unchanged, so the
    // 07:30 Time In lands inside its early window on today's date.
    $results = sccRace($employee, [
        sccWorker('assignment', $user, $employee, $late->id, '2026-10-08', '2026-10-08'),
        sccWorker('punch', $user, $employee, $late->id, '', ''),
    ]);
    sort($results);

    expect($results)->toBe(['punched: TIME IN recorded at Concurrency Site — geofence-exempt.', 'saved'])
        ->and(AttendanceLog::query()->where('employee_id', $employee->id)->count())->toBe(1)
        ->and(AttendanceLog::query()->where('employee_id', $employee->id)->sole()->logged_at->utc()->format('Y-m-d H:i'))->toBe(CarbonImmutable::parse(SCC_NOW)->utc()->format('Y-m-d H:i'))
        ->and(app(ShiftRoster::class)->forDate($employee->id, '2026-10-07')?->startTime)->toBe('08:00:00')
        ->and(app(ShiftRoster::class)->forDate($employee->id, '2026-10-08')?->startTime)->toBe('13:00:00');
});
