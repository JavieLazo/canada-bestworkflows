<?php

declare(strict_types=1);

use App\Models\AuditLog;
use App\Models\EmployeeProfile;
use App\Models\LeaveCredit;
use App\Models\LeaveTravelRequest;
use App\Models\LeaveType;
use App\Models\Notification;
use App\Models\Role;
use App\Models\RoleModuleGrant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

/** Filing must see a concurrent credit reduction, deletion or approved usage before inserting. */
it('waits for changing leave inputs and refuses a balance exhausted before filing', function (string $change) {
    $role = new Role;
    $role->forceFill(['name' => 'Leave filing race', 'all_data' => false, 'is_system' => false])->save();
    $grant = new RoleModuleGrant;
    $grant->forceFill(['role_id' => $role->id, 'module' => 'PORTAL'])->save();
    $employee = new EmployeeProfile;
    $employee->forceFill(['employee_code' => 'LEAVE-RACE', 'first_name' => 'Synthetic', 'last_name' => 'Applicant',
        'date_hired' => '2024-01-01', 'employment_status' => 'Regular', 'is_active' => true])->save();
    $user = User::factory()->create(['role_id' => $role->id, 'employee_id' => $employee->id]);
    $type = new LeaveType;
    $type->forceFill(['code' => 'LT-RACE', 'name' => 'Vacation Leave'])->save();
    $credit = new LeaveCredit;
    $credit->forceFill(['employee_id' => $employee->id, 'year' => 2026, 'request_type' => 'VACATION_LEAVE', 'credits' => 1])->save();
    $request = new LeaveTravelRequest;
    $request->forceFill(['code' => '1', 'employee_id' => $employee->id, 'request_type' => 'VACATION_LEAVE',
        'day_type' => 'WHOLE_DAY', 'start_date' => '2026-01-05', 'end_date' => '2026-01-05', 'status' => 'PENDING'])->save();

    $table = $change === 'approval' ? 'leave_travel_requests' : 'leave_credits';
    $environment = [
        'APP_ENV' => 'testing', 'APP_KEY' => (string) config('app.key'),
        'DB_CONNECTION' => 'mysql', 'DB_DATABASE' => 'ems_testing', 'DB_USERNAME' => 'ems_testing',
        'DB_HOST' => '127.0.0.1', 'DB_PORT' => (string) DB::connection()->getConfig('port'),
        'DB_PASSWORD' => (string) DB::connection()->getConfig('password'), 'DB_URL' => '', 'DB_SOCKET' => '',
        'EMS_MARIADB_TESTS' => 'disposable', 'BROADCAST_CONNECTION' => 'null', 'MAIL_MAILER' => 'array',
        'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'LOG_CHANNEL' => 'null', 'QUEUE_CONNECTION' => 'sync',
    ];
    $worker = new Process([PHP_BINARY, base_path('tests/Support/leave-filing-worker.php'), (string) $user->id, $table],
        base_path(), $environment, timeout: 20);

    DB::beginTransaction();
    try {
        if ($change === 'approval') {
            DB::table('leave_travel_requests')->where('id', $request->id)->lockForUpdate()->first();
        } else {
            // Match SaveLeaveCredits' employee/year scan and index-lock order.
            LeaveCredit::query()->where('employee_id', $employee->id)->where('year', 2026)->lockForUpdate()->get();
        }
        $worker->start();
        $deadline = microtime(true) + 10;
        while (! str_contains($worker->getOutput(), "ready\n") && $worker->isRunning() && microtime(true) < $deadline) {
            usleep(10000);
        }
        expect($worker->getOutput())->toContain("ready\n");
        expect($worker->isRunning())->toBeTrue();

        match ($change) {
            'reduction' => DB::table('leave_credits')->where('id', $credit->id)->update(['credits' => 0]),
            'deletion' => DB::table('leave_credits')->where('id', $credit->id)->delete(),
            'approval' => DB::table('leave_travel_requests')->where('id', $request->id)->update(['status' => 'APPROVED', 'is_paid' => true]),
            default => throw new RuntimeException('Unknown test change.'),
        };
        DB::commit();
        $worker->wait();
        expect($worker->isSuccessful())->toBeTrue($worker->getErrorOutput());
        $lines = explode("\n", trim($worker->getOutput()));
        $result = json_decode((string) end($lines), true, 512, JSON_THROW_ON_ERROR);
        expect($result)->toBe([
            'filed' => false,
            'type' => RuntimeException::class,
            'message' => 'You have no available leave credits for the selected leave type in 2026.',
        ]);
        expect(LeaveTravelRequest::query()->count())->toBe(1)
            ->and(AuditLog::query()->where('action', 'REQUEST_FILED')->count())->toBe(0)
            ->and(Notification::query()->count())->toBe(0);
    } finally {
        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        if ($worker->isRunning()) {
            $worker->stop(1);
        }
    }
})->with(['reduction', 'deletion', 'approval']);
