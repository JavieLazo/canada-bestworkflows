<?php

declare(strict_types=1);

use App\Domain\Enums\ModuleCode;
use App\Livewire\Employees\ChangeRequests;
use App\Livewire\Employees\Documents;
use App\Livewire\Employees\History;
use App\Livewire\Employees\PdsList;
use App\Livewire\Employees\Show;
use App\Models\EmployeeDocument;
use App\Models\EmployeeEducation;
use App\Models\EmployeeEmploymentHistory;
use App\Models\EmployeeProfile;
use App\Models\EmployeeRecordChangeRequest;
use App\Models\Role;
use App\Models\RoleModuleGrant;
use App\Models\User;
use App\Modules\Workforce\Application\Commands\SubmitRecordChange;
use App\Support\Access;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

/** My Profile submits proposals for management review, the requester's own management account included; Employee Records writes require management access. */
function recuiEmployee(string $name = 'Requester'): EmployeeProfile
{
    return EmployeeProfile::query()->create([
        'employee_code' => 'UI-'.Str::upper(Str::random(8)),
        'first_name' => $name, 'last_name' => 'Record', 'employment_status' => 'Regular',
        'date_hired' => '2024-01-01', 'monthly_rate' => 25000,
    ]);
}

/** @param list<string> $modules */
function recuiActor(?EmployeeProfile $employee = null, bool $manager = false, array $modules = [ModuleCode::PROFILE]): User
{
    $role = Role::query()->create(['name' => 'Request UI '.Str::random(8), 'all_data' => $manager, 'is_system' => false]);
    foreach ($modules as $module) {
        RoleModuleGrant::query()->create(['role_id' => $role->id, 'module' => $module]);
    }
    $user = User::factory()->create();
    $user->forceFill(['role_id' => $role->id, 'employee_id' => $employee?->id])->save();

    return $user;
}

function recuiSignIn(User $user): void
{
    test()->actingAs($user->fresh());
    app()->forgetInstance(Access::class);
}

function recuiRequest(User $user, string $reason = 'My emergency contact changed'): string
{
    return app(SubmitRecordChange::class)->section(Access::forActor($user), 'emergency', [
        'emergency_name' => 'New contact', 'emergency_relation' => 'Sister',
        'emergency_phone' => '0917 555 1234', 'emergency_address' => '15 Rizal Street',
    ], $reason);
}

it('pins the full own-record component and its child lists to the session and locks their modes', function () {
    $own = recuiEmployee();
    $other = recuiEmployee('Private colleague');
    recuiSignIn(recuiActor($own, true, [ModuleCode::PROFILE, ModuleCode::EMPLOYEES]));
    $page = Livewire::test(Show::class, ['own' => true, 'employee' => $other->id])
        ->assertSet('employeeId', $own->id)
        ->assertDontSee('Private colleague');
    expect(fn () => $page->set('own', false))->toThrow(CannotUpdateLockedPropertyException::class);
    $list = Livewire::test(PdsList::class, ['own' => true, 'employeeId' => $other->id, 'list' => 'education'])
        ->assertSet('employeeId', $own->id);
    expect(fn () => $list->set('employeeId', $other->id))->toThrow(CannotUpdateLockedPropertyException::class);
    Livewire::test(Documents::class, ['own' => true, 'employeeId' => $other->id])->assertForbidden();
});

it('stages a school correction without changing an existing official school', function () {
    $employee = recuiEmployee();
    $school = EmployeeEducation::query()->create(['employee_id' => $employee->id, 'level' => 'COLLEGE', 'school' => 'Old school', 'course' => 'BS Accountancy']);
    recuiSignIn(recuiActor($employee));
    Livewire::test(PdsList::class, ['own' => true, 'employeeId' => $employee->id, 'list' => 'education'])
        ->call('startEdit', $school->id)
        ->assertSee('Submit for approval')
        ->assertDontSee('Reason for change')
        ->set('form.school', 'Correct school')
        ->call('save')
        ->assertSet('dialogOpen', false)
        ->assertDispatched('record-change-submitted');
    expect($school->refresh()->school)->toBe('Old school')
        ->and(EmployeeRecordChangeRequest::query()->value('kind'))->toBe('PDS')
        ->and(EmployeeRecordChangeRequest::query()->value('reason'))->toBe('');
});

it('submits school additions and deletions for approval', function (bool $delete) {
    $employee = recuiEmployee();
    recuiSignIn(recuiActor($employee));
    $page = Livewire::test(PdsList::class, ['own' => true, 'employeeId' => $employee->id, 'list' => 'education']);
    if ($delete) {
        $school = EmployeeEducation::query()->create(['employee_id' => $employee->id, 'level' => 'COLLEGE', 'school' => 'Duplicate school']);
        $page->call('confirmDelete', $school->id)->assertDontSee('Reason for removal')->call('deleteConfirmed');
        expect($school->fresh())->not->toBeNull();
    } else {
        $page->call('openAdd', 'COLLEGE')->set('form.school', 'Missing school')->call('save');
        expect(EmployeeEducation::query()->count())->toBe(0);
    }
    expect(EmployeeRecordChangeRequest::query()->value('operation'))->toBe($delete ? 'DELETE' : 'CREATE')
        ->and(EmployeeRecordChangeRequest::query()->value('reason'))->toBe('');
})->with([true, false]);

it('stages a portrait until submission and leaves the official portrait unchanged', function () {
    Storage::fake('local');
    Storage::fake('public');
    $employee = recuiEmployee();
    recuiSignIn(recuiActor($employee));
    $page = Livewire::test(Show::class, ['own' => true])
        ->set('photo', UploadedFile::fake()->image('portrait.jpg', 300, 300))
        ->assertSet('portraitOpen', true)
        ->assertDontSee('Reason for change');
    expect(EmployeeRecordChangeRequest::query()->count())->toBe(0)->and($employee->fresh()->photo_path)->toBeNull();
    $page->call('submitPortrait')->assertSet('portraitOpen', false);
    expect(EmployeeRecordChangeRequest::query()->value('kind'))->toBe('PORTRAIT')
        ->and(EmployeeRecordChangeRequest::query()->value('reason'))->toBe('')
        ->and($employee->fresh()->photo_path)->toBeNull();
});

it('saves a managers own list rows and portrait directly through Employee Records', function () {
    Storage::fake('local');
    Storage::fake('public');
    $employee = recuiEmployee();
    $school = EmployeeEducation::query()->create(['employee_id' => $employee->id, 'level' => 'COLLEGE', 'school' => 'Old school']);
    recuiSignIn(recuiActor($employee, true, [ModuleCode::EMPLOYEES]));

    $list = Livewire::test(PdsList::class, ['employeeId' => $employee->id, 'list' => 'education'])
        ->call('startEdit', $school->id)
        ->assertDontSee('Submit for approval')
        ->set('form.school', 'Correct school')
        ->call('save')
        ->assertSet('dialogOpen', false)
        ->assertNotDispatched('record-change-submitted');
    expect($school->refresh()->school)->toBe('Correct school');

    $list->call('openAdd', 'GRADUATE')->set('form.school', 'Graduate school')->call('save');
    expect(EmployeeEducation::query()->count())->toBe(2);
    $list->call('confirmDelete', $school->id)->call('deleteConfirmed');
    expect($school->fresh())->toBeNull();

    Livewire::test(Show::class, ['employee' => $employee->id])
        ->set('photo', UploadedFile::fake()->image('portrait.jpg', 300, 300))
        ->assertSet('portraitOpen', false)
        ->assertNotDispatched('record-change-submitted');
    expect($employee->refresh()->photo_path)->not->toBeNull()
        ->and(EmployeeRecordChangeRequest::query()->count())->toBe(0);
});

it('refuses management-view section and portrait writes without all-data access even for the linked employee', function (string $action) {
    Storage::fake('local');
    Storage::fake('public');
    $employee = recuiEmployee();
    recuiSignIn(recuiActor($employee, false, [ModuleCode::EMPLOYEES]));
    $page = Livewire::test(Show::class, ['employee' => $employee->id])
        ->assertDontSee('Submit for approval')
        ->assertDontSeeHtml('wire:click="startEdit(\'personal\')"')
        ->assertDontSeeHtml('value="documents"');
    if ($action === 'section') {
        $page->set('form.first_name', 'Tampered')->call('saveSection', 'personal')->assertForbidden();
    } else {
        $page->set('photo', UploadedFile::fake()->image('portrait.jpg', 300, 300))->assertForbidden();
    }
    expect($employee->refresh()->first_name)->toBe('Requester')
        ->and($employee->photo_path)->toBeNull()
        ->and(EmployeeRecordChangeRequest::query()->count())->toBe(0);
})->with(['section', 'portrait']);

it('refuses the Documents component in employee self-service', function () {
    Storage::fake('local');
    $employee = recuiEmployee();
    recuiSignIn(recuiActor($employee));
    Livewire::test(Documents::class, ['own' => true, 'employeeId' => $employee->id])->assertForbidden();
    expect(EmployeeDocument::query()->count())->toBe(0)
        ->and(EmployeeRecordChangeRequest::query()->count())->toBe(0);
});

it('shows managers a pending comparison and preserves only supplied reasons through approval history', function (string $reason) {
    $employee = recuiEmployee();
    $request = recuiRequest(recuiActor($employee), $reason);
    recuiSignIn(recuiActor(null, true, [ModuleCode::EMPLOYEES]));
    $this->get('/employees/change-requests')->assertOk()->assertSee('Record change requests');
    $page = Livewire::test(ChangeRequests::class)
        ->assertDontSee('1 pending')
        ->call('show', $request)
        ->assertSee('When submitted')
        ->assertSee('New contact')
        ->assertSee('Approve changes');
    if ($reason === '') {
        $page->assertDontSee('Reason for change');
    } else {
        $page->assertSee('Reason for change')->assertSee($reason);
    }
    $page->call('review', true);
    expect($employee->refresh()->emergency_name)->toBe('New contact')
        ->and(EmployeeRecordChangeRequest::query()->findOrFail($request)->status)->toBe('APPROVED')
        ->and(EmployeeEmploymentHistory::query()->where('employee_id', $employee->id)->exists())->toBeTrue();
    $history = Livewire::test(History::class)->set('employeeId', $employee->id);
    if ($reason === '') {
        $history->assertDontSee('Reason:');
    } else {
        $history->assertSee('Reason: '.$reason);
    }
})->with(['', 'My emergency contact changed']);

it('requires a rejection explanation and retains the official data when rejected', function () {
    $employee = recuiEmployee();
    $request = recuiRequest(recuiActor($employee));
    recuiSignIn(recuiActor(null, true, [ModuleCode::EMPLOYEES]));
    $page = Livewire::test(ChangeRequests::class)->call('show', $request)->call('review', false);
    expect(EmployeeRecordChangeRequest::query()->findOrFail($request)->status)->toBe('PENDING');
    $page->set('reviewNote', 'Please confirm the contact number')->call('review', false);
    expect(EmployeeRecordChangeRequest::query()->findOrFail($request)->status)->toBe('REJECTED')
        ->and($employee->fresh()->emergency_name)->toBeNull()
        ->and(EmployeeEmploymentHistory::query()->where('employee_id', $employee->id)->exists())->toBeFalse();
});

it('lets another account of the same employee approve from the queue', function () {
    $employee = recuiEmployee();
    $request = recuiRequest(recuiActor($employee));
    recuiSignIn(recuiActor($employee, true, [ModuleCode::EMPLOYEES, ModuleCode::PROFILE]));
    Livewire::test(ChangeRequests::class)
        ->call('show', $request)
        ->assertSee('Approve changes')
        ->call('review', true);
    expect(EmployeeRecordChangeRequest::query()->findOrFail($request)->status)->toBe('APPROVED')
        ->and($employee->fresh()->emergency_name)->not->toBeNull();
});

it('shows only own requests and withdraws without editing the official record', function () {
    $employee = recuiEmployee();
    $user = recuiActor($employee, true, [ModuleCode::PROFILE, ModuleCode::EMPLOYEES]);
    $request = recuiRequest($user);
    $other = recuiRequest(recuiActor(recuiEmployee('Private colleague')));
    recuiSignIn($user);
    Livewire::test(ChangeRequests::class, ['own' => true])
        ->assertDontSee('Private colleague')
        ->call('show', $other)
        ->assertSet('openId', null)
        ->call('show', $request)
        ->assertSee('Withdraw request')
        ->call('withdraw');
    expect(EmployeeRecordChangeRequest::query()->findOrFail($request)->status)->toBe('WITHDRAWN')
        ->and($employee->fresh()->emergency_name)->toBeNull();
});

it('opens the own request route for an EMPLOYEES-only account without reviewer access', function () {
    $employee = recuiEmployee();
    $user = recuiActor($employee, false, [ModuleCode::EMPLOYEES]);
    recuiRequest($user);
    recuiSignIn($user);
    $this->get('/my-record/requests')->assertOk()->assertSee('My change requests')->assertDontSee('Approve changes');
    $this->get('/employees/change-requests')->assertForbidden();
});
