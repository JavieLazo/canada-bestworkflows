<?php

declare(strict_types=1);

use App\Domain\Enums\ModuleCode;
use App\Models\DocumentType;
use App\Models\EmployeeDocument;
use App\Models\EmployeeEducation;
use App\Models\EmployeeEmploymentHistory;
use App\Models\EmployeeProfile;
use App\Models\EmployeeRecordChangeRequest;
use App\Models\EmploymentStatusOption;
use App\Models\Notification;
use App\Models\Role;
use App\Models\RoleModuleGrant;
use App\Models\User;
use App\Models\WorkSite;
use App\Modules\Workforce\Application\Commands\AttachDocument;
use App\Modules\Workforce\Application\Commands\ReviewRecordChange;
use App\Modules\Workforce\Application\Commands\SubmitRecordChange;
use App\Modules\Workforce\Application\Commands\UpdateEmployee;
use App\Modules\Workforce\Application\Commands\WithdrawRecordChange;
use App\Modules\Workforce\Application\EmployeeFields;
use App\Support\Access;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

/** Approval, official employee values and timeline evidence commit together under real MariaDB locks. */

/** @return array{EmployeeProfile, User, User, EmployeeRecordChangeRequest} */
function recordApprovalFixture(bool $education = false): array
{
    $employee = EmployeeProfile::create([
        'employee_code' => 'APPROVAL-001', 'first_name' => 'Synthetic', 'last_name' => 'Requester',
        'date_hired' => '2024-01-01', 'employment_status' => 'Regular', 'is_active' => true,
        'emergency_name' => 'Original Contact', 'emergency_relation' => 'Sibling',
        'emergency_phone' => '09171234567', 'emergency_address' => 'Original address',
    ]);
    $ownRole = Role::create(['name' => 'Record owner', 'all_data' => false, 'is_system' => false]);
    RoleModuleGrant::create(['role_id' => $ownRole->id, 'module' => ModuleCode::PROFILE]);
    $requester = User::factory()->create(['employee_id' => $employee->id, 'role_id' => $ownRole->id]);
    $role = Role::create(['name' => 'Record reviewer', 'all_data' => true, 'is_system' => false]);
    RoleModuleGrant::create(['role_id' => $role->id, 'module' => ModuleCode::EMPLOYEES]);
    $reviewer = User::factory()->create(['role_id' => $role->id]);
    if ($education) {
        $row = EmployeeEducation::create(['employee_id' => $employee->id, 'level' => 'COLLEGE', 'school' => 'Original School']);
        $id = app(SubmitRecordChange::class)->pds(Access::forActor($requester), 'education', $row->id,
            ['level' => 'COLLEGE', 'school' => 'Proposed School']);
    } else {
        $id = app(SubmitRecordChange::class)->section(Access::forActor($requester), 'emergency', [
            'emergency_name' => 'Proposed Contact', 'emergency_relation' => 'Sibling',
            'emergency_phone' => '09171234567', 'emergency_address' => 'Original address',
        ]);
    }

    return [$employee, $requester, $reviewer, EmployeeRecordChangeRequest::query()->findOrFail($id)];
}

/** Launch a genuinely separate request against the guarded disposable database. */
function recordApprovalWorker(User $reviewer, EmployeeRecordChangeRequest $request): Process
{
    $environment = [
        'APP_ENV' => 'testing', 'APP_KEY' => (string) config('app.key'),
        'DB_CONNECTION' => 'mysql', 'DB_DATABASE' => 'ems_testing', 'DB_USERNAME' => 'ems_testing',
        'DB_HOST' => '127.0.0.1', 'DB_PORT' => (string) DB::connection()->getConfig('port'),
        'DB_PASSWORD' => (string) DB::connection()->getConfig('password'), 'DB_URL' => '', 'DB_SOCKET' => '',
        'EMS_MARIADB_TESTS' => 'disposable', 'BROADCAST_CONNECTION' => 'null', 'MAIL_MAILER' => 'array',
        'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'LOG_CHANNEL' => 'null', 'QUEUE_CONNECTION' => 'sync',
    ];

    return new Process([
        PHP_BINARY, base_path('tests/Support/record-approval-worker.php'), (string) $reviewer->id, $request->id,
    ], base_path(), $environment, timeout: 20);
}

it('resumes request and approved-history DDL after missing columns indexes constraints and foreign keys', function () {
    expect(DB::selectOne('select version() as version')->version)->toContain('MariaDB');
    $requests = require base_path('database/migrations/2026_10_08_0005_create_employee_record_change_requests_table.php');
    $history = require base_path('database/migrations/2026_10_08_0006_add_record_approval_to_employee_employment_histories_table.php');
    $history->down();
    $history->down();
    $requests->down();
    $requests->down();
    $requests->up();
    $requests->up();
    $history->up();
    $history->up();
    Schema::table('employee_record_change_requests', static fn ($table) => $table->dropIndex('ercr_status_idx'));
    DB::statement('ALTER TABLE employee_record_change_requests DROP CONSTRAINT ercr_state_chk');
    Schema::table('employee_employment_histories', static fn ($table) => $table->dropForeign(['request_id']));
    Schema::table('employee_employment_histories', static fn ($table) => $table->dropUnique('eehist_request_unq'));
    Schema::table('employee_employment_histories', static fn ($table) => $table->dropColumn('review_note'));
    $requests->up();
    $requests->up();
    $history->up();
    $history->up();
    expect(Schema::hasIndex('employee_record_change_requests', 'ercr_status_idx'))->toBeTrue()
        ->and(Schema::hasIndex('employee_employment_histories', 'eehist_request_unq'))->toBeTrue()
        ->and(Schema::hasForeignKey('employee_employment_histories', ['request_id']))->toBeTrue()
        ->and(Schema::hasColumn('employee_employment_histories', 'review_note'))->toBeTrue();
    [, , $reviewer, $request] = recordApprovalFixture();
    expect(fn () => DB::table('employee_record_change_requests')->where('id', $request->id)->update(['status' => 'UNKNOWN']))
        ->toThrow(QueryException::class, 'ercr_state_chk');
    expect(fn () => DB::table('employee_record_change_requests')->where('id', $request->id)->update(['pending_employee_id' => null]))
        ->toThrow(QueryException::class, 'ercr_state_chk');
    $duplicate = (array) DB::table('employee_record_change_requests')->where('id', $request->id)->first();
    $duplicate['id'] = (string) Str::ulid();
    expect(fn () => DB::table('employee_record_change_requests')->insert($duplicate))->toThrow(QueryException::class, 'ercr_pending_unq');
    app(ReviewRecordChange::class)->handle(Access::forActor($reviewer), $request->id, true);
    $evidence = (array) DB::table('employee_employment_histories')->where('request_id', $request->id)->first();
    $evidence['id'] = (string) Str::ulid();
    expect(fn () => DB::table('employee_employment_histories')->insert($evidence))->toThrow(QueryException::class, 'eehist_request_unq');
});

it('rolls back the official edit and decision when required history storage fails and permits a clean retry', function () {
    [$employee, , $reviewer, $request] = recordApprovalFixture();
    $notifications = Notification::query()->count();
    DB::unprepared("CREATE TRIGGER reject_record_history BEFORE INSERT ON employee_employment_histories FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Synthetic record history failure'");
    try {
        expect(fn () => app(ReviewRecordChange::class)->handle(Access::forActor($reviewer), $request->id, true, 'Confirmed'))
            ->toThrow(QueryException::class, 'Synthetic record history failure');
        expect($employee->fresh()->emergency_name)->toBe('Original Contact')
            ->and($request->fresh()->status)->toBe('PENDING')
            ->and($request->fresh()->reviewed_by)->toBeNull()
            ->and(EmployeeEmploymentHistory::query()->where('request_id', $request->id)->count())->toBe(0)
            ->and(Notification::query()->count())->toBe($notifications);
    } finally {
        DB::unprepared('DROP TRIGGER IF EXISTS reject_record_history');
    }
    app(ReviewRecordChange::class)->handle(Access::forActor($reviewer), $request->id, true, 'Confirmed');
    $history = EmployeeEmploymentHistory::query()->where('request_id', $request->id)->sole();
    expect($employee->fresh()->emergency_name)->toBe('Proposed Contact')
        ->and($request->fresh()->status)->toBe('APPROVED')
        ->and($request->fresh()->reason)->toBe('')
        ->and($history->request_reason)->toBe('')
        ->and($history->review_note)->toBe('Confirmed')
        ->and($history->record_changes[0]['before'])->toBe('Original Contact')
        ->and($history->record_changes[0]['after'])->toBe('Proposed Contact')
        ->and(Notification::query()->count())->toBeGreaterThan($notifications);
});

it('keeps approved history and employee values private until the outer transaction commits', function () {
    [$employee, , $reviewer, $request] = recordApprovalFixture();
    $notifications = Notification::query()->count();
    expect(fn () => DB::transaction(function () use ($reviewer, $request, $notifications): void {
        app(ReviewRecordChange::class)->handle(Access::forActor($reviewer), $request->id, true);
        expect(EmployeeEmploymentHistory::query()->where('request_id', $request->id)->count())->toBe(1)
            ->and(Notification::query()->count())->toBe($notifications);
        throw new RuntimeException('Synthetic outer rollback');
    }))->toThrow(RuntimeException::class, 'Synthetic outer rollback');
    expect($employee->fresh()->emergency_name)->toBe('Original Contact')
        ->and($request->fresh()->status)->toBe('PENDING')
        ->and(EmployeeEmploymentHistory::query()->where('request_id', $request->id)->count())->toBe(0)
        ->and(Notification::query()->count())->toBe($notifications);
});

it('serializes competing reviewers and records one approved change exactly once', function () {
    [$employee, , $reviewer, $request] = recordApprovalFixture();
    $otherReviewer = User::factory()->create(['role_id' => $reviewer->role_id]);
    $worker = recordApprovalWorker($otherReviewer, $request);
    DB::beginTransaction();
    try {
        app(ReviewRecordChange::class)->handle(Access::forActor($reviewer), $request->id, true);
        $worker->start();
        $deadline = microtime(true) + 10;
        do {
            if (str_contains($worker->getOutput(), "ready\n") || ! $worker->isRunning()) {
                break;
            }
            usleep(10000);
        } while (microtime(true) < $deadline);
        if (! str_contains($worker->getOutput(), "ready\n")) {
            throw new RuntimeException('Approval worker did not reach its lock: '.$worker->getErrorOutput().$worker->getOutput());
        }
        expect($worker->isRunning())->toBeTrue();
        DB::commit();
        $worker->wait();
        expect($worker->isSuccessful())->toBeTrue();
        $lines = explode("\n", trim($worker->getOutput()));
        $result = json_decode((string) end($lines), true, 512, JSON_THROW_ON_ERROR);
        expect($result['ok'])->toBeFalse()
            ->and($employee->fresh()->emergency_name)->toBe('Proposed Contact')
            ->and($request->fresh()->reviewed_by)->toBe($reviewer->id)
            ->and(EmployeeEmploymentHistory::query()->where('request_id', $request->id)->count())->toBe(1);
    } finally {
        while (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        if ($worker->isRunning()) {
            $worker->stop();
        }
    }
});

it('compares PDS changes against the HR edit committed while its employee lock was held', function () {
    [$employee, , $reviewer, $request] = recordApprovalFixture(education: true);
    $worker = recordApprovalWorker($reviewer, $request);
    DB::beginTransaction();
    try {
        EmployeeProfile::query()->whereKey($employee->id)->lockForUpdate()->firstOrFail();
        EmployeeEducation::query()->whereKey($request->row_id)->update(['school' => 'New HR School']);
        $worker->start();
        $deadline = microtime(true) + 10;
        do {
            if (str_contains($worker->getOutput(), "ready\n") || ! $worker->isRunning()) {
                break;
            }
            usleep(10000);
        } while (microtime(true) < $deadline);
        if (! str_contains($worker->getOutput(), "ready\n")) {
            throw new RuntimeException('Approval worker did not reach its lock: '.$worker->getErrorOutput().$worker->getOutput());
        }
        DB::commit();
        $worker->wait();
        expect($worker->isSuccessful())->toBeTrue();
        $lines = explode("\n", trim($worker->getOutput()));
        $result = json_decode((string) end($lines), true, 512, JSON_THROW_ON_ERROR);
        expect($result['ok'])->toBeFalse()
            ->and($result['message'])->toContain('changed after this request')
            ->and(EmployeeEducation::query()->findOrFail($request->row_id)->school)->toBe('New HR School')
            ->and($request->fresh()->status)->toBe('PENDING')
            ->and(EmployeeEmploymentHistory::query()->where('request_id', $request->id)->count())->toBe(0);
    } finally {
        while (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        if ($worker->isRunning()) {
            $worker->stop();
        }
    }
});

it('removes management document bytes when the enclosing transaction rolls back', function () {
    Storage::fake('local');
    [$employee, , $reviewer] = recordApprovalFixture();
    $actor = Access::forActor($reviewer);
    DocumentType::create(['code' => 'ID', 'name' => 'Identification']);
    expect(fn () => DB::transaction(function () use ($actor, $employee): void {
        app(AttachDocument::class)->handle($actor, $employee->id, UploadedFile::fake()->create('evidence.pdf', 8, 'application/pdf'),
            ['name' => 'New identification', 'document_no' => '', 'category' => 'Identification', 'expires_on' => '']);
        expect(Storage::disk('local')->allFiles('employee-documents'))->toHaveCount(1);
        throw new RuntimeException('Synthetic file rollback');
    }))->toThrow(RuntimeException::class, 'Synthetic file rollback');
    expect(Storage::disk('local')->allFiles('employee-documents'))->toBe([])
        ->and(EmployeeDocument::query()->count())->toBe(0);
});

it('restores the official portrait and keeps proposal evidence when approval rolls back outside the command', function () {
    Storage::fake('local');
    Storage::fake('public');
    [$employee, $requester, $reviewer, $existing] = recordApprovalFixture();
    $actor = Access::forActor($requester);
    app(WithdrawRecordChange::class)->handle($actor, $existing->id);
    $oldPath = 'portraits/'.$employee->id.'/original.jpg';
    Storage::disk('public')->put($oldPath, 'original portrait bytes');
    $employee->photo_path = $oldPath;
    $employee->save();
    $id = app(SubmitRecordChange::class)->portrait($actor, UploadedFile::fake()->image('new.png', 20, 10), 'Correct my portrait');
    $request = EmployeeRecordChangeRequest::query()->findOrFail($id);
    $privateFiles = Storage::disk('local')->allFiles('employee-record-changes');
    expect(fn () => DB::transaction(function () use ($reviewer, $id, $oldPath): void {
        app(ReviewRecordChange::class)->handle(Access::forActor($reviewer), $id, true);
        Storage::disk('public')->assertExists($oldPath);
        expect(Storage::disk('public')->allFiles('portraits'))->toHaveCount(2);
        throw new RuntimeException('Synthetic portrait rollback');
    }))->toThrow(RuntimeException::class, 'Synthetic portrait rollback');
    expect(Storage::disk('public')->allFiles('portraits'))->toBe([$oldPath])
        ->and(Storage::disk('local')->allFiles('employee-record-changes'))->toBe($privateFiles)
        ->and($employee->fresh()->photo_path)->toBe($oldPath)
        ->and($request->fresh()->status)->toBe('PENDING')
        ->and(EmployeeEmploymentHistory::query()->where('request_id', $id)->count())->toBe(0);
});

it('locks the active manager account and rolls back linked-record edits with their employment history', function () {
    [$employee, , $manager, $request] = recordApprovalFixture();
    $manager->employee_id = $employee->id;
    $manager->save();
    $actor = Access::forActor($manager);
    EmploymentStatusOption::query()->create(['code' => 'REG', 'name' => 'Regular']);
    $employee->work_site_id = WorkSite::query()->create(['name' => 'Management site'])->id;
    $employee->save();
    $observer = DB::build(['name' => 'record-management-observer'] + DB::connection()->getConfig());
    $observer->statement('SET SESSION innodb_lock_wait_timeout = 1');
    $beforeRate = $employee->refresh()->monthly_rate;
    DB::beginTransaction();
    try {
        app(UpdateEmployee::class)->handle($actor, $employee->id, 'employment', ['monthly_rate' => '42000'] + EmployeeFields::toForm($employee));
        expect($employee->fresh()->monthly_rate)->toBe(42000.0)
            ->and(EmployeeEmploymentHistory::query()->count())->toBe(1)
            ->and($observer->table('employee_employment_histories')->count())->toBe(0)
            ->and((float) $observer->table('employee_profiles')->where('id', $employee->id)->value('monthly_rate'))->toBe($beforeRate);
        expect(fn () => $observer->table('users')->where('id', $manager->id)->update(['is_active' => false]))
            ->toThrow(QueryException::class, 'Lock wait timeout');
    } finally {
        DB::rollBack();
        DB::purge('record-management-observer');
    }
    expect($employee->fresh()->monthly_rate)->toBe($beforeRate)
        ->and(EmployeeEmploymentHistory::query()->count())->toBe(0)
        ->and($request->fresh()->status)->toBe('PENDING')
        ->and($manager->fresh()->is_active)->toBeTrue();
});
