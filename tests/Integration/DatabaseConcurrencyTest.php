<?php

declare(strict_types=1);

use App\Domain\Attendance\AttendanceLog as DomainLog;
use App\Domain\Attendance\DtrCalculator;
use App\Domain\Attendance\Shift as DomainShift;
use App\Models\AttendanceLog;
use App\Models\AuditLog;
use App\Models\EmployeeProfile;
use App\Models\Notification;
use App\Models\OvertimeApproval;
use App\Models\PayrollFinalizationSnapshot;
use App\Models\PayrollRun;
use App\Models\PayrollRunMember;
use App\Models\Payslip;
use App\Models\Role;
use App\Models\RoleModuleGrant;
use App\Models\Shift;
use App\Models\User;
use App\Platform\Files\AttachmentUsage;
use App\Support\ManilaClock;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

/** Real connections must serialize critical writes and deduplicate repeated requests. */

/** @return list<array{ok: bool, type?: string, inserted?: int, duplicates?: int, failed?: int}> */
function databaseRace(string $operation, string $lock, User $user, EmployeeProfile $employee, string $runId = '', ?Closure $whileHeld = null): array
{
    $directory = sys_get_temp_dir().'/ems-test-race-'.Str::random(16);
    mkdir($directory, 0700);
    file_put_contents($directory.'/upload.bin', str_repeat('x', 1024));
    $environment = [
        'APP_ENV' => 'testing', 'APP_KEY' => (string) config('app.key'),
        'DB_CONNECTION' => 'mysql', 'DB_DATABASE' => 'ems_testing', 'DB_USERNAME' => 'ems_testing',
        'DB_HOST' => '127.0.0.1', 'DB_PORT' => (string) DB::connection()->getConfig('port'),
        'DB_PASSWORD' => (string) DB::connection()->getConfig('password'), 'DB_URL' => '', 'DB_SOCKET' => '',
        'EMS_MARIADB_TESTS' => 'disposable', 'BROADCAST_CONNECTION' => 'null', 'MAIL_MAILER' => 'array',
        'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'LOG_CHANNEL' => 'null', 'QUEUE_CONNECTION' => 'sync',
    ];
    $workers = [];
    DB::beginTransaction();
    match ($operation) {
        'grant' => DB::table('roles')->where('id', $runId)->lockForUpdate()->first(),
        'reminder' => DB::table('users')->where('id', $user->id)->lockForUpdate()->first(),
        'correct' => DB::table('attendance_logs')->where('employee_id', $employee->id)->lockForUpdate()->get(),
        default => DB::table('locks')->where('name', $lock)->lockForUpdate()->first(),
    };
    try {
        foreach ([1, 2] as $number) {
            $process = new Process([PHP_BINARY, base_path('tests/Support/concurrency-worker.php'), $operation, (string) $user->id, $employee->id, $runId, (string) $number, $directory], base_path(), $environment, timeout: 20);
            $workers[] = $process;
            $process->start();
        }
        $deadline = microtime(true) + 10;
        do {
            $ready = 0;
            foreach ($workers as $worker) {
                $ready += str_contains($worker->getOutput(), "ready\n") ? 1 : 0;
            }
            if ($ready === count($workers)) {
                break;
            }
            usleep(10000);
        } while (microtime(true) < $deadline);
        if ($ready !== count($workers)) {
            throw new RuntimeException('Test workers did not reach the locking barrier: '.implode('\n', array_map(static fn (Process $worker): string => $worker->getErrorOutput().$worker->getOutput(), $workers)));
        }
        // Whatever the lock holder writes before it lets go — a finalize
        // posting its payslip — commits with the lock.
        if ($whileHeld !== null) {
            $whileHeld();
        }
        DB::commit();
        $results = [];
        foreach ($workers as $worker) {
            $worker->wait();
            if (! $worker->isSuccessful()) {
                throw new RuntimeException('Test worker failed: '.$worker->getErrorOutput());
            }
            $lines = explode("\n", trim($worker->getOutput()));
            $result = json_decode((string) end($lines), true, 512, JSON_THROW_ON_ERROR);
            if (! is_array($result) || ! is_bool($result['ok'] ?? null)) {
                throw new RuntimeException('Invalid test worker result.');
            }
            $entry = ['ok' => $result['ok'], ...isset($result['type']) && is_string($result['type']) ? ['type' => $result['type']] : []];
            if ($operation === 'reminder') {
                foreach (['inserted', 'duplicates', 'failed'] as $key) {
                    if (! is_int($result['outcome'][$key] ?? null)) {
                        throw new RuntimeException('Missing reminder persistence outcome.');
                    }
                    $entry[$key] = $result['outcome'][$key];
                }
            }
            $results[] = $entry;
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
        File::deleteDirectory($directory);
    }
}

it('serializes competing payroll membership, finalization, and storage quota writes', function () {
    $role = Role::create(['name' => 'Concurrency test', 'all_data' => true, 'is_system' => false]);
    foreach (['PAYROLL', 'EMPLOYEES'] as $module) {
        RoleModuleGrant::create(['role_id' => $role->id, 'module' => $module]);
    }
    $employee = EmployeeProfile::create(['employee_code' => 'TEST-RACE', 'first_name' => 'Synthetic', 'last_name' => 'Concurrency', 'date_hired' => '2024-01-01', 'employment_status' => 'Regular', 'is_active' => true, 'monthly_rate' => 30000]);
    $user = User::factory()->create(['role_id' => $role->id, 'employee_id' => $employee->id]);
    $runId = '';
    foreach (['membership' => 'copems:payroll-membership', 'finalize' => 'copems:payroll-membership', 'quota' => 'copems:attachment-budget'] as $operation => $lock) {
        $results = databaseRace($operation, $lock, $user, $employee, $runId);
        expect(array_column($results, 'ok'))->toContain(true, false);
        expect(collect($results)->firstWhere('ok', false)['type'] ?? null)->toBe(RuntimeException::class);
        if ($operation === 'membership') {
            expect(PayrollRunMember::query()->where('employee_id', $employee->id)->count())->toBe(1);
            $runId = PayrollRun::query()->sole()->id;
        }
    }
    expect(Payslip::query()->where('run_id', $runId)->count())->toBe(1);
    expect(PayrollFinalizationSnapshot::query()->where('run_id', $runId)->count())->toBe(1);
    expect(Notification::query()->where('kind', 'PAYSLIP_POSTED')->count())->toBe(1);
    expect(AuditLog::query()->where('entity_id', $runId)->where('action', 'PAYROLL_FINALIZED')->count())->toBe(1);
    expect(AttachmentUsage::bytes())->toBe(1024);
});

it('serializes repeated grant intentions and counts racing reminder inserts accurately', function () {
    $adminRole = Role::create(['name' => 'Grant administrator', 'all_data' => true, 'is_system' => false]);
    RoleModuleGrant::create(['role_id' => $adminRole->id, 'module' => 'USERS']);
    $target = Role::create(['name' => 'Grant target', 'all_data' => false, 'is_system' => false]);
    $employee = EmployeeProfile::create(['employee_code' => 'TEST-GRANT', 'first_name' => 'Synthetic', 'last_name' => 'Grant',
        'date_hired' => '2024-01-01', 'employment_status' => 'Regular', 'is_active' => true]);
    $user = User::factory()->create(['role_id' => $adminRole->id, 'employee_id' => $employee->id]);
    $grants = databaseRace('grant', '', $user, $employee, $target->id);
    expect(array_column($grants, 'ok'))->toBe([true, true])
        ->and(RoleModuleGrant::query()->where('role_id', $target->id)->where('module', 'LEAVES')->count())->toBe(1)
        ->and(AuditLog::query()->where('entity', 'role_module_grants')->where('entity_id', $target->id)->count())->toBe(1);
    $reminders = databaseRace('reminder', '', $user, $employee);
    expect(array_column($reminders, 'ok'))->toBe([true, true])
        ->and(array_sum(array_column($reminders, 'inserted')))->toBe(1)
        ->and(array_sum(array_column($reminders, 'duplicates')))->toBe(1)
        ->and(array_sum(array_column($reminders, 'failed')))->toBe(0)
        ->and(Notification::query()->count())->toBe(1);
});

it('serializes two decisions on one overtime day behind the payroll membership lock', function () {
    $role = Role::create(['name' => 'Overtime race', 'all_data' => true, 'is_system' => false]);
    RoleModuleGrant::create(['role_id' => $role->id, 'module' => 'EDTR']);
    $employee = EmployeeProfile::create(['employee_code' => 'TEST-OT-RACE', 'first_name' => 'Synthetic', 'last_name' => 'Overtime',
        'date_hired' => '2024-01-01', 'employment_status' => 'Regular', 'is_active' => true, 'monthly_rate' => 30000]);
    // Both requests use the same all-data approver and compete for one day.
    $user = User::factory()->create(['role_id' => $role->id]);
    Shift::create(['employee_id' => $employee->id, 'start_time' => '08:00:00', 'end_time' => '17:00:00', 'mode' => 'FIXED',
        'grace_minutes' => 5, 'break_start' => '12:00:00', 'break_end' => '13:00:00', 'break_minutes' => 60,
        'requires_break_punch' => false, 'work_days' => [1, 2, 3, 4, 5]]);
    foreach (['TIME_IN' => '08:00', 'TIME_OUT' => '19:30'] as $type => $at) {
        AttendanceLog::create(['employee_id' => $employee->id, 'source' => 'MANUAL', 'log_type' => $type,
            'logged_at' => ManilaClock::instant("2026-09-08T{$at}:00")->utc()]);
    }

    $results = databaseRace('overtime', 'copems:payroll-membership', $user, $employee);

    // Without the lock both would INSERT and one would hit ota_employee_date_unq.
    expect(array_column($results, 'ok'))->toBe([true, true])
        ->and(OvertimeApproval::query()->where('employee_id', $employee->id)->count())->toBe(1)
        ->and(OvertimeApproval::query()->where('employee_id', $employee->id)->sole()->approved_minutes)->toBe(60)
        ->and(AuditLog::query()->where('action', 'DTR_OVERTIME_APPROVED')->where('entity_id', $employee->id)->count())->toBe(1);
});

it('lets a bulk overtime decision see a payslip posted while it waited for the payroll lock', function () {
    $role = Role::create(['name' => 'Overtime bulk race', 'all_data' => true, 'is_system' => false]);
    RoleModuleGrant::create(['role_id' => $role->id, 'module' => 'EDTR']);
    $employee = EmployeeProfile::create(['employee_code' => 'TEST-OT-BULK', 'first_name' => 'Synthetic', 'last_name' => 'Bulk',
        'date_hired' => '2024-01-01', 'employment_status' => 'Regular', 'is_active' => true, 'monthly_rate' => 30000]);
    $user = User::factory()->create(['role_id' => $role->id]);
    Shift::create(['employee_id' => $employee->id, 'start_time' => '08:00:00', 'end_time' => '17:00:00', 'mode' => 'FIXED',
        'grace_minutes' => 5, 'break_start' => '12:00:00', 'break_end' => '13:00:00', 'break_minutes' => 60,
        'requires_break_punch' => false, 'work_days' => [1, 2, 3, 4, 5]]);
    foreach (['TIME_IN' => '08:00', 'TIME_OUT' => '19:30'] as $type => $at) {
        AttendanceLog::create(['employee_id' => $employee->id, 'source' => 'MANUAL', 'log_type' => $type,
            'logged_at' => ManilaClock::instant("2026-09-08T{$at}:00")->utc()]);
    }

    // The test plays the finalize: holding payroll's membership lock, it
    // posts the payslip that covers the pending day, then commits. The bulk
    // action queued behind the lock must see that payslip and skip the day.
    $results = databaseRace('overtime-bulk', 'copems:payroll-membership', $user, $employee, whileHeld: static function () use ($employee): void {
        $payslip = new Payslip;
        $payslip->forceFill(['employee_id' => $employee->id, 'period_start' => '2026-09-01', 'period_end' => '2026-09-15',
            'gross_pay' => 15000, 'total_deductions' => 0, 'net_pay' => 15000])->save();
    });

    expect(array_column($results, 'ok'))->toBe([true, true])
        ->and(OvertimeApproval::query()->where('employee_id', $employee->id)->count())->toBe(0)
        ->and(AuditLog::query()->where('action', 'DTR_OVERTIME_APPROVED')->where('entity_id', $employee->id)->count())->toBe(0)
        ->and(Notification::query()->where('kind', 'OVERTIME_APPROVED')->count())->toBe(0);
});

it('serializes corrections of two back-to-back nights that share a morning', function () {
    $role = Role::create(['name' => 'Night correction race', 'all_data' => true, 'is_system' => false]);
    RoleModuleGrant::create(['role_id' => $role->id, 'module' => 'EDTR']);
    $employee = EmployeeProfile::create(['employee_code' => 'TEST-NIGHT-RACE', 'first_name' => 'Synthetic', 'last_name' => 'Night',
        'date_hired' => '2024-01-01', 'employment_status' => 'Regular', 'is_active' => true]);
    $user = User::factory()->create(['role_id' => $role->id]);
    $shift = Shift::create(['employee_id' => $employee->id, 'start_time' => '22:00:00', 'end_time' => '06:00:00', 'mode' => 'FIXED',
        'grace_minutes' => 5, 'break_start' => null, 'break_end' => null, 'break_minutes' => 0,
        'requires_break_punch' => false, 'work_days' => [1, 2, 3, 4, 5]]);
    // Wednesday's and Thursday's nights as the station recorded them. The
    // Thursday calendar day holds BOTH Wednesday's Time Out and Thursday's
    // Time In, so each correction reads — and locks — the other's rows.
    foreach ([['TIME_IN', '2026-09-23T22:04'], ['TIME_OUT', '2026-09-24T06:03'], ['TIME_IN', '2026-09-24T21:57'], ['TIME_OUT', '2026-09-25T06:08']] as [$type, $at]) {
        AttendanceLog::create(['employee_id' => $employee->id, 'source' => 'MANUAL', 'log_type' => $type,
            'logged_at' => ManilaClock::instant("{$at}:00")->utc()]);
    }

    // Worker 1 corrects Wednesday, worker 2 Thursday: each night to 10:00 PM
    // and 6:00 AM next day, so both delete and insert inside the shared span.
    $results = databaseRace('correct', '', $user, $employee, '2026-09-23');

    $logs = AttendanceLog::query()->where('employee_id', $employee->id)->orderBy('logged_at')->get();
    $domain = array_values($logs->map(static fn (AttendanceLog $row): DomainLog => DomainLog::fromArray([
        'id' => $row->id, 'log_type' => $row->log_type, 'logged_at' => $row->logged_at,
    ]))->all());
    // The shift as the command read it (ShiftRoster::forDate's row).
    $night = DomainShift::fromArray(Shift::query()->whereKey($shift->id)->sole()->toArray());
    // What the DTR reads as each date's row, in Manila wall clock.
    $row = static fn (string $date): array => array_map(
        static fn (DomainLog $log): string => $log->logType.' '.ManilaClock::instant($log->loggedAt)->setTimezone(ManilaClock::TZ)->format('Y-m-d H:i'),
        DtrCalculator::rowPunches($domain, $night, $date),
    );

    // Both corrections take payroll's membership lock first (a paid day is
    // never corrected), then the employee row, so they simply queue and
    // neither is picked as a deadlock victim (1213). Both succeed:
    // whichever ran second reads the other's committed night, still owns its
    // own, and replaces only that. Wednesday keeps its Time Out on Thursday
    // morning, and nothing is written twice.
    expect(array_column($results, 'ok'))->toBe([true, true])
        ->and($logs)->toHaveCount(4)
        ->and($logs->where('source', AttendanceLog::SOURCE_CORRECTION))->toHaveCount(4)
        ->and($row('2026-09-23'))->toBe(['TIME_IN 2026-09-23 22:00', 'TIME_OUT 2026-09-24 06:00'])
        ->and($row('2026-09-24'))->toBe(['TIME_IN 2026-09-24 22:00', 'TIME_OUT 2026-09-25 06:00'])
        ->and(AuditLog::query()->where('action', 'DTR_CORRECTED')->where('entity_id', $employee->id)->count())->toBe(2);
});
