<?php

declare(strict_types=1);

use App\Domain\Enums\ModuleCode;
use App\Models\EmployeeProfile;
use App\Models\InventoryAttachment;
use App\Models\InventoryDisposal;
use App\Models\InventoryIncident;
use App\Models\InventoryItem;
use App\Models\Role;
use App\Models\RoleModuleGrant;
use App\Models\User;
use App\Modules\Inventory\Application\Commands\RecordIncident;
use App\Modules\Inventory\Application\Commands\ResolveIncident;
use App\Modules\Inventory\Application\Commands\SaveItem;
use App\Modules\Inventory\Application\InventoryWrite;
use App\Support\Access;
use App\Support\LocalClock;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

/** A waiting upload must see a newly committed inspection before deciding disposal, and compensate its stored bytes. */
function invDisposalRaceUser(): User
{
    $role = new Role;
    $role->forceFill(['name' => 'Disposal race '.Str::random(8), 'all_data' => true, 'is_system' => false])->save();
    $grant = new RoleModuleGrant;
    $grant->forceFill(['role_id' => $role->id, 'module' => ModuleCode::INVENTORY])->save();

    return User::factory()->create(['role_id' => $role->id]);
}

function invDisposalRaceAuthority(): EmployeeProfile
{
    $employee = new EmployeeProfile;
    $employee->forceFill(['employee_code' => 'INV-'.Str::random(8), 'first_name' => 'Disposal', 'last_name' => 'Authority', 'employment_status' => 'Regular', 'date_hired' => '2024-01-01', 'is_active' => true])->save();

    return $employee;
}

/** @param array<string, string|int> $job */
function invDisposalRaceWorker(array $job, string $directory): Process
{
    $program = <<<'WORKER'
require getcwd().'/tests/bootstrap.php';
$app = require getcwd().'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
\Illuminate\Support\Facades\DB::statement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
$job = json_decode($argv[1], true, 512, JSON_THROW_ON_ERROR);
$directory = $argv[2];
config(['filesystems.disks.local.root' => $directory.'/private']);
\Illuminate\Support\Facades\Storage::forgetDisk('local');
$actor = \App\Support\Access::forActor(\App\Models\User::query()->findOrFail($job['user']));
$ready = false;
$storedDuringCheck = 0;
\Illuminate\Support\Facades\DB::connection()->beforeExecuting(function (string $query, array $bindings) use (&$ready, &$storedDuringCheck): void {
    if (! $ready && str_contains(strtolower($query), 'locks') && in_array(\App\Modules\Organization\Contracts\OrganizationReferences::STRUCTURE_LOCK, $bindings, true)) {
        $ready = true;
        echo "owner-lock\n";
        fflush(STDOUT);
    }
    if (str_contains(strtolower($query), 'inventory_incidents')) {
        $storedDuringCheck = count(\Illuminate\Support\Facades\Storage::disk('local')->allFiles());
    }
});
try {
    $file = new \Illuminate\Http\UploadedFile($directory.'/approval.pdf', 'approval.pdf', null, null, true);
    app(\App\Modules\Inventory\Application\Commands\RecordDisposal::class)->save($actor, [
        'item_id' => $job['item'], 'disposed_on' => $job['date'], 'method' => 'Authorized recycling',
        'reason' => 'Reviewed as unserviceable', 'authorization_reference' => 'RACE-AUTH',
        'authorized_by_employee_id' => $job['authority'],
    ], $file);
    $result = ['ok' => true];
} catch (\Throwable $error) {
    $result = ['ok' => false, 'type' => $error::class, 'message' => $error->getMessage()];
}
$result['stored_during_check'] = $storedDuringCheck;
$result['files'] = \Illuminate\Support\Facades\Storage::disk('local')->allFiles();
echo json_encode($result, JSON_THROW_ON_ERROR)."\n";
WORKER;

    return new Process([PHP_BINARY, '-r', $program, json_encode($job, JSON_THROW_ON_ERROR), $directory], base_path(), [
        'APP_ENV' => 'testing', 'APP_KEY' => (string) config('app.key'),
        'DB_CONNECTION' => 'mysql', 'DB_DATABASE' => 'ems_testing', 'DB_USERNAME' => 'ems_testing',
        'DB_HOST' => '127.0.0.1', 'DB_PORT' => (string) DB::connection()->getConfig('port'),
        'DB_PASSWORD' => (string) DB::connection()->getConfig('password'), 'DB_URL' => '', 'DB_SOCKET' => '',
        'EMS_MARIADB_TESTS' => 'disposable', 'BROADCAST_CONNECTION' => 'null', 'MAIL_MAILER' => 'array',
        'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'LOG_CHANNEL' => 'null', 'QUEUE_CONNECTION' => 'sync',
    ], timeout: 20);
}

it('refuses disposal when an open inspection commits while its upload waits for the inventory owner locks', function () {
    $manager = invDisposalRaceUser();
    $actor = Access::forActor($manager);
    $reviewer = Access::forActor(invDisposalRaceUser());
    $authority = invDisposalRaceAuthority();
    $date = LocalClock::todayKey();
    $itemId = app(SaveItem::class)->handle($actor, null, ['code' => 'DISPOSAL-001', 'name' => 'Disposal race printer', 'acquired_on' => '2026-01-01', 'cost' => '100']);
    $initial = app(RecordIncident::class)->save($actor, ['item_id' => $itemId, 'kind' => 'INSPECTION', 'reported_on' => $date, 'reference' => 'RACE-INITIAL', 'details' => 'Initial inspection.']);
    app(ResolveIncident::class)->save($reviewer, $initial, ['condition' => 'UNSERVICEABLE', 'resolution_reference' => 'RACE-REVIEW', 'resolution' => 'Verified unserviceable.']);
    expect(InventoryIncident::query()->where('item_id', $itemId)->where('status', 'OPEN')->exists())->toBeFalse();

    $directory = sys_get_temp_dir().'/ems-inventory-disposal-race-'.Str::random(16);
    mkdir($directory, 0700);
    file_put_contents($directory.'/approval.pdf', "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF\n");
    $worker = invDisposalRaceWorker(['user' => $manager->id, 'item' => $itemId, 'authority' => $authority->id, 'date' => $date], $directory);
    DB::beginTransaction();
    InventoryWrite::lock();
    try {
        $worker->start();
        $deadline = microtime(true) + 10;
        while (! str_contains($worker->getOutput(), "owner-lock\n") && $worker->isRunning() && microtime(true) < $deadline) {
            usleep(10000);
        }
        if (! str_contains($worker->getOutput(), "owner-lock\n")) {
            throw new RuntimeException('Disposal worker did not reach its owner lock: '.$worker->getErrorOutput().$worker->getOutput());
        }
        // This transaction owns both mutexes. The uploader has the budget lock
        // and cannot enter the owner protocol until the inspection is committed.
        $incident = app(RecordIncident::class)->save($actor, ['item_id' => $itemId, 'kind' => 'INSPECTION', 'reported_on' => $date, 'reference' => 'RACE-OPEN', 'details' => 'Additional inspection awaiting independent review.']);
        DB::commit();
        $worker->wait();
        if (! $worker->isSuccessful()) {
            throw new RuntimeException('Disposal worker failed: '.$worker->getErrorOutput());
        }
        $lines = explode("\n", trim($worker->getOutput()));
        $result = json_decode((string) end($lines), true, 512, JSON_THROW_ON_ERROR);
        expect($result)->toMatchArray(['ok' => false, 'type' => RuntimeException::class, 'stored_during_check' => 1, 'files' => []]);
        expect($result['message'])->toContain('open condition report');
        expect(InventoryItem::query()->findOrFail($itemId)->status)->toBe('IN_STOCK')
            ->and(InventoryItem::query()->findOrFail($itemId)->condition)->toBe('UNSERVICEABLE')
            ->and(InventoryIncident::query()->findOrFail($incident)->status)->toBe('OPEN')
            ->and(InventoryDisposal::query()->count())->toBe(0)
            ->and(InventoryAttachment::query()->count())->toBe(0);
        expect(File::allFiles($directory.'/private'))->toBe([]);
    } finally {
        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        if ($worker->isRunning()) {
            $worker->stop(1);
        }
        File::deleteDirectory($directory);
    }
});
