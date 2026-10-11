<?php

declare(strict_types=1);

use App\Domain\Enums\ModuleCode;
use App\Models\EmployeeAddress;
use App\Models\EmployeePdsDetail;
use App\Models\EmployeeProfile;
use App\Models\EmployeeRecordChangeRequest;
use App\Models\EmploymentStatusOption;
use App\Models\Position;
use App\Models\Role;
use App\Models\RoleModuleGrant;
use App\Models\User;
use App\Models\WorkSite;
use App\Modules\Workforce\Application\Commands\ReviewRecordChange;
use App\Modules\Workforce\Application\Commands\SubmitRecordChange;
use App\Modules\Workforce\Application\Commands\UpdateEmployee;
use App\Modules\Workforce\Application\EmployeeFields;
use App\Platform\Records\AccountEmployeeLinks;
use App\Support\Access;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

/**
 * The details row and the address rows are written behind the profile row's
 * lock with plain reads and diff-based writes, so first saves for different
 * employees never block or deadlock on each other's unique-index gaps, and
 * two writers of one employee are serialised without losing a column.
 */

/** @return array{User, EmployeeProfile, EmployeeProfile} */
function pdsraceFixture(): array
{
    $role = new Role;
    $role->forceFill(['name' => 'PDS race '.Str::random(6), 'all_data' => true, 'is_system' => false])->save();
    RoleModuleGrant::query()->create(['role_id' => $role->id, 'module' => ModuleCode::EMPLOYEES]);
    $user = User::factory()->create(['role_id' => $role->id]);
    Position::query()->create(['code' => '1', 'name' => 'Driver']);
    EmploymentStatusOption::query()->create(['code' => '1', 'name' => 'Regular']);
    $site = WorkSite::query()->create([
        'name' => 'Synthetic race site', 'latitude' => 14.5995, 'longitude' => 120.9842,
        'radius_meters' => 100, 'geofence_exempt' => false,
    ]);
    $employees = [];
    foreach (['A', 'B'] as $suffix) {
        $employees[] = EmployeeProfile::query()->create([
            'employee_code' => 'TEST-PDS-RACE-'.$suffix, 'first_name' => 'Synthetic', 'last_name' => 'Details '.$suffix,
            'birth_date' => '1980-01-01', 'date_hired' => '2020-01-01', 'employment_status' => 'Regular',
            'position' => 'Driver', 'work_site_id' => $site->id, 'is_active' => true, 'monthly_rate' => 30000,
            'phone' => '0917 555 0101', 'sex' => 'Male', 'civil_status' => 'Single',
        ]);
    }

    return [$user, $employees[0], $employees[1]];
}

/** @param array<string, mixed> $form */
function pdsraceStart(User $user, EmployeeProfile $employee, string $section, array $form, string $directory): Process
{
    file_put_contents($directory.'/form.json', json_encode($form, JSON_THROW_ON_ERROR));
    $environment = [
        'APP_ENV' => 'testing', 'APP_KEY' => (string) config('app.key'),
        'DB_CONNECTION' => 'mysql', 'DB_DATABASE' => 'ems_testing', 'DB_USERNAME' => 'ems_testing',
        'DB_HOST' => '127.0.0.1', 'DB_PORT' => (string) DB::connection()->getConfig('port'),
        'DB_PASSWORD' => (string) DB::connection()->getConfig('password'), 'DB_URL' => '', 'DB_SOCKET' => '',
        'EMS_MARIADB_TESTS' => 'disposable', 'BROADCAST_CONNECTION' => 'null', 'MAIL_MAILER' => 'array',
        'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'LOG_CHANNEL' => 'null', 'QUEUE_CONNECTION' => 'sync',
    ];
    $worker = new Process([
        PHP_BINARY, base_path('tests/Support/employee-pds-worker.php'),
        (string) $user->id, $employee->id, $section, $directory.'/form.json',
    ], base_path(), $environment, timeout: 30);
    $worker->start();

    return $worker;
}

/** Whether the worker printed `$needle` (or exited) within `$seconds`. */
function pdsraceAwait(Process $worker, string $needle, float $seconds): bool
{
    $deadline = microtime(true) + $seconds;
    do {
        if (str_contains($worker->getOutput(), $needle)) {
            return true;
        }
        if (! $worker->isRunning()) {
            return str_contains($worker->getOutput(), $needle);
        }
        usleep(10000);
    } while (microtime(true) < $deadline);

    return false;
}

/** @return array<string, mixed> the worker's JSON result line */
function pdsraceResult(Process $worker): array
{
    $lines = explode("\n", trim($worker->getOutput()));
    $result = json_decode((string) end($lines), true, 512, JSON_THROW_ON_ERROR);
    if (! is_array($result)) {
        throw new RuntimeException('Invalid details worker result: '.$worker->getErrorOutput().$worker->getOutput());
    }

    return $result;
}

/**
 * Saves `$section` for `$first` on this connection and leaves the
 * transaction open, then lets an independent request save `$section` for
 * `$second`. Returns whether that request committed while this one still
 * held its uncommitted first rows, and its result.
 *
 * @param  array<string, mixed>  $changes
 * @return array{bool, array<string, mixed>}
 */
function pdsraceFirstSaves(string $section, array $changes): array
{
    [$user, $first, $second] = pdsraceFixture();
    $otherManager = User::factory()->create(['role_id' => $user->role_id]);
    $firstForm = $changes + EmployeeFields::toForm($first);
    $secondForm = $changes + EmployeeFields::toForm($second);
    $directory = sys_get_temp_dir().'/ems-test-pds-race-'.Str::random(16);
    mkdir($directory, 0700);
    $worker = null;
    DB::beginTransaction();
    try {
        app(UpdateEmployee::class)->handle(Access::forActor($user), $first->id, $section, $firstForm);
        $worker = pdsraceStart($otherManager, $second, $section, $secondForm, $directory);
        // A gap lock taken by this connection's first save would hold the
        // other insert until this transaction ends: the worker would still
        // be waiting at the deadline, or die of a deadlock.
        $finished = pdsraceAwait($worker, '"ok"', 15);
        DB::commit();
        $worker->wait();

        return [$finished, pdsraceResult($worker)];
    } finally {
        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        if ($worker?->isRunning()) {
            $worker->stop(1);
        }
        File::deleteDirectory($directory);
    }
}

it('commits two first address saves for different employees at the same time', function () {
    [$finished, $result] = pdsraceFirstSaves('addresses', ['res_street' => 'Rizal St.', 'res_city' => 'Tayabas City']);

    expect($result)->toBe(['ok' => true])
        ->and($finished)->toBeTrue()
        ->and(EmployeeAddress::query()->where('type', 'RESIDENTIAL')->count())->toBe(2)
        ->and(EmployeePdsDetail::query()->where('permanent_address_same', true)->count())->toBe(2);
});

it('commits two first details saves for different employees at the same time', function () {
    [$finished, $result] = pdsraceFirstSaves('statutory', ['umid_no' => '0028-1234567-8']);

    expect($result)->toBe(['ok' => true])
        ->and($finished)->toBeTrue()
        ->and(EmployeePdsDetail::query()->where('umid_no', '0028-1234567-8')->count())->toBe(2);
});

it('keeps both columns when HR saves UMID while an independent reviewer approves Specify', function () {
    [$user, $employee] = pdsraceFixture();
    $selfRole = new Role;
    $selfRole->forceFill(['name' => 'PDS race self '.Str::random(6), 'all_data' => false, 'is_system' => false])->save();
    RoleModuleGrant::query()->create(['role_id' => $selfRole->id, 'module' => ModuleCode::PROFILE]);
    $self = User::factory()->create(['role_id' => $selfRole->id, 'employee_id' => $employee->id]);
    $reviewer = User::factory()->create(['role_id' => $user->role_id]);
    $reviewAccess = Access::forActor($reviewer);
    $requestId = app(SubmitRecordChange::class)->section(Access::forActor($self), 'personal', [
        'civil_status' => 'Other', 'civil_status_other' => 'Annulled',
    ] + EmployeeFields::toForm($employee), 'Correct my civil status');
    $form = ['umid_no' => 'U-1'] + EmployeeFields::toForm($employee);
    $directory = sys_get_temp_dir().'/ems-test-pds-race-'.Str::random(16);
    mkdir($directory, 0700);
    $worker = null;
    DB::beginTransaction();
    try {
        app(AccountEmployeeLinks::class)->lockCurrentLinks([$reviewer->id, $self->id]);
        DB::table('employee_profiles')->where('id', $employee->id)->lockForUpdate()->first();
        $worker = pdsraceStart($user, $employee, 'statutory', $form, $directory);
        if (! pdsraceAwait($worker, "ready\n", 10)) {
            throw new RuntimeException('Details worker did not reach the row lock: '.$worker->getErrorOutput().$worker->getOutput());
        }
        app(ReviewRecordChange::class)->handle($reviewAccess, $requestId, true);
        DB::commit();
        $worker->wait();
        $result = pdsraceResult($worker);
    } finally {
        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        if ($worker?->isRunning()) {
            $worker->stop(1);
        }
        File::deleteDirectory($directory);
    }

    $row = EmployeePdsDetail::query()->where('employee_id', $employee->id)->first();
    expect(EmployeeRecordChangeRequest::query()->findOrFail($requestId)->status)->toBe('APPROVED')
        ->and($result)->toBe(['ok' => true])
        ->and(EmployeePdsDetail::query()->where('employee_id', $employee->id)->count())->toBe(1)
        ->and($row?->umid_no)->toBe('U-1')
        ->and($row?->civil_status_other)->toBe('Annulled')
        ->and($employee->refresh()->civil_status)->toBe('Other');
});
