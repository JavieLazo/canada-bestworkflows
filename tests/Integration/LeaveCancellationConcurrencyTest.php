<?php

declare(strict_types=1);

use App\Models\AuditLog;
use App\Models\EmployeeProfile;
use App\Models\LeaveRequestCancellation;
use App\Models\LeaveTravelRequest;
use App\Models\Notification;
use App\Models\Payslip;
use App\Models\Role;
use App\Models\RoleModuleGrant;
use App\Models\User;
use App\Modules\Timekeeping\Application\Commands\CancelAbsence;
use App\Modules\Timekeeping\Contracts\PostedPayrollDates;
use App\Support\Access;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

/** Cancellation serializes with finalization and competing cancellation before taking a read snapshot. */
it('waits for payroll membership and refuses dates another writer posted or cancelled while waiting', function (string $change) {
    $this->travelTo(CarbonImmutable::parse('2026-10-10T10:00:00+08:00'));
    $role = new Role;
    $role->forceFill(['name' => 'Leave cancellation race', 'all_data' => true, 'is_system' => false])->save();
    $grant = new RoleModuleGrant;
    $grant->forceFill(['role_id' => $role->id, 'module' => 'LEAVES'])->save();
    $employee = new EmployeeProfile;
    $employee->forceFill(['employee_code' => 'LEAVE-CANCEL-RACE', 'first_name' => 'Synthetic', 'last_name' => 'Applicant',
        'date_hired' => '2024-01-01', 'employment_status' => 'Regular', 'is_active' => true])->save();
    $user = User::factory()->create(['role_id' => $role->id, 'employee_id' => $employee->id]);
    $request = new LeaveTravelRequest;
    $request->forceFill(['code' => '1', 'employee_id' => $employee->id, 'request_type' => 'VACATION_LEAVE',
        'day_type' => 'WHOLE_DAY', 'start_date' => '2026-10-12', 'end_date' => '2026-10-13',
        'status' => 'APPROVED', 'is_paid' => true])->save();
    $this->actingAs($user);
    app()->forgetInstance(Access::class);
    $actor = app(Access::class);

    $source = <<<'PHP'
require 'tests/bootstrap.php';
$app = require 'bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (! $app->environment('testing') || \Illuminate\Support\Facades\DB::connection()->getDatabaseName() !== 'ems_testing') {
    throw new \RuntimeException('Disposable test database required.');
}
\Carbon\CarbonImmutable::setTestNow(\Carbon\CarbonImmutable::parse('2026-10-10T10:00:00+08:00'));
auth()->setUser(\App\Models\User::query()->findOrFail($argv[1]));
$app->forgetInstance(\App\Support\Access::class);
\Illuminate\Support\Facades\DB::connection()->beforeExecuting(static function (string $sql): void {
    if (str_contains($sql, '`locks`') && str_contains(strtolower($sql), 'for update')) {
        echo "ready\n";
        flush();
    }
});
try {
    app(\App\Modules\Timekeeping\Application\Commands\CancelAbsence::class)->handle(
        app(\App\Support\Access::class), $argv[2], ['2026-10-12'], 'Competing change',
    );
    echo json_encode(['cancelled' => true], JSON_THROW_ON_ERROR)."\n";
} catch (\RuntimeException $exception) {
    echo json_encode(['cancelled' => false, 'message' => $exception->getMessage()], JSON_THROW_ON_ERROR)."\n";
}
PHP;
    $environment = [
        'APP_ENV' => 'testing', 'APP_KEY' => (string) config('app.key'),
        'DB_CONNECTION' => 'mysql', 'DB_DATABASE' => 'ems_testing', 'DB_USERNAME' => 'ems_testing',
        'DB_HOST' => '127.0.0.1', 'DB_PORT' => (string) DB::connection()->getConfig('port'),
        'DB_PASSWORD' => (string) DB::connection()->getConfig('password'), 'DB_URL' => '', 'DB_SOCKET' => '',
        'EMS_MARIADB_TESTS' => 'disposable', 'BROADCAST_CONNECTION' => 'null', 'MAIL_MAILER' => 'array',
        'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'LOG_CHANNEL' => 'null', 'QUEUE_CONNECTION' => 'sync',
    ];
    $worker = new Process([PHP_BINARY, '-r', $source, (string) $user->id, $request->id], base_path(), $environment, timeout: 20);

    DB::beginTransaction();
    try {
        app(PostedPayrollDates::class)->lock();
        $worker->start();
        $deadline = microtime(true) + 10;
        while (! str_contains($worker->getOutput(), "ready\n") && $worker->isRunning() && microtime(true) < $deadline) {
            usleep(10000);
        }
        expect($worker->getOutput())->toContain("ready\n")
            ->and($worker->isRunning())->toBeTrue();

        if ($change === 'payroll') {
            $payslip = new Payslip;
            $payslip->forceFill(['employee_id' => $employee->id, 'period_start' => '2026-10-12', 'period_end' => '2026-10-12'])->save();
        } else {
            app(CancelAbsence::class)->handle($actor, $request->id, ['2026-10-12'], 'First cancellation');
            expect(AuditLog::query()->count())->toBe(0)->and(Notification::query()->count())->toBe(0);
        }
        DB::commit();
        $worker->wait();
        expect($worker->isSuccessful())->toBeTrue($worker->getErrorOutput());
        $lines = explode("\n", trim($worker->getOutput()));
        $result = json_decode((string) end($lines), true, 512, JSON_THROW_ON_ERROR);
        expect($result)->toBe([
            'cancelled' => false,
            'message' => $change === 'payroll'
                ? 'A selected leave date is already on a posted payslip and cannot be cancelled.'
                : 'A selected leave date has already been cancelled. Refresh the request.',
        ]);
        $expected = $change === 'payroll' ? 0 : 1;
        expect(LeaveRequestCancellation::query()->count())->toBe($expected)
            ->and(AuditLog::query()->where('action', 'REQUEST_CANCELLED')->count())->toBe($expected)
            ->and(Notification::query()->where('kind', 'LEAVE_CANCELLED')->count())->toBe($expected);
    } finally {
        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        if ($worker->isRunning()) {
            $worker->stop(1);
        }
    }
})->with(['payroll', 'cancellation']);
