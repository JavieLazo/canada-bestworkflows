<?php

declare(strict_types=1);

use App\Domain\Enums\ModuleCode;
use App\Models\AuditLog;
use App\Models\EmployeeEducation;
use App\Models\EmployeeEmploymentHistory;
use App\Models\EmployeeProfile;
use App\Models\EmployeeRecordChangeRequest;
use App\Models\EmploymentStatusOption;
use App\Models\Role;
use App\Models\RoleModuleGrant;
use App\Models\User;
use App\Models\WorkSite;
use App\Modules\Workforce\Application\Commands\DeletePdsRow;
use App\Modules\Workforce\Application\Commands\ReviewRecordChange;
use App\Modules\Workforce\Application\Commands\SavePdsRow;
use App\Modules\Workforce\Application\Commands\SubmitRecordChange;
use App\Modules\Workforce\Application\Commands\UpdateEmployee;
use App\Modules\Workforce\Application\Commands\UpdateOwnProfile;
use App\Modules\Workforce\Application\Commands\UpdatePortrait;
use App\Modules\Workforce\Application\Commands\WithdrawRecordChange;
use App\Modules\Workforce\Application\EmployeeFields;
use App\Modules\Workforce\Application\Queries\RecordChangeRequests;
use App\Modules\Workforce\Application\RecordChangeData;
use App\Modules\Workforce\Domain\Records\OwnRecordFields;
use App\Support\Access;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** Profile proposals need independent review; authorized Employee Records management edits save directly. */
function ercrEmployee(): EmployeeProfile
{
    return EmployeeProfile::query()->create([
        'employee_code' => 'RCR-'.Str::random(8), 'first_name' => 'Ana', 'last_name' => 'Santos',
        'date_hired' => '2024-01-01', 'employment_status' => 'Regular', 'is_active' => true,
        'monthly_rate' => 30000, 'emergency_name' => 'Original Contact',
    ]);
}

/** @param list<string> $modules */
function ercrUser(?EmployeeProfile $employee = null, bool $all = false, array $modules = [ModuleCode::PROFILE]): User
{
    $role = Role::query()->create(['name' => 'Requests '.Str::random(10), 'all_data' => $all, 'is_system' => false]);
    foreach ($modules as $module) {
        RoleModuleGrant::query()->create(['role_id' => $role->id, 'module' => $module]);
    }

    return User::factory()->create(['role_id' => $role->id, 'employee_id' => $employee?->id]);
}

function ercrReviewer(): Access
{
    return Access::forActor(ercrUser(all: true, modules: [ModuleCode::EMPLOYEES]));
}

function ercrSubmit(Access $actor, string $name = 'Requested Contact'): string
{
    return app(SubmitRecordChange::class)->section($actor, 'emergency', [
        'emergency_name' => $name, 'emergency_relation' => '', 'emergency_phone' => '', 'emergency_address' => '',
    ], 'Correct my emergency contact');
}

it('keeps the official record unchanged until approval and freezes both actors and the changed values', function () {
    $employee = ercrEmployee();
    $actor = Access::forActor(ercrUser($employee));
    $id = ercrSubmit($actor);
    expect($employee->refresh()->emergency_name)->toBe('Original Contact')
        ->and(EmployeeEmploymentHistory::query()->count())->toBe(0);
    $request = EmployeeRecordChangeRequest::query()->findOrFail($id);
    expect($request->baseline)->toBe(['emergency_name' => 'Original Contact'])
        ->and($request->proposed)->toBe(['emergency_name' => 'Requested Contact'])
        ->and($request->requested_by)->toBe((int) $actor->userId);
    $reviewer = ercrReviewer();
    app(ReviewRecordChange::class)->handle($reviewer, $id, true, 'Verified');
    expect($employee->refresh()->emergency_name)->toBe('Requested Contact')
        ->and($request->refresh()->status)->toBe('APPROVED')
        ->and($request->reviewed_by)->toBe((int) $reviewer->userId)
        ->and($request->pending_employee_id)->toBeNull()
        ->and(EmployeeEmploymentHistory::query()->count())->toBe(1);
    $history = EmployeeEmploymentHistory::query()->sole();
    expect($history->getAttribute('request_id'))->toBe($id)
        ->and($history->getAttribute('request_reason'))->toBe('Correct my emergency contact')
        ->and($history->getAttribute('record_changes')[0])->toMatchArray(['label' => 'Full Name', 'before' => 'Original Contact', 'after' => 'Requested Contact']);
    expect(fn () => app(ReviewRecordChange::class)->handle($reviewer, $id, true))->toThrow(RuntimeException::class, 'already been resolved');
    expect(EmployeeEmploymentHistory::query()->count())->toBe(1);
});

it('submits and approves a section without requiring a reason', function () {
    $employee = ercrEmployee();
    $actor = Access::forActor(ercrUser($employee));
    $id = app(SubmitRecordChange::class)->section($actor, 'emergency', [
        'emergency_name' => 'Requested Contact', 'emergency_relation' => '', 'emergency_phone' => '', 'emergency_address' => '',
    ]);
    $request = EmployeeRecordChangeRequest::query()->findOrFail($id);
    expect($request->reason)->toBe('')->and($request->status)->toBe(EmployeeRecordChangeRequest::PENDING)
        ->and($employee->refresh()->emergency_name)->toBe('Original Contact');
    app(ReviewRecordChange::class)->handle(ercrReviewer(), $id, true);
    expect($request->refresh()->status)->toBe(EmployeeRecordChangeRequest::APPROVED)
        ->and($employee->refresh()->emergency_name)->toBe('Requested Contact')
        ->and(EmployeeEmploymentHistory::query()->sole()->request_reason)->toBe('');
});

it('accepts a blank supplied reason and still caps optional historical reason text', function () {
    $employee = ercrEmployee();
    $actor = Access::forActor(ercrUser($employee));
    $form = ['emergency_name' => 'Requested Contact', 'emergency_relation' => '', 'emergency_phone' => '', 'emergency_address' => ''];
    expect(fn () => app(SubmitRecordChange::class)->section($actor, 'emergency', $form, str_repeat('a', 1001)))
        ->toThrow(RuntimeException::class, 'at most 1,000');
    expect(EmployeeRecordChangeRequest::query()->count())->toBe(0)
        ->and($employee->refresh()->emergency_name)->toBe('Original Contact');
    $id = app(SubmitRecordChange::class)->section($actor, 'emergency', $form, '   ');
    expect(EmployeeRecordChangeRequest::query()->findOrFail($id)->reason)->toBe('');
});

it('keeps one pending request per employee across accounts and permits withdrawal without changing records', function () {
    $employee = ercrEmployee();
    $first = Access::forActor(ercrUser($employee));
    $second = Access::forActor(ercrUser($employee));
    $id = ercrSubmit($first);
    expect(fn () => ercrSubmit($second, 'Other proposal'))->toThrow(RuntimeException::class, 'already has a pending');
    app(WithdrawRecordChange::class)->handle($second, $id);
    expect(EmployeeRecordChangeRequest::query()->findOrFail($id)->status)->toBe('WITHDRAWN')
        ->and($employee->refresh()->emergency_name)->toBe('Original Contact')
        ->and(EmployeeEmploymentHistory::query()->count())->toBe(0);
    expect(ercrSubmit($second, 'Replacement request'))->not->toBe($id);
});

it('rejects with a required reason and keeps the official record and employment history untouched', function () {
    $employee = ercrEmployee();
    $id = ercrSubmit(Access::forActor(ercrUser($employee)));
    $reviewer = ercrReviewer();
    expect(fn () => app(ReviewRecordChange::class)->handle($reviewer, $id, false))->toThrow(RuntimeException::class, 'rejection needs a reason');
    app(ReviewRecordChange::class)->handle($reviewer, $id, false, 'Please supply the correct contact.');
    expect(EmployeeRecordChangeRequest::query()->findOrFail($id)->status)->toBe('REJECTED')
        ->and($employee->refresh()->emergency_name)->toBe('Original Contact')
        ->and(EmployeeEmploymentHistory::query()->count())->toBe(0);
});

it('refuses self approval through the requesting account or another account for the same employee', function () {
    $employee = ercrEmployee();
    $owner = Access::forActor(ercrUser($employee, true, [ModuleCode::PROFILE, ModuleCode::EMPLOYEES]));
    $id = ercrSubmit($owner);
    $otherAccount = Access::forActor(ercrUser($employee, true, [ModuleCode::EMPLOYEES]));
    foreach ([$owner, $otherAccount, Access::forActor(ercrUser())] as $reviewer) {
        expect(fn () => app(ReviewRecordChange::class)->handle($reviewer, $id, true))->toThrow(HttpException::class);
    }
    expect(EmployeeRecordChangeRequest::query()->findOrFail($id)->status)->toBe('PENDING')
        ->and($employee->refresh()->emergency_name)->toBe('Original Contact');
});

it('rejects stale affected values while preserving unrelated current fields', function () {
    $employee = ercrEmployee();
    $actor = Access::forActor(ercrUser($employee));
    $id = ercrSubmit($actor);
    $employee->emergency_address = 'Management entered address';
    $employee->save();
    app(ReviewRecordChange::class)->handle(ercrReviewer(), $id, true);
    expect($employee->refresh()->emergency_address)->toBe('Management entered address');
    $next = ercrSubmit($actor, 'Later proposal');
    $employee->emergency_name = 'Management correction';
    $employee->save();
    expect(fn () => app(ReviewRecordChange::class)->handle(ercrReviewer(), $next, true))->toThrow(RuntimeException::class, RecordChangeData::STALE);
    expect($employee->refresh()->emergency_name)->toBe('Management correction')
        ->and(EmployeeRecordChangeRequest::query()->findOrFail($next)->status)->toBe('PENDING');
});

it('rejects an unlinked submission and rechecks the submitting account link and activity before approval', function () {
    expect(fn () => ercrSubmit(Access::forActor(ercrUser())))->toThrow(HttpException::class);
    $employee = ercrEmployee();
    $user = ercrUser($employee);
    $id = ercrSubmit(Access::forActor($user));
    $user->employee_id = ercrEmployee()->id;
    $user->save();
    expect(fn () => app(ReviewRecordChange::class)->handle(ercrReviewer(), $id, true))->toThrow(RuntimeException::class, 'no longer active and linked');
    $user->employee_id = $employee->id;
    $user->is_active = false;
    $user->save();
    expect(fn () => app(ReviewRecordChange::class)->handle(ercrReviewer(), $id, true))->toThrow(RuntimeException::class, 'no longer active and linked');
    expect($employee->refresh()->emergency_name)->toBe('Original Contact');
});

it('refuses direct own-record writes without Employee Records management access', function (bool $all, string $module) {
    Storage::fake('public');
    $employee = ercrEmployee();
    $actor = Access::forActor(ercrUser($employee, $all, [$module]));
    foreach ([
        fn () => app(UpdateEmployee::class)->handle($actor, $employee->id, 'emergency', []),
        fn () => app(UpdatePortrait::class)->handle($actor, $employee->id, null),
        fn () => app(SavePdsRow::class)->handle($actor, $employee->id, 'education', null, []),
        fn () => app(DeletePdsRow::class)->handle($actor, $employee->id, 'education', 'missing'),
    ] as $action) {
        expect($action)->toThrow(HttpException::class);
    }
    expect(fn () => app(UpdateOwnProfile::class)->personal($actor, []))->toThrow(RuntimeException::class, 'Submit a record change request');
    expect($employee->refresh()->emergency_name)->toBe('Original Contact')
        ->and(EmployeeRecordChangeRequest::query()->count())->toBe(0);
})->with([
    'profile all-data only' => [true, ModuleCode::PROFILE],
    'employee records own scope' => [false, ModuleCode::EMPLOYEES],
]);

it('approves personal changes while preserving an omitted employee ID and unrelated management values', function () {
    $employee = ercrEmployee();
    $employee->employee_id_no = 'HR-ORIGINAL';
    $employee->save();
    $actor = Access::forActor(ercrUser($employee));
    $form = ['last_name' => 'Reyes', 'monthly_rate' => '99999', 'employee_id' => 'forged'] + EmployeeFields::toForm($employee);
    unset($form['employee_id_no']);
    $id = app(SubmitRecordChange::class)->section($actor, 'personal', $form, 'Legal name correction');
    $request = EmployeeRecordChangeRequest::query()->findOrFail($id);
    expect($request->proposed)->toBe(['last_name' => 'Reyes'])
        ->and($request->form)->not->toHaveKey('employee_id_no')
        ->and($employee->refresh()->employee_id_no)->toBe('HR-ORIGINAL');
    $employee->employee_id_no = 'HR-CURRENT';
    $employee->save();
    app(ReviewRecordChange::class)->handle(ercrReviewer(), $id, true);
    expect($employee->refresh()->last_name)->toBe('Reyes')
        ->and($employee->employee_id_no)->toBe('HR-CURRENT')
        ->and($employee->monthly_rate)->toBe(30000.0)
        ->and(EmployeeEmploymentHistory::query()->count())->toBe(1);
});

it('refuses own Employee Details proposals regardless of the account module or all-data privilege', function (bool $all, string $module) {
    $employee = ercrEmployee();
    $actor = Access::forActor(ercrUser($employee, $all, [$module]));
    expect(fn () => app(SubmitRecordChange::class)->section($actor, 'employment', ['monthly_rate' => '31000'] + EmployeeFields::toForm($employee), 'Correct rate'))
        ->toThrow(RuntimeException::class, OwnRecordFields::SECTION_REFUSAL);
    expect($employee->refresh()->monthly_rate)->toBe(30000.0)
        ->and(EmployeeRecordChangeRequest::query()->count())->toBe(0)
        ->and(EmployeeEmploymentHistory::query()->count())->toBe(0)
        ->and(AuditLog::query()->count())->toBe(0);
})->with([
    'profile employee' => [false, ModuleCode::PROFILE],
    'profile all-data' => [true, ModuleCode::PROFILE],
    'employee-record all-data' => [true, ModuleCode::EMPLOYEES],
]);

it('refuses a tampered Employee ID in an own personal proposal', function (bool $all, string $module) {
    $employee = ercrEmployee();
    $employee->employee_id_no = 'HR-ORIGINAL';
    $employee->save();
    $actor = Access::forActor(ercrUser($employee, $all, [$module]));
    expect(fn () => app(SubmitRecordChange::class)->section($actor, 'personal', ['employee_id_no' => 'FORGED', 'last_name' => 'Reyes'] + EmployeeFields::toForm($employee), 'Personal change'))
        ->toThrow(RuntimeException::class, OwnRecordFields::EMPLOYEE_ID_REFUSAL);
    expect($employee->refresh()->employee_id_no)->toBe('HR-ORIGINAL')
        ->and($employee->last_name)->toBe('Santos')
        ->and(EmployeeRecordChangeRequest::query()->count())->toBe(0)
        ->and(AuditLog::query()->count())->toBe(0);
})->with([
    'profile employee' => [false, ModuleCode::PROFILE],
    'profile all-data' => [true, ModuleCode::PROFILE],
    'employee-record all-data' => [true, ModuleCode::EMPLOYEES],
]);

it('accepts an unchanged Employee ID beside allowed personal changes without proposing it', function () {
    $employee = ercrEmployee();
    $employee->employee_id_no = 'HR-ORIGINAL';
    $employee->save();
    $actor = Access::forActor(ercrUser($employee));
    $id = app(SubmitRecordChange::class)->section($actor, 'personal', ['last_name' => 'Reyes'] + EmployeeFields::toForm($employee), 'Correct name');
    expect(EmployeeRecordChangeRequest::query()->findOrFail($id)->proposed)->toBe(['last_name' => 'Reyes']);
    app(ReviewRecordChange::class)->handle(ercrReviewer(), $id, true);
    expect($employee->refresh()->last_name)->toBe('Reyes')->and($employee->employee_id_no)->toBe('HR-ORIGINAL');
});

it('refuses approval of restricted pending section requests but lets management reject them', function (string $section, string $field, string $proposed, string $refusal) {
    $employee = ercrEmployee();
    $employee->employee_id_no = 'HR-ORIGINAL';
    $employee->save();
    $requester = ercrUser($employee);
    $current = RecordChangeData::sectionValues($employee, $section);
    $before = [$field => $current[$field]];
    $after = [$field => $proposed];
    $request = new EmployeeRecordChangeRequest;
    $request->forceFill([
        'employee_id' => $employee->id, 'employee_name' => 'Ana Santos',
        'pending_employee_id' => $employee->id, 'kind' => 'SECTION',
        'section' => $section, 'operation' => 'UPDATE',
        'baseline' => $before, 'proposed' => $after, 'form' => $after,
        'changes' => RecordChangeData::changes($employee, $section, $before, $after),
        'reason' => 'Pending correction', 'status' => EmployeeRecordChangeRequest::PENDING,
        'requested_by' => $requester->id, 'requested_by_name' => $requester->name,
    ])->save();
    $reviewer = ercrReviewer();
    expect(fn () => app(ReviewRecordChange::class)->handle($reviewer, $request->id, true))
        ->toThrow(RuntimeException::class, $refusal);
    expect($employee->refresh()->employee_id_no)->toBe('HR-ORIGINAL')
        ->and($employee->monthly_rate)->toBe(30000.0)
        ->and($request->refresh()->status)->toBe(EmployeeRecordChangeRequest::PENDING)
        ->and($request->reviewed_by)->toBeNull()
        ->and(EmployeeEmploymentHistory::query()->count())->toBe(0)
        ->and(AuditLog::query()->count())->toBe(0);
    app(ReviewRecordChange::class)->handle($reviewer, $request->id, false, 'Management will make this change directly.');
    expect($request->refresh()->status)->toBe(EmployeeRecordChangeRequest::REJECTED)
        ->and($request->pending_employee_id)->toBeNull()
        ->and(EmployeeEmploymentHistory::query()->count())->toBe(0);
})->with([
    'Employee Details' => ['employment', 'monthly_rate', '31000.00', OwnRecordFields::SECTION_REFUSAL],
    'Employee ID' => ['personal', 'employee_id_no', 'FORGED', OwnRecordFields::EMPLOYEE_ID_REFUSAL],
]);

it('lets management change Employee ID and Employee Details directly for any authorized record', function (bool $own) {
    $employee = ercrEmployee();
    $reviewer = Access::forActor(ercrUser($own ? $employee : null, true, [ModuleCode::EMPLOYEES]));
    EmploymentStatusOption::query()->create(['code' => 'REG', 'name' => 'Regular']);
    $site = WorkSite::query()->create(['name' => 'Main']);
    $employee->work_site_id = $site->id;
    $employee->save();
    app(UpdateEmployee::class)->handle($reviewer, $employee->id, 'personal', ['employee_id_no' => 'HR-UPDATED'] + EmployeeFields::toForm($employee));
    app(UpdateEmployee::class)->handle($reviewer, $employee->id, 'employment', ['monthly_rate' => '31000'] + EmployeeFields::toForm($employee->refresh()));
    expect($employee->refresh()->monthly_rate)->toBe(31000.0)
        ->and($employee->employee_id_no)->toBe('HR-UPDATED')
        ->and(EmployeeRecordChangeRequest::query()->count())->toBe(0)
        ->and(EmployeeEmploymentHistory::query()->count())->toBe(1);
})->with([false, true]);

it('scopes request lists and details before exposing personal values and omits private storage payloads', function () {
    $employee = ercrEmployee();
    $actor = Access::forActor(ercrUser($employee, true, [ModuleCode::PROFILE, ModuleCode::EMPLOYEES]));
    $own = ercrSubmit($actor);
    $otherActor = Access::forActor(ercrUser(ercrEmployee()));
    $other = ercrSubmit($otherActor, 'Other private contact');
    $query = app(RecordChangeRequests::class);
    expect($query->page($actor, true)->total())->toBe(1)
        ->and($query->find($actor, $other, true))->toBeNull()
        ->and($query->page(Access::forActor(ercrUser(null, true, [ModuleCode::PAYROLL])))->total())->toBe(0)
        ->and($query->page(ercrReviewer())->total())->toBe(2);
    $view = $query->find($actor, $own, true);
    expect($view?->can_review)->toBeFalse()->and($view?->can_withdraw)->toBeTrue()
        ->and($view?->toArray())->not->toHaveKeys(['form', 'baseline', 'proposed']);
});

it('creates updates and removes PDS rows without reasons only after approval', function () {
    $employee = ercrEmployee();
    $actor = Access::forActor(ercrUser($employee));
    $form = ['level' => 'COLLEGE', 'school' => 'State University', 'course' => 'BS Education', 'attended_from' => '2010', 'attended_to' => '2014', 'units_earned' => '', 'year_graduated' => '2014', 'honors' => ''];
    $id = app(SubmitRecordChange::class)->pds($actor, 'education', null, $form);
    expect(EmployeeEducation::query()->count())->toBe(0);
    app(ReviewRecordChange::class)->handle(ercrReviewer(), $id, true);
    $row = EmployeeEducation::query()->sole();
    expect($row->school)->toBe('State University');
    $id = app(SubmitRecordChange::class)->pds($actor, 'education', $row->id, ['school' => 'National University'] + $form);
    app(ReviewRecordChange::class)->handle(ercrReviewer(), $id, true);
    expect($row->refresh()->school)->toBe('National University');
    $id = app(SubmitRecordChange::class)->pds($actor, 'education', $row->id, [], delete: true);
    expect(EmployeeEducation::query()->count())->toBe(1);
    app(ReviewRecordChange::class)->handle(ercrReviewer(), $id, true);
    expect(EmployeeEducation::query()->count())->toBe(0)->and(EmployeeEmploymentHistory::query()->count())->toBe(3)
        ->and(EmployeeRecordChangeRequest::query()->pluck('reason')->all())->toBe(['', '', ''])
        ->and(EmployeeEmploymentHistory::query()->pluck('request_reason')->all())->toBe(['', '', '']);
});

it('keeps proposed and previous portraits private and immutable after approval', function () {
    Storage::fake('local');
    Storage::fake('public');
    $employee = ercrEmployee();
    $manager = ercrReviewer();
    app(UpdatePortrait::class)->handle($manager, $employee->id, UploadedFile::fake()->image('old.png', 10, 10));
    $old = $employee->refresh()->photo_path;
    $actor = Access::forActor(ercrUser($employee));
    $id = app(SubmitRecordChange::class)->portrait($actor, UploadedFile::fake()->image('new.png', 20, 10));
    $request = EmployeeRecordChangeRequest::query()->findOrFail($id);
    expect($employee->refresh()->photo_path)->toBe($old)->and($request->reason)->toBe('');
    Storage::disk('local')->assertExists($request->form['photo_previous_path']);
    Storage::disk('local')->assertExists($request->form['photo_proposed_path']);
    app(ReviewRecordChange::class)->handle($manager, $id, true);
    expect($employee->refresh()->photo_path)->not->toBe($old)
        ->and(EmployeeEmploymentHistory::query()->sole()->request_reason)->toBe('');
    $removal = app(SubmitRecordChange::class)->portrait($actor, null);
    expect(EmployeeRecordChangeRequest::query()->findOrFail($removal)->reason)->toBe('')
        ->and($employee->refresh()->photo_path)->not->toBeNull();
    app(ReviewRecordChange::class)->handle($manager, $removal, true);
    expect($employee->refresh()->photo_path)->toBeNull()
        ->and(EmployeeEmploymentHistory::query()->pluck('request_reason')->all())->toBe(['', '']);
    Storage::disk('local')->assertExists($request->form['photo_previous_path']);
    Storage::disk('local')->assertExists($request->form['photo_proposed_path']);
    expect(app(RecordChangeRequests::class)->evidence(Access::forActor(ercrUser(ercrEmployee())), $id, 'after'))->toBeNull();
});

it('refuses blank mandatory record columns before storing a proposal', function (string $key, string $section) {
    $employee = ercrEmployee();
    $employee->work_site_id = WorkSite::query()->create(['name' => 'Validation site'])->id;
    $employee->save();
    $actor = Access::forActor(ercrUser($employee));
    expect(fn () => app(SubmitRecordChange::class)->section($actor, $section, [$key => ''] + EmployeeFields::toForm($employee), 'Correction'))
        ->toThrow(RuntimeException::class, 'is required');
    expect(EmployeeRecordChangeRequest::query()->count())->toBe(0);
})->with([
    'first name' => ['first_name', 'personal'],
    'last name' => ['last_name', 'personal'],
]);

it('replays an omitted optional field as the exact clear that was proposed', function () {
    $employee = ercrEmployee();
    $actor = Access::forActor(ercrUser($employee));
    $id = app(SubmitRecordChange::class)->section($actor, 'emergency', [], 'Clear outdated contact');
    app(ReviewRecordChange::class)->handle(ercrReviewer(), $id, true);
    expect($employee->refresh()->emergency_name)->toBeNull()
        ->and(EmployeeRecordChangeRequest::query()->findOrFail($id)->status)->toBe('APPROVED');
});

it('rechecks current reviewer independence even when Access was created before an account relink', function () {
    $employee = ercrEmployee();
    $id = ercrSubmit(Access::forActor(ercrUser($employee)));
    $reviewer = ercrUser(null, true, [ModuleCode::EMPLOYEES]);
    $staleAccess = Access::forActor($reviewer);
    $reviewer->employee_id = $employee->id;
    $reviewer->save();
    expect(fn () => app(ReviewRecordChange::class)->handle($staleAccess, $id, true))->toThrow(HttpException::class);
    app(UpdateEmployee::class)->handle($staleAccess, $employee->id, 'emergency', ['emergency_name' => 'Management correction']);
    expect($employee->refresh()->emergency_name)->toBe('Management correction')
        ->and(EmployeeRecordChangeRequest::query()->findOrFail($id)->status)->toBe('PENDING');
    expect(fn () => app(ReviewRecordChange::class)->handle(ercrReviewer(), $id, true))->toThrow(RuntimeException::class, RecordChangeData::STALE);
});

it('lets management save PDS rows and portraits directly on their linked employee', function () {
    Storage::fake('public');
    $employee = ercrEmployee();
    $actor = Access::forActor(ercrUser($employee, true, [ModuleCode::PROFILE, ModuleCode::EMPLOYEES]));
    $form = ['level' => 'COLLEGE', 'school' => 'State University'];
    app(SavePdsRow::class)->handle($actor, $employee->id, 'education', null, $form);
    $row = EmployeeEducation::query()->sole();
    app(SavePdsRow::class)->handle($actor, $employee->id, 'education', $row->id, ['school' => 'National University'] + $form);
    expect($row->refresh()->school)->toBe('National University');
    app(DeletePdsRow::class)->handle($actor, $employee->id, 'education', $row->id);
    expect(EmployeeEducation::query()->count())->toBe(0);
    app(UpdatePortrait::class)->handle($actor, $employee->id, UploadedFile::fake()->image('portrait.png', 20, 10));
    $path = $employee->refresh()->photo_path;
    expect($path)->toBeString();
    Storage::disk('public')->assertExists($path);
    app(UpdatePortrait::class)->handle($actor, $employee->id, null);
    expect($employee->refresh()->photo_path)->toBeNull()
        ->and(EmployeeRecordChangeRequest::query()->count())->toBe(0);
    Storage::disk('public')->assertMissing($path);
    expect(fn () => app(UpdatePortrait::class)->handle($actor, $employee->id, null, ownOnly: true))->toThrow(HttpException::class);
});

it('rechecks active management access before every direct record mutation', function (string $revocation) {
    Storage::fake('public');
    $employee = ercrEmployee();
    $user = ercrUser($employee, true, [ModuleCode::EMPLOYEES]);
    $actor = Access::forActor($user);
    $row = EmployeeEducation::query()->create(['employee_id' => $employee->id, 'level' => 'COLLEGE', 'school' => 'Original School']);
    match ($revocation) {
        'inactive' => $user->forceFill(['is_active' => false])->save(),
        'grant' => RoleModuleGrant::query()->where('role_id', $user->role_id)->delete(),
        'all-data' => Role::query()->whereKey($user->role_id)->update(['all_data' => false]),
        'role' => $user->forceFill(['role_id' => null])->save(),
        default => throw new InvalidArgumentException('Unknown management access revocation.'),
    };
    if ($revocation !== 'inactive') {
        $actor = Access::forActor($user->fresh());
    }
    foreach ([
        fn () => app(UpdateEmployee::class)->handle($actor, $employee->id, 'emergency', ['emergency_name' => 'Forged']),
        fn () => app(UpdatePortrait::class)->handle($actor, $employee->id, UploadedFile::fake()->image('forged.png', 20, 10)),
        fn () => app(SavePdsRow::class)->handle($actor, $employee->id, 'education', $row->id, ['level' => 'COLLEGE', 'school' => 'Forged School']),
        fn () => app(DeletePdsRow::class)->handle($actor, $employee->id, 'education', $row->id),
    ] as $action) {
        expect($action)->toThrow(HttpException::class);
    }
    expect($employee->refresh()->emergency_name)->toBe('Original Contact')
        ->and($employee->photo_path)->toBeNull()
        ->and($row->refresh()->school)->toBe('Original School')
        ->and(Storage::disk('public')->allFiles())->toBe([])
        ->and(EmployeeRecordChangeRequest::query()->count())->toBe(0)
        ->and(AuditLog::query()->count())->toBe(0);
})->with(['inactive', 'grant', 'all-data', 'role']);

it('keeps ordinary field validation on management edits to the linked employee', function () {
    $employee = ercrEmployee();
    $actor = Access::forActor(ercrUser($employee, true, [ModuleCode::EMPLOYEES]));
    expect(fn () => app(UpdateEmployee::class)->handle($actor, $employee->id, 'personal', ['first_name' => ''] + EmployeeFields::toForm($employee)))
        ->toThrow(RuntimeException::class, 'is required');
    expect(fn () => app(SavePdsRow::class)->handle($actor, $employee->id, 'education', null, ['level' => 'COLLEGE', 'school' => '']))
        ->toThrow(RuntimeException::class);
    expect($employee->refresh()->first_name)->toBe('Ana')
        ->and(EmployeeEducation::query()->count())->toBe(0)
        ->and(EmployeeRecordChangeRequest::query()->count())->toBe(0)
        ->and(AuditLog::query()->count())->toBe(0);
});
