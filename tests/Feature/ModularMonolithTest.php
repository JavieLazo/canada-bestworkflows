<?php

declare(strict_types=1);

use App\Actions\Fortify\ResetUserPassword;
use App\Domain\Attendance\AttendanceLog as DomainLog;
use App\Domain\Attendance\DtrCalculator;
use App\Livewire\Edtr\EdtrPage;
use App\Livewire\Employees\Documents;
use App\Livewire\Leaves\Index as LeavesPage;
use App\Livewire\Payroll\Support\PayrollData;
use App\Livewire\Users\Index as UsersPage;
use App\Models\AttendanceLog;
use App\Models\AttendanceRemark;
use App\Models\AuditLog;
use App\Models\DocumentType;
use App\Models\EmployeeDocument;
use App\Models\EmployeeProfile;
use App\Models\LeaveTravelRequest;
use App\Models\Project;
use App\Models\ProjectTask;
use App\Models\Role;
use App\Models\RoleModuleGrant;
use App\Models\Shift;
use App\Models\ShiftType;
use App\Models\TaskComment;
use App\Models\User;
use App\Modules\IdentityAccess\Application\Commands\ResetAccountPassword;
use App\Modules\IdentityAccess\Application\Queries\GetAccounts;
use App\Modules\IdentityAccess\Infrastructure\PasswordWriter;
use App\Modules\Payroll\Application\Commands\FinalizePayroll;
use App\Modules\Timekeeping\Application\Commands\AssignShift;
use App\Modules\Timekeeping\Application\Commands\CorrectPunches;
use App\Modules\Timekeeping\Application\Commands\DecideOvertime;
use App\Modules\Timekeeping\Application\Commands\DecidePendingOvertime;
use App\Modules\Timekeeping\Application\Commands\FileAbsence;
use App\Modules\Timekeeping\Application\Commands\ReviewAbsence;
use App\Modules\Timekeeping\Application\Commands\WithdrawAbsence;
use App\Modules\Timekeeping\Contracts\AttendanceSource;
use App\Modules\Timekeeping\Domain\Attendance\PunchSlots;
use App\Modules\Timekeeping\Infrastructure\ShiftRoster;
use App\Modules\Workforce\Contracts\EmployeeDirectory;
use App\Modules\Workforce\Contracts\EmployeePayContexts;
use App\Modules\WorkManagement\Application\Commands\ReviewTask;
use App\Modules\WorkManagement\Application\Commands\ReviewTaskResponse;
use App\Platform\Files\AttachmentStore;
use App\Support\Access;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\ScheduleFixture;

/** Regression coverage for module boundaries, failure recovery, and preserved historical behavior. */
/** @param list<string> $modules */
function assessmentActor(array $modules, bool $allData = true): User
{
    $role = Role::create(['name' => 'Assessment role', 'all_data' => $allData, 'is_system' => false]);
    foreach ($modules as $module) {
        RoleModuleGrant::create(['role_id' => $role->id, 'module' => $module]);
    }
    $user = User::factory()->create(['role_id' => $role->id]);
    test()->actingAs($user);

    return $user;
}

function assessmentEmployee(): EmployeeProfile
{
    return EmployeeProfile::create([
        'employee_code' => 'EMP-ASSESS', 'first_name' => 'Assessment', 'last_name' => 'Employee',
        'employment_status' => 'Regular', 'date_hired' => '2024-01-01',
        'monthly_rate' => 30000, 'is_active' => true,
    ]);
}

function assessmentPattern(string $code, string $start, string $end): ShiftType
{
    return ShiftType::create([
        'code' => $code, 'name' => $code, 'mode' => 'FIXED', 'work_days' => [1, 2, 3, 4, 5],
        'start_time' => $start, 'end_time' => $end, 'grace_minutes' => 0,
        'break_minutes' => 0, 'requires_break_punch' => false,
    ]);
}

it('blocks fresh account data on a real Livewire request after USERS is revoked', function () {
    $actor = assessmentActor(['USERS', 'PROFILE']);
    $page = $this->get('/users')->assertOk();
    $document = new DOMDocument;
    $previous = libxml_use_internal_errors(true);
    $document->loadHTML((string) $page->getContent());
    libxml_clear_errors();
    libxml_use_internal_errors($previous);
    $snapshots = (new DOMXPath($document))->query('//*[@*[name()="wire:snapshot"]]');
    if ($snapshots === false) {
        throw new LogicException('The snapshot query is invalid.');
    }
    $snapshot = null;
    foreach ($snapshots as $node) {
        if (! $node instanceof DOMElement) {
            continue;
        }
        $candidate = $node->getAttribute('wire:snapshot');
        $decoded = json_decode($candidate, true, 512, JSON_THROW_ON_ERROR);
        if (array_key_exists('createEmail', $decoded['data'] ?? [])) {
            $snapshot = $candidate;
            break;
        }
    }
    expect($snapshot)->not->toBeNull();
    RoleModuleGrant::where('role_id', $actor->role_id)->where('module', 'USERS')->delete();
    $actor->unsetRelation('role');
    app()->forgetInstance(Access::class);
    User::factory()->create(['name' => 'Created After Permission Revocation']);
    $this->get('/users')->assertRedirect();
    $response = $this->postJson(Livewire::getUpdateUri(), [
        'components' => [['snapshot' => $snapshot, 'updates' => [],
            'calls' => [['path' => '', 'method' => '$refresh', 'params' => []]]]],
    ], ['X-Livewire' => 'true']);
    expect($response->getStatusCode())->toBeIn([200, 302, 403]);
    expect($response->getContent())->not->toContain('Created After Permission Revocation');
});

it('reauthorizes account queries even without route middleware', function () {
    $actor = assessmentActor(['USERS']);
    expect(app(GetAccounts::class)->handle(Access::forActor($actor))['accounts'])->not->toBeEmpty();
    RoleModuleGrant::where('role_id', $actor->role_id)->delete();
    $actor->unsetRelation('role');
    $listing = app(GetAccounts::class)->handle(Access::forActor($actor));
    expect($listing['accounts'])->toBeEmpty();
    expect($listing['roles'])->toBeEmpty();
    expect($listing['grants'])->toBeEmpty();
});

it('rejects direct module commands from an actor without their grants', function () {
    $actor = Access::forActor(assessmentActor([]));
    expect(fn () => app(FileAbsence::class)->handle($actor, 'VACATION_LEAVE', 'WHOLE_DAY', '2026-08-03', '2026-08-03', ''))
        ->toThrow(RuntimeException::class, 'You do not have access to that.');
    expect(fn () => app(WithdrawAbsence::class)->handle($actor, 'missing'))
        ->toThrow(RuntimeException::class, 'You do not have access to that.');
    expect(fn () => app(ReviewAbsence::class)->handle($actor, 'missing', 'APPROVED', true))
        ->toThrow(RuntimeException::class, 'Your role cannot manage organization-wide data.');
    expect(fn () => app(FinalizePayroll::class)->handle($actor, 'missing'))
        ->toThrow(RuntimeException::class, 'Your role cannot manage organization-wide data.');
    expect(fn () => app(ReviewTask::class)->handle($actor, 'missing', 'missing', 'COMPLETED', ''))
        ->toThrow(RuntimeException::class, 'You do not have access to that.');
    expect(fn () => app(ReviewTaskResponse::class)->handle($actor, 'missing', 'missing', 'missing', 'APPROVED', '', 0))
        ->toThrow(RuntimeException::class, 'You do not have access to that.');
    expect(fn () => app(ResetAccountPassword::class)->handle($actor, '1', 'New-Password#2026', 'New-Password#2026'))
        ->toThrow(RuntimeException::class, 'You do not have access to that.');
    expect(fn () => app(DecideOvertime::class)->handle($actor, 'missing', '2026-08-03', 'APPROVED', '60'))
        ->toThrow(RuntimeException::class, 'You are not allowed to decide overtime.');
    expect(fn () => app(DecidePendingOvertime::class)->handle($actor, 'missing', '2026-08-01', '2026-08-15', 'REJECTED'))
        ->toThrow(RuntimeException::class, 'You are not allowed to decide overtime.');
});

it('does not expose another employees payroll inputs to own data or unlinked accounts', function () {
    $actor = assessmentActor(['PAYROLL'], false);
    $employee = assessmentEmployee();
    $access = Access::forActor($actor);
    expect(fn () => app(EmployeePayContexts::class)->get($access, [$employee->id]))
        ->toThrow(HttpException::class);
    expect(fn () => app(AttendanceSource::class)->forPayroll($access, [$employee->id], '2026-08-01', '2026-08-15'))
        ->toThrow(HttpException::class);
    $actor->forceFill(['employee_id' => $employee->id])->save();
    $own = Access::forActor($actor);
    expect(app(EmployeePayContexts::class)->get($own, [$employee->id]))->toHaveCount(1);
    expect(fn () => app(AttendanceSource::class)->forPayroll($own, ['someone-else'], '2026-08-01', '2026-08-15'))
        ->toThrow(HttpException::class);
});

it('scopes the absence employee lookup inside Workforce to an authorized linked actor', function () {
    $employee = assessmentEmployee();
    $user = assessmentActor(['PORTAL']);
    $directory = app(EmployeeDirectory::class);
    expect(fn () => $directory->isActiveForAbsence(Access::forActor($user)))
        ->toThrow(HttpException::class);
    $user->forceFill(['employee_id' => $employee->id])->save();
    expect($directory->isActiveForAbsence(Access::forActor($user)))->toBeTrue();
    $employee->update(['is_active' => false]);
    expect($directory->isActiveForAbsence(Access::forActor($user)))->toBeFalse();
    RoleModuleGrant::where('role_id', $user->role_id)->delete();
    $user->unsetRelation('role');
    expect(fn () => $directory->isActiveForAbsence(Access::forActor($user)))
        ->toThrow(HttpException::class);
});

it('returns identical attendance facts to authorized DTR and payroll without private leave reasons', function () {
    $actor = assessmentActor(['EDTR', 'PAYROLL']);
    $employee = assessmentEmployee();
    LeaveTravelRequest::create([
        'code' => 'LR-FACTS', 'employee_id' => $employee->id, 'request_type' => 'VACATION_LEAVE',
        'day_type' => 'WHOLE_DAY', 'start_date' => '2026-08-03', 'end_date' => '2026-08-03',
        'status' => 'APPROVED', 'is_paid' => true, 'reason' => 'Private explanation',
    ]);
    $source = app(AttendanceSource::class);
    $access = Access::forActor($actor);
    $dtr = $source->forDtr($access, $employee->id, '2026-08-01', '2026-08-15');
    $payroll = $source->forPayroll($access, [$employee->id], '2026-08-01', '2026-08-15')[$employee->id];
    expect($dtr)->toEqual($payroll);
    expect(array_keys(get_object_vars($dtr->leaves[0])))->not->toContain('reason');
    expect(array_map(static fn (ReflectionMethod $method): string => $method->getName(), (new ReflectionClass($dtr->leaves[0]))->getMethods()))->not->toContain('save');
});

it('rolls back a task decision when its review comment cannot be stored', function () {
    $actor = assessmentActor(['PROJECTS']);
    $project = Project::create(['code' => 'PRJ-ATOMIC', 'name' => 'Atomic review', 'status' => 'ACTIVE', 'start_date' => '2026-08-01']);
    $task = ProjectTask::create(['code' => 'TSK-ATOMIC', 'project_id' => $project->id, 'title' => 'Review', 'status' => 'SUBMITTED', 'priority' => 'NORMAL']);
    TaskComment::creating(static function (): void {
        throw new RuntimeException('review comment failed');
    });
    expect(fn () => app(ReviewTask::class)->handle(Access::forActor($actor), $project->id, $task->id, 'RETURNED', 'Please revise'))
        ->toThrow(RuntimeException::class, 'review comment failed');
    expect($task->fresh()->status)->toBe('SUBMITTED');
    expect(TaskComment::count())->toBe(0);
    expect(AuditLog::where('entity_id', $task->id)->count())->toBe(0);
});

it('does not delete an existing attachment when a storage name collides', function () {
    Storage::fake('local');
    Storage::disk('local')->put('task-files/existing', 'original evidence');
    expect(fn () => app(AttachmentStore::class)->store(UploadedFile::fake()->create('new.pdf', 1), 'task-files', 'existing', static fn (string $path): string => $path))
        ->toThrow(RuntimeException::class, 'That storage path is already in use.');
    expect(Storage::disk('local')->get('task-files/existing'))->toBe('original evidence');
});

it('compensates private task bytes when metadata persistence throws', function () {
    Storage::fake('local');
    expect(fn () => app(AttachmentStore::class)->store(UploadedFile::fake()->create('new.pdf', 1), 'task-files', 'new-evidence', static function (string $path): never {
        throw new RuntimeException('metadata failed');
    }))->toThrow(RuntimeException::class, 'metadata failed');
    expect(Storage::disk('local')->allFiles('task-files'))->toBe([]);
});

it('preserves the current session and unrelated accounts while replacing a credential', function () {
    $actor = assessmentActor(['PROFILE']);
    $other = User::factory()->create();
    foreach (['current' => $actor->id, 'old' => $actor->id, 'unrelated' => $other->id] as $id => $userId) {
        DB::table('sessions')->insert(['id' => $id, 'user_id' => $userId, 'payload' => '', 'last_activity' => time()]);
    }
    $remember = $actor->remember_token;
    app(PasswordWriter::class)->replace($actor, 'New-Password#2026', 'current');
    expect(DB::table('sessions')->orderBy('id')->pluck('id')->all())->toBe(['current', 'unrelated']);
    expect($actor->fresh()->remember_token)->not->toBe($remember);
});

it('revokes database sessions through the password recovery action', function () {
    $actor = assessmentActor(['PROFILE']);
    DB::table('sessions')->insert(['id' => 'recovered', 'user_id' => $actor->id, 'payload' => '', 'last_activity' => time()]);
    app(ResetUserPassword::class)->reset($actor, [
        'password' => 'Recovered-Password#2026', 'password_confirmation' => 'Recovered-Password#2026',
    ]);
    expect(DB::table('sessions')->where('id', 'recovered')->exists())->toBeFalse();
    expect(Hash::check('Recovered-Password#2026', $actor->fresh()->password))->toBeTrue();
});

it('revokes other database sessions through the security settings screen', function () {
    $actor = assessmentActor(['PROFILE']);
    $actor->password = 'original-password';
    $actor->save();
    DB::table('sessions')->insert(['id' => 'old-settings', 'user_id' => $actor->id, 'payload' => '', 'last_activity' => time()]);
    Livewire::test('pages::settings.security')
        ->set('current_password', 'original-password')->set('password', 'New-Password#2026')
        ->set('password_confirmation', 'New-Password#2026')->call('updatePassword')->assertHasNoErrors();
    expect(DB::table('sessions')->where('id', 'old-settings')->exists())->toBeFalse();
});

it('revokes an existing database session after an administrative password reset', function () {
    assessmentActor(['USERS']);
    $target = User::factory()->create(['password' => 'original-password']);
    DB::table('sessions')->insert([
        'id' => 'assessment-existing-session', 'user_id' => $target->id,
        'payload' => base64_encode(serialize([])), 'last_activity' => time(),
    ]);
    Livewire::test(UsersPage::class)->call('openAccount', (string) $target->id)
        ->set('resetPassword', 'Assessment-Password#2026')
        ->set('resetPasswordConfirm', 'Assessment-Password#2026')->call('resetAccountPassword');
    expect(Hash::check('Assessment-Password#2026', $target->fresh()->password))->toBeTrue();
    expect(DB::table('sessions')->where('id', 'assessment-existing-session')->exists())->toBeFalse();
});

it('cleans up an uploaded file when employee document metadata fails', function () {
    assessmentActor(['EMPLOYEES']);
    $employee = assessmentEmployee();
    DocumentType::create(['code' => 'DOC-ASSESS', 'name' => 'Assessment category']);
    Storage::fake('local');
    $component = Livewire::test(Documents::class, ['employeeId' => $employee->id])
        ->set('form.category', 'Assessment category')
        ->set('upload', UploadedFile::fake()->create('assessment.pdf', 1, 'application/pdf'));
    EmployeeDocument::creating(static function (): void {
        throw new RuntimeException('assessment metadata failure');
    });
    $component->call('submit');
    expect(EmployeeDocument::count())->toBe(0);
    expect(Storage::disk('local')->allFiles('employee-documents'))->toHaveCount(0);
});

it('judges each date by the roster that applied to it now that effective dated scheduling is introduced', function () {
    // Schedules are effective-dated: a later assignment adds a row from its
    // date and never re-judges an earlier one.
    assessmentActor(['ROSTER']);
    $employee = assessmentEmployee();
    $day = assessmentPattern('PAT-A', '08:00:00', '16:00:00');
    $early = assessmentPattern('PAT-B', '07:00:00', '15:00:00');
    $logs = DomainLog::listFromArrays([
        ['log_type' => 'TIME_IN', 'logged_at' => '2026-08-03T08:00:00+08:00'],
        ['log_type' => 'TIME_OUT', 'logged_at' => '2026-08-03T16:00:00+08:00'],
        ['log_type' => 'TIME_IN', 'logged_at' => '2026-08-04T08:00:00+08:00'],
        ['log_type' => 'TIME_OUT', 'logged_at' => '2026-08-04T16:00:00+08:00'],
    ]);
    $this->travelTo(new DateTimeImmutable('2026-08-03T06:00:00+08:00'));
    app(AssignShift::class)->handle(app(Access::class), $employee->id, $day->id);
    $before = DtrCalculator::compute($logs, app(ShiftRoster::class)->scheduleForEmployee($employee->id, '2026-08-02', '2026-08-05'), [], '2026-08-03', '2026-08-04');
    $this->travelTo(new DateTimeImmutable('2026-08-04T06:00:00+08:00'));
    app(AssignShift::class)->handle(app(Access::class), $employee->id, $early->id);
    $after = DtrCalculator::compute($logs, app(ShiftRoster::class)->scheduleForEmployee($employee->id, '2026-08-02', '2026-08-05'), [], '2026-08-03', '2026-08-04');
    expect(Shift::where('employee_id', $employee->id)->count())->toBe(2);
    expect($before[0]->lateMinutes)->toBe(0.0)->and($before[1]->lateMinutes)->toBe(0.0);
    // The earlier date keeps its hours; the date the change took effect uses the new ones.
    expect($after[0]->lateMinutes)->toBe(0.0)->and($after[1]->lateMinutes)->toBe(60.0);
});

it('preserves current salary semantics until effective dated compensation is explicitly introduced', function () {
    assessmentActor(['PAYROLL']);
    $this->travelTo(new DateTimeImmutable('2026-08-20T12:00:00+08:00'));
    $employee = assessmentEmployee();
    foreach (['TIME_IN' => '08:00:00', 'TIME_OUT' => '17:00:00'] as $type => $time) {
        AttendanceLog::create([
            'employee_id' => $employee->id, 'log_type' => $type, 'source' => 'MANUAL',
            'logged_at' => CarbonImmutable::parse('2026-08-03T'.$time.'+08:00')->utc(),
        ]);
    }
    $before = PayrollData::calculateDrafts([$employee->id], '2026-08-01', '2026-08-15')['drafts'][0];
    $employee->update(['monthly_rate' => 60000]);
    $after = PayrollData::calculateDrafts([$employee->id], '2026-08-01', '2026-08-15')['drafts'][0];
    expect($before->gross_pay)->toBeGreaterThan(0);
    expect($after->gross_pay)->toBeGreaterThan($before->gross_pay);
});

it('includes approved absence facts in an authorized DTR without requiring leave management access', function () {
    $this->travelTo(new DateTimeImmutable('2026-08-20T12:00:00+08:00'));
    $actor = assessmentActor(['EDTR']);
    $employee = assessmentEmployee();
    LeaveTravelRequest::create([
        'code' => 'LR-ASSESS', 'employee_id' => $employee->id, 'request_type' => 'VACATION_LEAVE',
        'day_type' => 'WHOLE_DAY', 'start_date' => '2026-08-03', 'end_date' => '2026-08-03',
        'status' => 'APPROVED', 'is_paid' => true,
    ]);
    $component = Livewire::test(EdtrPage::class)->set('start', '2026-08-03')->set('end', '2026-08-03')
        ->set('tab', 'dtr')->call('employeePicked', 'dtr-employee', $employee->id);
    $before = $component->viewData('rows')[0];
    RoleModuleGrant::create(['role_id' => $actor->role_id, 'module' => 'LEAVES']);
    $actor->unsetRelation('role');
    app()->forgetInstance(Access::class);
    $component->call('$refresh');
    $after = $component->viewData('rows')[0];
    expect($before->status)->toBe('EXCUSED');
    expect($after->status)->toBe('EXCUSED');
});

it('preserves best effort auditing until its durability policy is explicitly changed', function () {
    assessmentActor(['LEAVES']);
    $employee = assessmentEmployee();
    ScheduleFixture::standard($employee);
    $leave = LeaveTravelRequest::create([
        'code' => 'LR-AUDIT', 'employee_id' => $employee->id, 'request_type' => 'VACATION_LEAVE',
        'day_type' => 'WHOLE_DAY', 'start_date' => '2026-08-03', 'end_date' => '2026-08-03',
        'status' => 'PENDING',
    ]);
    AuditLog::creating(static function (): void {
        throw new RuntimeException('assessment audit failure');
    });
    Livewire::test(LeavesPage::class)->call('show', $leave->id)->call('review', 'APPROVED', true);
    expect($leave->fresh()->status)->toBe('APPROVED');
    expect(AuditLog::where('entity_id', $leave->id)->count())->toBe(0);
});

it('rolls back punch replacements when the correction note cannot be written', function () {
    $actor = assessmentActor(['EDTR']);
    $employee = assessmentEmployee();
    $old = AttendanceLog::create([
        'employee_id' => $employee->id, 'log_type' => 'TIME_IN', 'source' => 'MANUAL',
        'logged_at' => CarbonImmutable::parse('2026-08-03T08:15:00+08:00')->utc(),
    ]);
    AttendanceRemark::saving(static function (): void {
        throw new RuntimeException('assessment correction-note failure');
    });
    expect(fn () => CorrectPunches::apply(Access::forActor($actor), $employee->id, '2026-08-03', [
        'time_in' => '08:00', 'break_out' => null, 'break_in' => null, 'time_out' => '17:00',
    ], PunchSlots::SAME_DAY, 'Assessment correction'))->toThrow(RuntimeException::class, 'assessment correction-note failure');
    expect(AttendanceLog::whereKey($old->id)->exists())->toBeTrue();
    expect(AttendanceLog::where('employee_id', $employee->id)->count())->toBe(1);
    expect(AttendanceRemark::count())->toBe(0);
});
