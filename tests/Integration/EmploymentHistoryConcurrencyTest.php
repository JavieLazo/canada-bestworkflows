<?php

declare(strict_types=1);

use App\Domain\Enums\ModuleCode;
use App\Models\AuditLog;
use App\Models\EmployeeEmploymentHistory;
use App\Models\EmployeeProfile;
use App\Models\EmploymentStatusOption;
use App\Models\Position;
use App\Models\Role;
use App\Models\RoleModuleGrant;
use App\Models\User;
use App\Models\WorkSite;
use App\Modules\Organization\Contracts\OrganizationReferences;
use App\Modules\Workforce\Application\Commands\UpdateEmployee;
use App\Modules\Workforce\Application\EmployeeFields;
use App\Platform\Database\TransactionMutex;
use App\Platform\Records\AccountEmployeeLinks;
use App\Support\Access;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

/** Employment history and merged validation must use the employee row after competing saves commit. */

/** @return array{User, EmployeeProfile} */
function employmentHistoryRaceFixture(): array
{
    $role = new Role;
    $role->forceFill(['name' => 'Employment race '.Str::random(6), 'all_data' => true, 'is_system' => false])->save();
    RoleModuleGrant::query()->create(['role_id' => $role->id, 'module' => ModuleCode::EMPLOYEES]);
    $user = User::factory()->create(['role_id' => $role->id]);
    Position::query()->create(['code' => '1', 'name' => 'Driver']);
    EmploymentStatusOption::query()->create(['code' => '1', 'name' => 'Regular']);
    $site = WorkSite::query()->create([
        'name' => 'Synthetic race site', 'latitude' => 14.5995, 'longitude' => 120.9842,
        'radius_meters' => 100, 'geofence_exempt' => false,
    ]);
    $employee = EmployeeProfile::query()->create([
        'employee_code' => 'TEST-HISTORY-RACE', 'first_name' => 'Synthetic', 'last_name' => 'History',
        'birth_date' => '1980-01-01', 'date_hired' => '2020-01-01', 'employment_status' => 'Regular',
        'position' => 'Driver', 'position_level' => 'Junior', 'work_site_id' => $site->id,
        'is_active' => true, 'monthly_rate' => 30000,
    ]);

    return [$user, $employee];
}

/**
 * Queue an independent HR request behind this connection's employee row
 * lock. The worker signals immediately before the first locking read or
 * UPDATE it would wait on; the holder then commits another edit before the
 * waiting request continues.
 *
 * Each request takes a shared lock on its own account before its business
 * locks. Shared locks never wait on one another, so the worker reaches the
 * intended structure or employee lock. `$holdStructure` takes
 * the structure mutex before the employee row when the holder also edits
 * employment. A personal-details holder needs only the employee row.
 *
 * @param  array<string, mixed>  $form
 * @return array{ok: bool, type?: string, message?: string}
 */
function employmentHistoryRace(User $user, EmployeeProfile $employee, array $form, Closure $whileHeld, bool $holdStructure): array
{
    $workerUser = User::factory()->create(['role_id' => $user->role_id]);
    $directory = sys_get_temp_dir().'/ems-test-history-race-'.Str::random(16);
    mkdir($directory, 0700);
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
        PHP_BINARY, base_path('tests/Support/employment-history-worker.php'),
        (string) $workerUser->id, $employee->id, $directory.'/form.json', $holdStructure ? 'structure' : 'row',
    ], base_path(), $environment, timeout: 20);
    DB::beginTransaction();
    try {
        app(AccountEmployeeLinks::class)->lockCurrentLinks([(int) $user->id]);
        if ($holdStructure) {
            TransactionMutex::acquire(OrganizationReferences::STRUCTURE_LOCK);
        }
        DB::table('employee_profiles')->where('id', $employee->id)->lockForUpdate()->first();
        $worker->start();
        $deadline = microtime(true) + 10;
        do {
            if (str_contains($worker->getOutput(), "ready\n")) {
                break;
            }
            if (! $worker->isRunning()) {
                break;
            }
            usleep(10000);
        } while (microtime(true) < $deadline);
        if (! str_contains($worker->getOutput(), "ready\n")) {
            throw new RuntimeException('Employment worker did not reach the row lock: '.$worker->getErrorOutput().$worker->getOutput());
        }
        $whileHeld();
        DB::commit();
        $worker->wait();
        if (! $worker->isSuccessful()) {
            throw new RuntimeException('Employment worker failed: '.$worker->getErrorOutput().$worker->getOutput());
        }
        $lines = explode("\n", trim($worker->getOutput()));
        $result = json_decode((string) end($lines), true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($result) || ! is_bool($result['ok'] ?? null)) {
            throw new RuntimeException('Invalid employment worker result.');
        }

        return [
            'ok' => $result['ok'],
            ...is_string($result['type'] ?? null) ? ['type' => $result['type']] : [],
            ...is_string($result['message'] ?? null) ? ['message' => $result['message']] : [],
        ];
    } finally {
        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        if ($worker->isRunning()) {
            $worker->stop(1);
        }
        File::deleteDirectory($directory);
    }
}

it('serializes overlapping HR saves so every employment snapshot matches the values that save commits', function () {
    [$user, $employee] = employmentHistoryRaceFixture();
    $form = EmployeeFields::toForm($employee);
    $result = employmentHistoryRace($user, $employee, ['position_level' => 'Senior'] + $form, static function () use ($user, $employee, $form): void {
        app(UpdateEmployee::class)->handle(Access::forActor($user), $employee->id, 'employment', ['monthly_rate' => '40000'] + $form);
    }, holdStructure: true);

    // The waiting form still carries 30000: ordinary last-save-wins behavior
    // writes it back after seeing 40000, and records BOTH changes. Were
    // Eloquent to skip that salary as unchanged against its stale model, the
    // row would stay at 40000 and this request's history would falsely
    // record 30000.
    $history = EmployeeEmploymentHistory::query()->where('employee_id', $employee->id)->orderBy('created_at')->orderBy('id')->get();
    expect($result)->toBe(['ok' => true])
        ->and($history)->toHaveCount(2)
        ->and($history[0]->monthly_rate)->toBe(40000.0)
        ->and($history[0]->position_level)->toBe('Junior')
        ->and($history[0]->changed_fields)->toBe('monthly_rate')
        ->and($history[0]->previous_values['monthly_rate'])->toBe('30000.00')
        ->and($history[1]->monthly_rate)->toBe(30000.0)
        ->and($history[1]->position_level)->toBe('Senior')
        ->and($history[1]->changed_fields)->toBe('position_level,monthly_rate')
        ->and($history[1]->previous_values['monthly_rate'])->toBe('40000.00')
        ->and($history[1]->previous_values['position_level'])->toBe('Junior')
        ->and($employee->refresh()->monthly_rate)->toBe($history[1]->monthly_rate)
        ->and($employee->position_level)->toBe($history[1]->position_level)
        ->and(AuditLog::query()->where('entity_id', $employee->id)->count())->toBe(2);
});

it('validates an employment edit against personal facts committed while it waited for the employee lock', function () {
    [$user, $employee] = employmentHistoryRaceFixture();
    $form = EmployeeFields::toForm($employee);
    $result = employmentHistoryRace($user, $employee, ['date_hired' => '1990-01-01'] + $form, static function () use ($user, $employee, $form): void {
        app(UpdateEmployee::class)->handle(Access::forActor($user), $employee->id, 'personal', ['birth_date' => '1995-01-01'] + $form);
    }, holdStructure: false);

    expect($result)->toBe([
        'ok' => false, 'type' => RuntimeException::class, 'message' => 'Birth date cannot be after the date joined.',
    ])
        ->and($employee->refresh()->birth_date?->format('Y-m-d'))->toBe('1995-01-01')
        ->and($employee->date_hired->format('Y-m-d'))->toBe('2020-01-01')
        ->and(EmployeeEmploymentHistory::query()->where('employee_id', $employee->id)->count())->toBe(0)
        ->and(AuditLog::query()->where('entity_id', $employee->id)->count())->toBe(1);
});
