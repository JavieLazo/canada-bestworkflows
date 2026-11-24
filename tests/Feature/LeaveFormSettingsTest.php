<?php

declare(strict_types=1);

use App\Livewire\Leaves\Index;
use App\Livewire\Portal\PortalPage;
use App\Livewire\Records\DepartmentsPage;
use App\Livewire\Records\LeaveCreditSettingsPage;
use App\Models\AuditLog;
use App\Models\EmployeeProfile;
use App\Models\LeaveCredit;
use App\Models\LeaveSignatory;
use App\Models\LeaveTravelRequest;
use App\Models\LeaveType;
use App\Models\OrgNode;
use App\Models\Position;
use App\Models\Role;
use App\Models\RoleModuleGrant;
use App\Models\User;
use App\Modules\Organization\Application\Commands\SaveDepartment;
use App\Modules\Organization\Application\Commands\SaveUnit;
use App\Modules\Organization\Application\Queries\StructureRecords;
use App\Modules\Timekeeping\Application\Commands\ReviewAbsence;
use App\Modules\Timekeeping\Application\Commands\SaveLeaveSignatory;
use App\Modules\Timekeeping\Application\Queries\LeaveSignatoryRecords;
use App\Modules\Timekeeping\Domain\Absence\LeaveSignatorySlot;
use App\Modules\Timekeeping\Infrastructure\AccountReferences;
use App\Modules\Workforce\Application\Queries\EmployeeRecords;
use App\Support\Access;
use App\Support\LocalClock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Once;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Support\ScheduleFixture;

/**
 * What stands behind the Application for Leave's officers and its filed view.
 * CERTIFIED BY and APPROVED BY are employees picked on Leave Settings, whose
 * name and position the form reads from their record, showing the printed
 * officers until HR picks them. THE HEAD OF OFFICE is typed on each department
 * or unit, a unit's head taking priority over its department's. An approval
 * may carry the form's "others (specify)", recorded only. A FILED application
 * opens read-only from My requests, for its own employee alone, even for an
 * all-data holder, and from Leave Management's review dialog, where the
 * salary follows the record's own permission.
 */

/** @param  list<string>  $modules */
function lfsetActor(array $modules, bool $allData = false, ?string $employeeId = null): User
{
    $role = new Role;
    $role->forceFill(['name' => 'Set '.Str::random(6), 'all_data' => $allData, 'is_system' => false])->save();
    foreach ($modules as $module) {
        $grant = new RoleModuleGrant;
        $grant->forceFill(['role_id' => $role->getKey(), 'module' => $module])->save();
    }
    $user = User::factory()->create(['role_id' => $role->getKey(), 'employee_id' => $employeeId]);
    test()->actingAs($user);
    app()->forgetInstance(Access::class);

    return $user;
}

/** @param  array<string, mixed>  $overrides */
function lfsetEmployee(string $code, array $overrides = []): EmployeeProfile
{
    $employee = new EmployeeProfile;
    $employee->forceFill(array_merge([
        'employee_code' => $code, 'first_name' => 'Liza', 'last_name' => 'Domingo', 'position' => 'Accountant',
        'monthly_rate' => 35000, 'employment_status' => 'Regular', 'date_hired' => '2024-01-01', 'is_active' => true,
    ], $overrides))->save();

    ScheduleFixture::standard($employee);

    return $employee;
}

function lfsetType(string $name, string $details = 'NONE'): LeaveType
{
    $type = new LeaveType;
    $type->forceFill(['code' => 'LS-'.Str::random(6), 'name' => $name, 'leave_details' => $details])->save();

    return $type;
}

function lfsetCredit(EmployeeProfile $employee, string $token, float $credits): void
{
    $credit = new LeaveCredit;
    $credit->forceFill(['employee_id' => $employee->getKey(), 'year' => 2026, 'request_type' => $token, 'credits' => $credits])->save();
}

function lfsetNode(string $code, string $kind, string $name, ?string $parent = null, ?string $head = null, ?string $designation = null): OrgNode
{
    $node = new OrgNode;
    $node->forceFill(['code' => $code, 'kind' => $kind, 'name' => $name, 'parent_id' => $parent, 'sort_order' => 0, 'head_name' => $head, 'head_designation' => $designation])->save();

    return $node;
}

function lfsetPlaced(string $code, OrgNode $node): EmployeeProfile
{
    $position = new Position;
    $position->forceFill(['code' => 'P'.$code, 'name' => 'Accountant '.$code, 'org_node_id' => $node->getKey(), 'sort_order' => 0])->save();

    return lfsetEmployee($code, ['position_id' => $position->getKey()]);
}

/** @param  array<string, mixed>  $overrides */
function lfsetRequest(EmployeeProfile $employee, string $code, array $overrides = []): LeaveTravelRequest
{
    $request = new LeaveTravelRequest;
    $request->forceFill(array_merge([
        'code' => $code, 'employee_id' => $employee->getKey(), 'request_type' => 'VACATION_LEAVE', 'day_type' => 'WHOLE_DAY',
        'start_date' => '2026-11-17', 'end_date' => '2026-11-18', 'reason' => 'Family trip', 'status' => 'PENDING',
    ], $overrides))->save();

    return $request;
}

beforeEach(fn () => CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-16 09:00:00', LocalClock::zone())));
afterEach(fn () => CarbonImmutable::setTestNow());

/*
|--------------------------------------------------------------------------
| Certified by and Approved by
|--------------------------------------------------------------------------
*/

it('shows blank officers until HR sets them', function () {
    $access = Access::forActor(lfsetActor(['PORTAL']));

    $officers = app(LeaveSignatoryRecords::class)->all($access);

    expect($officers[LeaveSignatorySlot::CERTIFIED]->name)->toBe('')
        ->and($officers[LeaveSignatorySlot::APPROVED]->designation)->toBe('')
        ->and(LeaveSignatory::query()->count())->toBe(0);
});

it('sets an officer for a leave settings manager, audits it and skips a no-op', function () {
    $access = Access::forActor(lfsetActor(['LEAVES'], allData: true));
    $ana = lfsetEmployee('EMP-2', ['first_name' => 'Ana', 'last_name' => 'Reyes', 'position' => 'HR Head']);
    $save = app(SaveLeaveSignatory::class);

    expect($save->handle($access, 'CERTIFIED', $ana->getKey()))->toBeTrue()
        ->and($save->handle($access, 'CERTIFIED', $ana->getKey()))->toBeFalse();

    expect(LeaveSignatory::query()->sole()->only(['slot', 'employee_id', 'name', 'designation']))->toBe(['slot' => 'CERTIFIED', 'employee_id' => $ana->getKey(), 'name' => null, 'designation' => null])
        ->and(AuditLog::query()->where('action', 'LEAVE_SIGNATORY_SET')->sole()->details)->toBe('Certified by: Ana Reyes, HR Head');
});

it('reads a signatory from the employee record as it stands', function () {
    $access = Access::forActor(lfsetActor(['LEAVES'], allData: true));
    $ana = lfsetEmployee('EMP-2', ['first_name' => 'Ana', 'middle_name' => 'Cruz', 'last_name' => 'Reyes', 'position' => 'HR Head']);
    app(SaveLeaveSignatory::class)->handle($access, 'CERTIFIED', $ana->getKey());

    $ana->forceFill(['last_name' => 'Santos', 'position' => 'Vice President'])->save();

    $officer = app(LeaveSignatoryRecords::class)->all($access)['CERTIFIED'];
    expect($officer->name)->toBe('Ana Cruz Santos')
        ->and($officer->designation)->toBe('Vice President')
        ->and($officer->employeeId)->toBe($ana->getKey());
});

it('keeps an officer typed before employees could be picked until one is picked', function () {
    $access = Access::forActor(lfsetActor(['LEAVES'], allData: true));
    $row = new LeaveSignatory;
    $row->forceFill(['slot' => 'APPROVED', 'name' => 'Typed Name', 'designation' => 'Typed Title'])->save();
    expect(app(LeaveSignatoryRecords::class)->all($access)['APPROVED']->name)->toBe('Typed Name');

    $ana = lfsetEmployee('EMP-2', ['first_name' => 'Ana', 'last_name' => 'Reyes', 'position' => 'HR Head']);
    app(SaveLeaveSignatory::class)->handle($access, 'APPROVED', $ana->getKey());

    expect(app(LeaveSignatoryRecords::class)->all($access)['APPROVED']->designation)->toBe('HR Head')
        ->and($row->refresh()->only(['name', 'designation']))->toBe(['name' => null, 'designation' => null]);
});

it('keeps a saved officer whose record became inactive, and refuses picking one anew', function () {
    $access = Access::forActor(lfsetActor(['LEAVES'], allData: true));
    $ana = lfsetEmployee('EMP-2', ['first_name' => 'Ana', 'last_name' => 'Reyes']);
    $save = app(SaveLeaveSignatory::class);
    $save->handle($access, 'CERTIFIED', $ana->getKey());
    $ana->forceFill(['is_active' => false])->save();

    expect($save->handle($access, 'CERTIFIED', $ana->getKey()))->toBeFalse()
        ->and(app(LeaveSignatoryRecords::class)->all($access)['CERTIFIED']->employeeActive)->toBeFalse()
        ->and(fn () => $save->handle($access, 'APPROVED', $ana->getKey()))->toThrow(RuntimeException::class);
});

it('shows a blank line for an officer HR emptied', function () {
    $access = Access::forActor(lfsetActor(['LEAVES'], allData: true));
    $save = app(SaveLeaveSignatory::class);

    expect($save->handle($access, 'APPROVED', null))->toBeTrue()
        ->and($save->handle($access, 'APPROVED', null))->toBeFalse();

    expect(app(LeaveSignatoryRecords::class)->all($access)['APPROVED']->name)->toBe('')
        ->and(AuditLog::query()->where('action', 'LEAVE_SIGNATORY_SET')->sole()->details)->toBe('Approved by: cleared');
});

it('refuses an officer change without management, an unknown slot and an unknown employee', function (array $modules, bool $allData, string $slot, bool $known) {
    $access = Access::forActor(lfsetActor(array_values(array_filter($modules, 'is_string')), $allData));
    $id = $known ? lfsetEmployee('EMP-2')->getKey() : (string) Str::ulid();

    expect(fn () => app(SaveLeaveSignatory::class)->handle($access, $slot, $id))->toThrow(RuntimeException::class);
    expect(LeaveSignatory::query()->count())->toBe(0)
        ->and(AuditLog::query()->where('action', 'LEAVE_SIGNATORY_SET')->count())->toBe(0);
})->with([
    'no all-data' => [['LEAVES'], false, 'CERTIFIED', true],
    'portal only' => [['PORTAL'], true, 'CERTIFIED', true],
    'unknown slot' => [['LEAVES'], true, 'MAYOR', true],
    'unknown employee' => [['LEAVES'], true, 'CERTIFIED', false],
]);

it('saves the signatories picked on Leave Settings and shows them on the form', function () {
    $mine = lfsetEmployee('EMP-1');
    lfsetActor(['LEAVES', 'RECORDS', 'PORTAL'], allData: true, employeeId: $mine->getKey());
    $ana = lfsetEmployee('EMP-2', ['first_name' => 'Ana', 'last_name' => 'Reyes', 'position' => 'HR Head']);

    Livewire::test(LeaveCreditSettingsPage::class)
        ->set('tab', 'signatories')
        ->assertSeeHtml('data-leave-signatories')
        ->assertSeeHtml('data-signatory-edit')
        ->assertDontSee('No employee chosen yet.')
        ->assertDontSeeHtml('data-signatory-designation')
        ->call('editSignatories')
        ->assertDontSeeHtml('data-signatory-edit')
        ->assertSee('The form shows a blank line until you pick an employee.')
        ->dispatch('kit-employee-picked', name: 'signatory-CERTIFIED', id: $ana->getKey())
        ->assertSet('signatoryForm.CERTIFIED', $ana->getKey())
        ->assertSeeHtml('data-signatory-designation>HR Head</p>')
        ->call('saveSignatories')
        ->assertSet('signatoriesEditing', false)
        ->assertSet('signatoryForm.CERTIFIED', $ana->getKey())
        ->assertSee('Ana Reyes')
        ->assertSeeHtml('data-signatory-edit');

    expect(LeaveSignatory::query()->sole()->employee_id)->toBe($ana->getKey());

    Livewire::test(PortalPage::class)->set('tab', 'requests')
        ->assertSeeHtml('data-officer-certified>Ana Reyes</dd>')
        ->assertSeeHtml('data-officer-approved>—</dd>');
});

it('leaves an untouched printed officer unset when the signatories are saved', function () {
    lfsetActor(['LEAVES'], allData: true);

    Livewire::test(LeaveCreditSettingsPage::class)->set('tab', 'signatories')->call('editSignatories')->call('saveSignatories');

    expect(LeaveSignatory::query()->count())->toBe(0);
});

it('drops unsaved signatory choices on Cancel and on leaving the tab', function () {
    lfsetActor(['LEAVES'], allData: true);
    $ana = lfsetEmployee('EMP-2');

    Livewire::test(LeaveCreditSettingsPage::class)
        ->set('tab', 'signatories')
        ->call('editSignatories')
        ->dispatch('kit-employee-picked', name: 'signatory-CERTIFIED', id: $ana->getKey())
        ->call('cancelSignatories')
        ->assertSet('signatoriesEditing', false)
        ->assertSet('signatoryForm.CERTIFIED', '')
        ->call('editSignatories')
        ->dispatch('kit-employee-picked', name: 'signatory-CERTIFIED', id: $ana->getKey())
        ->set('tab', 'types')
        ->assertSet('signatoriesEditing', false)
        ->set('tab', 'signatories')
        ->assertSet('signatoryForm.CERTIFIED', '');

    expect(LeaveSignatory::query()->count())->toBe(0);
});

it('ignores a signatory pick or clear while the tab is not being edited', function () {
    lfsetActor(['LEAVES'], allData: true);
    $ana = lfsetEmployee('EMP-2');

    Livewire::test(LeaveCreditSettingsPage::class)
        ->set('tab', 'signatories')
        ->dispatch('kit-employee-picked', name: 'signatory-CERTIFIED', id: $ana->getKey())
        ->call('clearSignatory', 'APPROVED')
        ->assertSet('signatoryForm.CERTIFIED', '')
        ->assertSet('signatoryForm.APPROVED', '')
        ->call('saveSignatories');

    expect(LeaveSignatory::query()->count())->toBe(0);
});

it('flags a saved signatory who is no longer active', function () {
    $access = Access::forActor(lfsetActor(['LEAVES'], allData: true));
    $ana = lfsetEmployee('EMP-2', ['first_name' => 'Ana', 'last_name' => 'Reyes']);
    app(SaveLeaveSignatory::class)->handle($access, 'CERTIFIED', $ana->getKey());
    $ana->forceFill(['is_active' => false])->save();

    Livewire::test(LeaveCreditSettingsPage::class)
        ->set('tab', 'signatories')
        ->assertSeeHtml('data-signatory-inactive')
        ->call('editSignatories')
        ->assertSee('Ana Reyes is no longer active. Pick who signs now.');
});

it('leaves a blank line for a signatory cleared on Leave Settings', function () {
    $access = Access::forActor(lfsetActor(['LEAVES'], allData: true));

    Livewire::test(LeaveCreditSettingsPage::class)
        ->set('tab', 'signatories')
        ->call('editSignatories')
        ->call('clearSignatory', 'APPROVED')
        ->assertSee('Saving leaves a blank line to sign by hand.')
        ->call('saveSignatories');

    expect(app(LeaveSignatoryRecords::class)->all($access)['APPROVED']->name)->toBe('')
        ->and(app(LeaveSignatoryRecords::class)->all($access)['CERTIFIED']->name)->toBe('');
});

it('drops a forged pick of an unknown or inactive employee', function () {
    lfsetActor(['LEAVES'], allData: true);
    $gone = lfsetEmployee('EMP-2', ['is_active' => false]);

    Livewire::test(LeaveCreditSettingsPage::class)
        ->set('tab', 'signatories')
        ->call('editSignatories')
        ->dispatch('kit-employee-picked', name: 'signatory-CERTIFIED', id: (string) Str::ulid())
        ->assertSet('signatoryForm.CERTIFIED', '')
        ->dispatch('kit-employee-picked', name: 'signatory-CERTIFIED', id: $gone->getKey())
        ->assertSet('signatoryForm.CERTIFIED', '');
});

it('reads the signatories to a viewer without letting them save', function () {
    lfsetActor(['LEAVES']);
    $ana = lfsetEmployee('EMP-2');

    Livewire::test(LeaveCreditSettingsPage::class)
        ->set('tab', 'signatories')
        ->assertDontSeeHtml('data-signatory-edit')
        ->assertSee('Approved by')
        ->call('editSignatories')
        ->assertSet('signatoriesEditing', false)
        ->dispatch('kit-employee-picked', name: 'signatory-APPROVED', id: $ana->getKey())
        ->call('clearSignatory', 'CERTIFIED')
        ->call('saveSignatories');

    expect(LeaveSignatory::query()->count())->toBe(0);
});

it('keeps an account that set an officer', function () {
    $user = lfsetActor(['LEAVES'], allData: true);
    expect(app(AccountReferences::class)->references($user->getKey()))->toBeFalse();

    app(SaveLeaveSignatory::class)->handle(Access::forActor($user), 'CERTIFIED', lfsetEmployee('EMP-2')->getKey());

    expect(app(AccountReferences::class)->references($user->getKey()))->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| The head of office
|--------------------------------------------------------------------------
*/

it('sets a department head, leaves it when not given, and clears it when blank', function () {
    $access = Access::forActor(lfsetActor(['RECORDS'], allData: true));
    $save = app(SaveDepartment::class);

    $save->handle($access, null, 'Finance', '', 'Juan Cruz', 'Finance Manager');
    $node = OrgNode::query()->sole();
    expect($node->only(['head_name', 'head_designation']))->toBe(['head_name' => 'Juan Cruz', 'head_designation' => 'Finance Manager'])
        ->and(AuditLog::query()->where('action', 'STRUCTURE_CREATED')->sole()->details)->toContain('head of office: Juan Cruz, Finance Manager');

    $save->handle($access, $node->id, 'Finance', '');
    expect($node->refresh()->head_name)->toBe('Juan Cruz');

    $save->handle($access, $node->id, 'Finance', '', '', '');
    expect($node->refresh()->head_name)->toBeNull()
        ->and(AuditLog::query()->where('action', 'STRUCTURE_UPDATED')->latest('id')->first()?->details)->toContain('head of office: cleared');
});

it('sets a unit head and refuses an over-long one', function () {
    $access = Access::forActor(lfsetActor(['RECORDS'], allData: true));
    $department = lfsetNode('1', OrgNode::KIND_DEPARTMENT, 'Finance');

    app(SaveUnit::class)->handle($access, null, 'Payroll', $department->id, '', 'Rosa Lim', 'Unit Head');

    expect(OrgNode::query()->where('kind', OrgNode::KIND_UNIT)->sole()->head_name)->toBe('Rosa Lim')
        ->and(fn () => app(SaveUnit::class)->handle($access, null, 'Budget', $department->id, '', str_repeat('x', 201), ''))->toThrow(RuntimeException::class);
});

it('refuses a head of office without all-data records access', function () {
    $access = Access::forActor(lfsetActor(['RECORDS']));

    expect(fn () => app(SaveDepartment::class)->handle($access, null, 'Finance', '', 'Juan', ''))->toThrow(RuntimeException::class);
    expect(OrgNode::query()->count())->toBe(0);
});

it('names the unit head first, then the department head, then nobody', function (?string $unitHead, ?string $departmentHead, ?string $expected) {
    $access = Access::forActor(lfsetActor(['PORTAL']));
    $department = lfsetNode('1', OrgNode::KIND_DEPARTMENT, 'Finance', null, $departmentHead, 'Dept Title');
    $unit = lfsetNode('2', OrgNode::KIND_UNIT, 'Payroll', $department->id, $unitHead, 'Unit Title');

    expect(app(StructureRecords::class)->officeHead($access, $unit->id, $department->id)['name'] ?? null)->toBe($expected);
})->with([
    'the unit has one' => ['Rosa Lim', 'Juan Cruz', 'Rosa Lim'],
    'only the department has one' => [null, 'Juan Cruz', 'Juan Cruz'],
    'nobody set' => [null, null, null],
]);

it('edits a head on Departments & Units and shows it on the applicant\'s form', function () {
    $department = lfsetNode('1', OrgNode::KIND_DEPARTMENT, 'Finance');
    $mine = lfsetPlaced('EMP-2', $department);
    lfsetActor(['RECORDS', 'PORTAL'], allData: true, employeeId: $mine->getKey());

    Livewire::test(DepartmentsPage::class)
        ->call('startEdit', $department->id)
        ->assertSeeHtml('data-office-head-fields')
        ->set('formHeadName', 'Juan Cruz')
        ->set('formHeadDesignation', 'Finance Manager')
        ->call('save')
        ->assertSee('Head of office: Juan Cruz, Finance Manager');

    Livewire::test(PortalPage::class)->set('tab', 'requests')
        ->assertSeeHtml('data-office-head>Juan Cruz</dd>')
        ->assertSee('Finance Manager');
});

it('keeps a blank head of office line for an unplaced employee', function () {
    $mine = lfsetEmployee('EMP-3');
    lfsetActor(['PORTAL'], employeeId: $mine->getKey());

    Livewire::test(PortalPage::class)->set('tab', 'requests')->assertSeeHtml("data-office-head>\u{2014}</dd>");
});

/*
|--------------------------------------------------------------------------
| Approved for: others (specify)
|--------------------------------------------------------------------------
*/

it('keeps the approval note, trimmed, and changes nothing else about the approval', function () {
    lfsetType('Vacation Leave');
    $mine = lfsetEmployee('EMP-4');
    lfsetCredit($mine, 'VACATION_LEAVE', 15);
    $request = lfsetRequest($mine, 'LR-1');
    $access = Access::forActor(lfsetActor(['LEAVES'], allData: true));

    app(ReviewAbsence::class)->handle($access, $request->id, 'APPROVED', true, null, '  Subject to turnover  ');

    expect($request->refresh()->only(['status', 'is_paid', 'approval_note']))->toBe(['status' => 'APPROVED', 'is_paid' => true, 'approval_note' => 'Subject to turnover'])
        ->and((float) $request->credit_days)->toBe(2.0);
});

it('keeps no note on a disapproval or on official business, and refuses an over-long one', function () {
    lfsetType('Vacation Leave');
    $mine = lfsetEmployee('EMP-5');
    $access = Access::forActor(lfsetActor(['LEAVES'], allData: true));
    $rejected = lfsetRequest($mine, 'LR-2');
    $travel = lfsetRequest($mine, 'LR-3', ['request_type' => 'BUSINESS_TRAVEL', 'start_date' => '2026-11-20', 'end_date' => '2026-11-20']);
    $long = lfsetRequest($mine, 'LR-4', ['start_date' => '2026-11-24', 'end_date' => '2026-11-24']);

    app(ReviewAbsence::class)->handle($access, $rejected->id, 'REJECTED', null, 'Short staff', 'ignored');
    app(ReviewAbsence::class)->handle($access, $travel->id, 'APPROVED', null, null, 'ignored');

    expect($rejected->refresh()->approval_note)->toBeNull()
        ->and($travel->refresh()->approval_note)->toBeNull()
        ->and(fn () => app(ReviewAbsence::class)->handle($access, $long->id, 'APPROVED', true, null, str_repeat('x', 256)))->toThrow(RuntimeException::class);
    expect($long->refresh()->status)->toBe('PENDING');
});

it('asks for the note in the review dialog and shows it afterwards', function () {
    lfsetType('Vacation Leave');
    $mine = lfsetEmployee('EMP-6');
    lfsetCredit($mine, 'VACATION_LEAVE', 15);
    $request = lfsetRequest($mine, 'LR-5');
    lfsetActor(['LEAVES'], allData: true);

    Livewire::test(Index::class)
        ->call('show', $request->id)
        ->assertSeeHtml('data-approval-note-field')
        ->set('approvalNote', 'Subject to turnover')
        ->call('review', 'APPROVED', true)
        ->assertSet('approvalNote', '')
        ->call('show', $request->id)
        ->assertSeeHtml('data-approval-note')
        ->assertSee('Subject to turnover')
        ->assertDontSeeHtml('data-approval-note-field');

    expect($request->refresh()->approval_note)->toBe('Subject to turnover');
});

/*
|--------------------------------------------------------------------------
| The filed application
|--------------------------------------------------------------------------
*/

it('opens the employee\'s own filed application, read-only, with what was filed', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-12 09:00:00', LocalClock::zone()));
    lfsetType('Vacation Leave', 'VACATION');
    $mine = lfsetEmployee('EMP-7');
    lfsetCredit($mine, 'VACATION_LEAVE', 15);
    $request = lfsetRequest($mine, 'LR-6', ['leave_detail' => 'ABROAD', 'leave_detail_note' => 'Japan', 'commutation' => 'REQUESTED']);
    lfsetActor(['PORTAL'], employeeId: $mine->getKey());

    $page = Livewire::test(PortalPage::class)->set('tab', 'requests')
        ->assertSeeHtml('data-view-filed')
        ->assertDontSeeHtml('data-filed-application')
        ->call('viewFiled', $request->id)
        ->assertSet('filedOpen', true)
        ->assertSeeHtml('data-filed-application')
        ->assertSeeHtml('data-applicant-receipt>Nov 12, 2026</dd>')
        ->assertSeeHtml('data-filed-note>Japan</span>')
        ->assertSeeHtml('data-filed-start>Nov 17, 2026</dd>')
        ->assertSeeHtml('data-filed-end>Nov 18, 2026</dd>')
        ->assertSeeHtml('data-filed-reason>Family trip</dd>')
        ->assertSeeHtml('data-working-days>2</dd>')
        ->assertSeeHtml('data-filed-status>PENDING</span>');

    expect($page->viewData('filedForm')['certification'][0])->toMatchArray(['earned' => 15.0, 'less' => 2.0, 'balance' => 13.0]);

    $page->set('filedOpen', false)->assertSet('filedId', null)->assertDontSeeHtml('data-filed-application');
});

it('refuses another employee\'s filed application, even to an all-data holder', function () {
    lfsetType('Vacation Leave');
    $other = lfsetEmployee('EMP-8', ['first_name' => 'Other']);
    $request = lfsetRequest($other, 'LR-7');
    $mine = lfsetEmployee('EMP-9');
    lfsetActor(['PORTAL', 'LEAVES'], allData: true, employeeId: $mine->getKey());

    Livewire::test(PortalPage::class)->set('tab', 'requests')
        ->call('viewFiled', $request->id)
        ->assertSet('filedId', null)
        ->assertSet('filedOpen', false)
        ->assertDontSeeHtml('data-filed-application');
});

it('refuses a filed application to an account with no employee record', function () {
    lfsetType('Vacation Leave');
    $request = lfsetRequest(lfsetEmployee('EMP-10'), 'LR-8');
    lfsetActor(['PORTAL']);

    Livewire::test(PortalPage::class)->set('tab', 'requests')->call('viewFiled', $request->id)->assertSet('filedId', null);
});

it('locks the open filed id against the client', function () {
    $mine = lfsetEmployee('EMP-11');
    lfsetActor(['PORTAL'], employeeId: $mine->getKey());

    expect(fn () => Livewire::test(PortalPage::class)->set('filedId', 'forged'))->toThrow(Exception::class);
});

it('fills 7.C and 7.A from a paid approval, adding its cost back to what it was taken from', function () {
    lfsetType('Vacation Leave');
    $mine = lfsetEmployee('EMP-12');
    lfsetCredit($mine, 'VACATION_LEAVE', 15);
    $request = lfsetRequest($mine, 'LR-9');
    app(ReviewAbsence::class)->handle(Access::forActor(lfsetActor(['LEAVES'], allData: true)), $request->id, 'APPROVED', true, null, 'Subject to turnover');
    lfsetActor(['PORTAL'], employeeId: $mine->getKey());

    $page = Livewire::test(PortalPage::class)->set('tab', 'requests')->call('viewFiled', $request->id)
        ->assertSeeHtml('data-filed-with-pay>2</dd>')
        ->assertSeeHtml('data-filed-without-pay>0</dd>')
        ->assertSeeHtml('data-filed-others>Subject to turnover</dd>')
        ->assertSeeHtml('data-filed-status>APPROVED</span>');

    expect($page->viewData('filedForm')['certification'][0])->toMatchArray(['earned' => 15.0, 'less' => 2.0, 'balance' => 13.0]);
});

it('splits 7.C when the credits covered only part', function () {
    lfsetType('Vacation Leave');
    $mine = lfsetEmployee('EMP-13');
    lfsetCredit($mine, 'VACATION_LEAVE', 1);
    $request = lfsetRequest($mine, 'LR-10');
    app(ReviewAbsence::class)->handle(Access::forActor(lfsetActor(['LEAVES'], allData: true)), $request->id, 'APPROVED', true);
    lfsetActor(['PORTAL'], employeeId: $mine->getKey());

    Livewire::test(PortalPage::class)->set('tab', 'requests')->call('viewFiled', $request->id)
        ->assertSeeHtml('data-filed-with-pay>1</dd>')
        ->assertSeeHtml('data-filed-without-pay>1</dd>')
        ->assertSeeHtml('data-working-days>2</dd>');
});

it('fills 7.C with no paid days when approved without pay, and takes nothing from the credits', function () {
    lfsetType('Vacation Leave');
    $mine = lfsetEmployee('EMP-14');
    lfsetCredit($mine, 'VACATION_LEAVE', 15);
    $request = lfsetRequest($mine, 'LR-11');
    app(ReviewAbsence::class)->handle(Access::forActor(lfsetActor(['LEAVES'], allData: true)), $request->id, 'APPROVED', false);
    lfsetActor(['PORTAL'], employeeId: $mine->getKey());

    $page = Livewire::test(PortalPage::class)->set('tab', 'requests')->call('viewFiled', $request->id)
        ->assertSeeHtml('data-filed-with-pay>0</dd>')
        ->assertSeeHtml('data-filed-without-pay>2</dd>');

    expect($page->viewData('filedForm')['certification'][0])->toMatchArray(['earned' => 15.0, 'less' => 0.0, 'balance' => 15.0]);
});

it('fills 7.B and 7.D from the recommendation and the disapproval', function () {
    lfsetType('Vacation Leave');
    $mine = lfsetEmployee('EMP-15');
    $request = lfsetRequest($mine, 'LR-12', ['recommendation' => 'FOR_DISAPPROVAL', 'recommendation_reason' => 'Short staff']);
    app(ReviewAbsence::class)->handle(Access::forActor(lfsetActor(['LEAVES'], allData: true)), $request->id, 'REJECTED', null, 'Peak season');
    lfsetActor(['PORTAL'], employeeId: $mine->getKey());

    Livewire::test(PortalPage::class)->set('tab', 'requests')->call('viewFiled', $request->id)
        ->assertSeeHtml('data-filed-recommendation-reason>Short staff</span>')
        ->assertSeeHtml('data-filed-disapproved>Peak season</p>')
        ->assertSeeHtml('data-filed-status>DISAPPROVED</span>')
        ->assertSeeHtml("data-filed-with-pay>\u{2014}</dd>");
});

it('still shows the type filed after HR removed it', function () {
    $mine = lfsetEmployee('EMP-16');
    $request = lfsetRequest($mine, 'LR-13', ['request_type' => 'BEREAVEMENT_LEAVE']);
    lfsetActor(['PORTAL'], employeeId: $mine->getKey());

    $page = Livewire::test(PortalPage::class)->set('tab', 'requests')->call('viewFiled', $request->id);

    expect(array_column($page->viewData('filedForm')['types'], 'value'))->toContain('BEREAVEMENT_LEAVE');
});

it('marks the same line for an older request\'s short type code, without listing the type twice', function () {
    lfsetType('Vacation Leave');
    $mine = lfsetEmployee('EMP-18');
    $request = lfsetRequest($mine, 'LR-15', ['request_type' => 'VACATION']);
    lfsetActor(['PORTAL'], employeeId: $mine->getKey());

    $form = Livewire::test(PortalPage::class)->set('tab', 'requests')->call('viewFiled', $request->id)->viewData('filedForm');

    expect(array_column($form['types'], 'value'))->toBe(['VACATION_LEAVE'])
        ->and($form['type'])->toBe('VACATION_LEAVE');
});

it('shows official business with no days to state', function () {
    $mine = lfsetEmployee('EMP-17');
    $request = lfsetRequest($mine, 'LR-14', ['request_type' => 'BUSINESS_TRAVEL']);
    lfsetActor(['PORTAL'], employeeId: $mine->getKey());

    Livewire::test(PortalPage::class)->set('tab', 'requests')->call('viewFiled', $request->id)
        ->assertSeeHtml('data-filed-application')
        ->assertSeeHtml("data-working-days>\u{2014}</dd>");
});

/*
|--------------------------------------------------------------------------
| Leave Management's view of the form
|--------------------------------------------------------------------------
*/

it('opens the employee\'s application from the review dialog, read-only', function () {
    lfsetType('Vacation Leave', 'VACATION');
    $department = lfsetNode('1', OrgNode::KIND_DEPARTMENT, 'Finance', null, 'Juan Cruz', 'Finance Manager');
    $employee = lfsetPlaced('EMP-20', $department);
    $employee->forceFill(['middle_name' => 'Reyes'])->save();
    lfsetCredit($employee, 'VACATION_LEAVE', 15);
    $request = lfsetRequest($employee, 'LR-20', ['leave_detail' => 'ABROAD', 'leave_detail_note' => 'Japan', 'commutation' => 'REQUESTED']);
    lfsetActor(['LEAVES'], allData: true);

    $page = Livewire::test(Index::class)
        ->call('show', $request->id)
        ->assertSeeHtml('data-view-application')
        ->assertDontSeeHtml('data-filed-application')
        ->call('viewApplication')
        ->assertSet('formOpen', true)
        ->assertSeeHtml('data-filed-application')
        ->assertSeeHtml('data-applicant-last>Domingo</dd>')
        ->assertSeeHtml('data-applicant-middle>Reyes</dd>')
        ->assertSeeHtml('data-applicant-office>Finance</dd>')
        ->assertSeeHtml('data-office-head>Juan Cruz</dd>')
        ->assertSeeHtml('data-filed-note>Japan</span>')
        ->assertSeeHtml('data-officer-approved>—</dd>');

    expect($page->viewData('applicationForm')['filed']['certification'][0])->toMatchArray(['earned' => 15.0, 'less' => 2.0, 'balance' => 13.0]);
});

it('hides the salary from a leave manager who cannot open the employee\'s record', function () {
    lfsetType('Vacation Leave');
    $employee = lfsetEmployee('EMP-21');
    $request = lfsetRequest($employee, 'LR-21');
    lfsetActor(['LEAVES'], allData: true);

    Livewire::test(Index::class)->call('show', $request->id)->call('viewApplication')
        ->assertSeeHtml("data-applicant-salary>\u{2014}</dd>")
        ->assertDontSee('$35,000.00');
});

it('shows the salary to a leave manager who also holds the employee\'s record', function (string $module) {
    lfsetType('Vacation Leave');
    $employee = lfsetEmployee('EMP-22');
    $request = lfsetRequest($employee, 'LR-22');
    lfsetActor(['LEAVES', $module], allData: true);

    Livewire::test(Index::class)->call('show', $request->id)->call('viewApplication')
        ->assertSeeHtml('data-applicant-salary>$35,000.00</dd>');
})->with(['EMPLOYEES', 'PAYROLL']);

it('reads the employee\'s credits on the form, never the manager\'s', function () {
    lfsetType('Vacation Leave');
    $employee = lfsetEmployee('EMP-23');
    lfsetCredit($employee, 'VACATION_LEAVE', 7);
    $request = lfsetRequest($employee, 'LR-23');
    $manager = lfsetEmployee('EMP-24', ['first_name' => 'Manager']);
    lfsetCredit($manager, 'VACATION_LEAVE', 40);
    lfsetActor(['LEAVES'], allData: true, employeeId: $manager->getKey());

    $page = Livewire::test(Index::class)->call('show', $request->id)->call('viewApplication');

    expect($page->viewData('applicationForm')['filed']['certification'][0])->toMatchArray(['earned' => 7.0, 'less' => 2.0, 'balance' => 5.0]);
});

it('shows an approved application with section 7 filled in', function () {
    lfsetType('Vacation Leave');
    $employee = lfsetEmployee('EMP-25');
    lfsetCredit($employee, 'VACATION_LEAVE', 15);
    $request = lfsetRequest($employee, 'LR-25');
    $access = Access::forActor(lfsetActor(['LEAVES'], allData: true));
    app(ReviewAbsence::class)->handle($access, $request->id, 'APPROVED', true, null, 'Subject to turnover');

    Livewire::test(Index::class)->call('show', $request->id)->call('viewApplication')
        ->assertSeeHtml('data-filed-with-pay>2</dd>')
        ->assertSeeHtml('data-filed-others>Subject to turnover</dd>')
        ->assertSeeHtml('data-filed-status>APPROVED</span>');
});

it('opens no form without an open request, and closes it with the review', function () {
    lfsetType('Vacation Leave');
    $request = lfsetRequest(lfsetEmployee('EMP-26'), 'LR-26');
    lfsetActor(['LEAVES'], allData: true);

    Livewire::test(Index::class)
        ->call('viewApplication')
        ->assertSet('formOpen', false)
        ->call('show', $request->id)
        ->call('viewApplication')
        ->assertSet('formOpen', true)
        ->set('detailOpen', false)
        ->assertSet('formOpen', false)
        ->assertDontSeeHtml('data-filed-application');
});

it('keeps another employee\'s application from a leaves holder without all-data', function () {
    lfsetType('Vacation Leave');
    $request = lfsetRequest(lfsetEmployee('EMP-27'), 'LR-27');
    $mine = lfsetEmployee('EMP-28', ['first_name' => 'Mine']);
    lfsetActor(['LEAVES'], employeeId: $mine->getKey());

    Livewire::test(Index::class)
        ->call('show', $request->id)
        ->assertSet('openId', null)
        ->call('viewApplication')
        ->assertSet('formOpen', false)
        ->assertDontSeeHtml('data-filed-application');
});

it('tells nothing of another employee to a caller who may not read them', function () {
    $other = lfsetEmployee('EMP-29', ['first_name' => 'Other']);
    $mine = lfsetEmployee('EMP-30');
    $access = Access::forActor(lfsetActor(['LEAVES'], employeeId: $mine->getKey()));

    expect(app(EmployeeRecords::class)->applicantIdentity($access, $other->getKey()))->toBeNull()
        ->and(app(EmployeeRecords::class)->applicantIdentity($access, $mine->getKey()))->not->toBeNull();
});

/*
|--------------------------------------------------------------------------
| Always white
|--------------------------------------------------------------------------
*/

it('keeps every dialog of the form out of dark mode', function () {
    lfsetType('Vacation Leave');
    $mine = lfsetEmployee('EMP-40');
    $request = lfsetRequest($mine, 'LR-40');
    lfsetActor(['PORTAL', 'LEAVES'], allData: true, employeeId: $mine->getKey());

    expect(substr_count(Livewire::test(PortalPage::class)->set('tab', 'requests')->html(), 'hris-always-light'))->toBe(2)
        ->and(substr_count(Livewire::test(Index::class)->html(), 'hris-always-light'))->toBe(1);
});

it('has the dark variant skip an always-light surface, which redeclares the light tokens', function () {
    $css = str_replace("\r\n", "\n", (string) file_get_contents(resource_path('css/app.css')));

    expect($css)->toContain('@custom-variant dark (&:where(.dark, .dark *):where(:not(.hris-always-light, .hris-always-light *)));')
        ->toContain(":root,\n.hris-always-light {\n");
});

it('redeclares every theme colour on an always-light surface', function () {
    $css = str_replace("\r\n", "\n", (string) file_get_contents(resource_path('css/app.css')));
    preg_match('/@theme inline \{(.*?)\n\}/s', $css, $theme);
    preg_match('/\.hris-always-light \{\s+color-scheme: light;(.*?)\n\}/s', $css, $light);
    preg_match_all('/(--color-[a-z0-9-]+):\s*(var\(--[a-z0-9-]+\));/', $theme[1] ?? '', $tokens, PREG_SET_ORDER);

    expect(count($tokens))->toBeGreaterThan(20);
    foreach ($tokens as [, $name, $value]) {
        expect($light[1] ?? '')->toContain("{$name}: {$value};");
    }
});

/*
|--------------------------------------------------------------------------
| The migrations
|--------------------------------------------------------------------------
*/

it('runs each migration twice and rolls back', function (string $file, string $table, array $columns) {
    $migration = require base_path("database/migrations/{$file}");
    $migration->up();
    $migration->up();
    expect(Schema::hasColumns($table, $columns))->toBeTrue();
})->with([
    'officers' => ['2026_11_11_0001_create_leave_signatories_table.php', 'leave_signatories', ['slot', 'name', 'designation', 'updated_by', 'updated_by_name']],
    'head of office' => ['2026_11_11_0002_add_head_of_office_to_org_nodes_table.php', 'org_nodes', ['head_name', 'head_designation']],
    'approval note' => ['2026_11_11_0003_add_approval_note_to_leave_travel_requests_table.php', 'leave_travel_requests', ['approval_note']],
    'signatory employee' => ['2026_11_11_0005_add_employee_to_leave_signatories_table.php', 'leave_signatories', ['employee_id']],
]);

it('reads a typed officer and refuses a pick before the employee column exists', function () {
    $engine = in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true);
    $migration = require base_path('database/migrations/2026_11_11_0005_add_employee_to_leave_signatories_table.php');
    $migration->down();
    Once::flush();
    if ($engine) {
        DB::connection()->getPdo()->beginTransaction();
    }

    try {
        $access = Access::forActor(lfsetActor(['LEAVES'], allData: true));
        $row = new LeaveSignatory;
        $row->forceFill(['slot' => 'APPROVED', 'name' => 'Typed Name', 'designation' => 'Typed Title'])->save();

        expect(LeaveSignatoryRecords::employeesReady())->toBeFalse()
            ->and(app(LeaveSignatoryRecords::class)->all($access)['APPROVED']->name)->toBe('Typed Name')
            ->and(fn () => app(SaveLeaveSignatory::class)->handle($access, 'APPROVED', null))->toThrow(RuntimeException::class);

        Livewire::test(LeaveCreditSettingsPage::class)->set('tab', 'signatories')
            ->assertSee('The signatories can be chosen once')
            ->assertDontSeeHtml('data-signatory-edit');
    } finally {
        if ($engine) {
            DB::connection()->getPdo()->rollBack();
        }
        $migration->up();
        Once::flush();
        if ($engine) {
            DB::connection()->getPdo()->beginTransaction();
        }
    }
});

it('reads blank officers before their table exists', function () {
    $engine = in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true);
    $employeeColumn = require base_path('database/migrations/2026_11_11_0005_add_employee_to_leave_signatories_table.php');
    $migration = require base_path('database/migrations/2026_11_11_0001_create_leave_signatories_table.php');
    $migration->down();
    Once::flush();
    if ($engine) {
        DB::connection()->getPdo()->beginTransaction();
    }

    try {
        $access = Access::forActor(lfsetActor(['LEAVES'], allData: true));
        expect(LeaveSignatoryRecords::ready())->toBeFalse()
            ->and(app(LeaveSignatoryRecords::class)->all($access)['APPROVED']->name)->toBe('')
            ->and(fn () => app(SaveLeaveSignatory::class)->handle($access, 'APPROVED', null))->toThrow(RuntimeException::class);
    } finally {
        if ($engine) {
            DB::connection()->getPdo()->rollBack();
        }
        $migration->up();
        $employeeColumn->up();
        Once::flush();
        if ($engine) {
            DB::connection()->getPdo()->beginTransaction();
        }
    }
});
