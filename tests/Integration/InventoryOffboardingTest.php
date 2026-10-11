<?php

declare(strict_types=1);

use App\Domain\Enums\ModuleCode;
use App\Models\EmployeeProfile;
use App\Models\EmploymentStatusOption;
use App\Models\InventoryItem;
use App\Models\Role;
use App\Models\RoleModuleGrant;
use App\Models\User;
use App\Models\WorkSite;
use App\Modules\Inventory\Application\Commands\SaveItem;
use App\Modules\Organization\Contracts\OrganizationReferences;
use App\Support\Access;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

/** Independent MariaDB connections serialize new issues with employee inactivation and its outstanding-item warning. */
function invOffboardingUser(): User
{
    $role = new Role;
    $role->forceFill(['name' => 'Inventory race '.Str::random(8), 'all_data' => true, 'is_system' => false])->save();
    foreach ([ModuleCode::INVENTORY, ModuleCode::EMPLOYEES] as $module) {
        $grant = new RoleModuleGrant;
        $grant->forceFill(['role_id' => $role->id, 'module' => $module])->save();
    }

    return User::factory()->create(['role_id' => $role->id]);
}

function invOffboardingEmployee(WorkSite $site): EmployeeProfile
{
    $row = new EmployeeProfile;
    $row->forceFill(['employee_code' => 'INV-'.Str::random(8), 'first_name' => 'Property', 'last_name' => Str::random(8), 'employment_status' => 'Regular', 'date_hired' => '2024-01-01', 'is_active' => true, 'work_site_id' => $site->id])->save();

    return $row;
}

/** @param list<array<string, mixed>> $jobs
 * @return list<array<string, mixed>>
 */
function invOffboardingRace(array $jobs): array
{
    $program = <<<'WORKER'
require getcwd().'/tests/bootstrap.php';
$app = require getcwd().'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$job = json_decode($argv[1], true, 512, JSON_THROW_ON_ERROR);
$actor = \App\Support\Access::forActor(\App\Models\User::query()->findOrFail($job['user']));
$ready = false;
\Illuminate\Support\Facades\DB::connection()->beforeExecuting(function (string $query) use (&$ready): void {
    if (! $ready && str_contains(strtolower($query), 'locks') && str_contains(strtolower($query), 'for update')) {
        $ready = true;
        echo "ready\n";
        fflush(STDOUT);
    }
});
try {
    if ($job['operation'] === 'issue') {
        app(\App\Modules\Inventory\Application\Commands\IssueItems::class)->handle($actor, [$job['item']], $job['employee'], $job['issuer'], '2026-10-01', 'FIX-2026-001');
    } else {
        $employee = \App\Models\EmployeeProfile::query()->findOrFail($job['employee']);
        $form = ['is_active' => false] + \App\Modules\Workforce\Application\EmployeeFields::toForm($employee);
        app(\App\Modules\Workforce\Application\Commands\UpdateEmployee::class)->handle($actor, $job['employee'], 'employment', $form);
    }
    echo json_encode(['ok' => true])."\n";
} catch (\Throwable $error) {
    echo json_encode(['ok' => false, 'type' => $error::class, 'message' => $error->getMessage()])."\n";
}
WORKER;
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
    DB::table('locks')->where('name', OrganizationReferences::STRUCTURE_LOCK)->lockForUpdate()->first();
    try {
        foreach ($jobs as $job) {
            $worker = new Process([PHP_BINARY, '-r', $program, json_encode($job, JSON_THROW_ON_ERROR)], base_path(), $environment, timeout: 20);
            $workers[] = $worker;
            $worker->start();
            $deadline = microtime(true) + 10;
            while (! str_contains($worker->getOutput(), "ready\n") && $worker->isRunning() && microtime(true) < $deadline) {
                usleep(10000);
            }
            if (! str_contains($worker->getOutput(), "ready\n")) {
                throw new RuntimeException('Inventory worker never reached its lock: '.$worker->getErrorOutput().$worker->getOutput());
            }
            // The signal precedes the locking statement; let it queue before the next worker.
            usleep(300000);
        }
        DB::commit();
        $results = [];
        foreach ($workers as $worker) {
            $worker->wait();
            if (! $worker->isSuccessful()) {
                throw new RuntimeException('Inventory worker failed: '.$worker->getErrorOutput());
            }
            $lines = explode("\n", trim($worker->getOutput()));
            $result = json_decode((string) end($lines), true, 512, JSON_THROW_ON_ERROR);
            if (! is_array($result)) {
                throw new RuntimeException('Invalid inventory worker result.');
            }
            $results[] = $result;
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

it('never issues to an inactive employee or inactivates an employee whose new issue was not acknowledged', function (bool $issueFirst) {
    EmploymentStatusOption::query()->create(['code' => 'REG', 'name' => 'Regular']);
    $user = invOffboardingUser();
    $site = WorkSite::query()->create(['name' => 'Inventory race office', 'latitude' => 14.5, 'longitude' => 121.0, 'radius_meters' => 100, 'geofence_exempt' => false]);
    $employee = invOffboardingEmployee($site);
    $issuer = invOffboardingEmployee($site);
    $id = app(SaveItem::class)->handle(Access::forActor($user), null, ['code' => 'OFFBOARD-001', 'name' => 'Race printer', 'acquired_on' => '2026-01-01', 'cost' => '100']);
    $issue = ['operation' => 'issue', 'user' => $user->id, 'employee' => $employee->id, 'issuer' => $issuer->id, 'item' => $id];
    $inactive = ['operation' => 'inactive', 'user' => $user->id, 'employee' => $employee->id];
    $results = invOffboardingRace($issueFirst ? [$issue, $inactive] : [$inactive, $issue]);
    [$issued, $inactivated] = $issueFirst ? $results : array_reverse($results);
    if ($issueFirst) {
        expect($issued['ok'])->toBeTrue()->and($inactivated['ok'])->toBeFalse()->and($inactivated['message'])->toContain('Outstanding items remain');
        expect($employee->fresh()->is_active)->toBeTrue()->and(InventoryItem::query()->findOrFail($id)->status)->toBe('ISSUED');
    } else {
        expect($inactivated['ok'])->toBeTrue()->and($issued['ok'])->toBeFalse()->and($issued['message'])->toContain('active employee');
        expect($employee->fresh()->is_active)->toBeFalse()->and(InventoryItem::query()->findOrFail($id)->status)->toBe('IN_STOCK');
    }
})->with(['issue obtains the lock first' => true, 'inactivation obtains the lock first' => false]);
