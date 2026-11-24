<?php

declare(strict_types=1);

use App\Livewire\Portal\PortalPage;
use App\Models\AuditLog;
use App\Models\EmployeeProfile;
use App\Models\LeaveCredit;
use App\Models\LeaveRule;
use App\Models\LeaveTravelRequest;
use App\Models\LeaveType;
use App\Models\OrgNode;
use App\Models\Position;
use App\Models\Role;
use App\Models\RoleModuleGrant;
use App\Models\User;
use App\Modules\Timekeeping\Domain\Credits\LeaveTypeRule;
use App\Support\Access;
use App\Support\LocalClock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Once;
use Illuminate\Support\Str;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

/**
 * My Portal's filing form carries the fields of PPMC's Application for Leave.
 * Sections 1 to 5 are the session's OWN record, even for an all-data holder,
 * and are never taken from input. 6.A lists Leave Settings in the form's
 * order, less the types the employee may not use, with official business
 * under Others. Section 7 is the office's: it states the standing today and
 * what the draft would take, and writes nothing. Filing is FileAbsence as
 * before, and the dialog closes only when the request is filed.
 */

/** @param  list<string>  $modules */
function lformActor(array $modules, bool $allData = false, ?string $employeeId = null): User
{
    $role = new Role;
    $role->forceFill(['name' => 'Form '.Str::random(6), 'all_data' => $allData, 'is_system' => false])->save();
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
function lformEmployee(string $code, array $overrides = []): EmployeeProfile
{
    $employee = new EmployeeProfile;
    $employee->forceFill(array_merge([
        'employee_code' => $code, 'first_name' => 'Liza', 'middle_name' => 'Reyes', 'last_name' => 'Domingo',
        'position' => 'Accountant', 'monthly_rate' => 35000, 'employment_status' => 'Regular',
        'date_hired' => '2024-01-01', 'is_active' => true,
    ], $overrides))->save();

    return $employee;
}

function lformType(string $name, string $details = 'NONE'): LeaveType
{
    $type = new LeaveType;
    $type->forceFill(['code' => 'LF-'.Str::random(6), 'name' => $name, 'leave_details' => $details])->save();

    return $type;
}

function lformCredit(EmployeeProfile $employee, string $token, float $credits): void
{
    $credit = new LeaveCredit;
    $credit->forceFill(['employee_id' => $employee->getKey(), 'year' => 2026, 'request_type' => $token, 'credits' => $credits])->save();
}

/**
 * The page on its Leave & travel tab, with the clock on a Monday in 2026.
 *
 * @return Testable<PortalPage>
 */
function lformPage(): Testable
{
    return Livewire::test(PortalPage::class)->set('tab', 'requests');
}

beforeEach(fn () => CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-16 09:00:00', LocalClock::zone())));
afterEach(fn () => CarbonImmutable::setTestNow());

/*
|--------------------------------------------------------------------------
| Sections 1 to 5
|--------------------------------------------------------------------------
*/

it('fills the identity lines from the employee\'s own record', function () {
    lformType('Vacation Leave');
    $mine = lformEmployee('EMP-1', ['suffix' => 'Jr.']);
    lformActor(['PORTAL'], employeeId: $mine->getKey());

    lformPage()
        ->assertSeeHtml('data-leave-application')
        ->assertSee('Application for Leave')
        ->assertSee('Domingo Jr.')
        ->assertSee('Liza')
        ->assertSee('Reyes')
        ->assertSee('Accountant')
        ->assertSee('$35,000.00')
        ->assertSee('Nov 16, 2026');
});

it('keeps an all-data holder\'s form to their own record', function () {
    lformEmployee('EMP-2', ['first_name' => 'Other', 'last_name' => 'Person', 'position' => 'Director', 'monthly_rate' => 99000]);
    $mine = lformEmployee('EMP-3');
    lformActor(['PORTAL', 'LEAVES', 'EMPLOYEES', 'PAYROLL'], allData: true, employeeId: $mine->getKey());

    lformPage()
        ->assertSee('$35,000.00')
        ->assertDontSee('Director')
        ->assertDontSee('$99,000.00');
});

it('says a daily rate is daily, and leaves an unset salary blank', function (array $rates, ?string $shown) {
    $mine = lformEmployee('EMP-4', $rates);
    lformActor(['PORTAL'], employeeId: $mine->getKey());

    $data = lformPage()->viewData('applicant');

    expect($data['salary'])->toBe($shown ?? '');
})->with([
    'daily' => [['monthly_rate' => 0, 'daily_rate' => 1200], '$1,200.00 a day'],
    'none' => [['monthly_rate' => 0, 'daily_rate' => null], null],
]);

it('draws no form for an account with no employee record', function () {
    lformActor(['PORTAL']);

    lformPage()
        ->assertDontSeeHtml('data-leave-application')
        ->assertDontSeeHtml('data-apply-button');
});

/*
|--------------------------------------------------------------------------
| Section 6
|--------------------------------------------------------------------------
*/

it('lists the leave types in the form\'s order with their citations, and official business under Others', function () {
    foreach (['Leave Without Pay', 'Sick Leave', 'Vacation Leave', 'Business Travel / OB'] as $name) {
        lformType($name);
    }
    $mine = lformEmployee('EMP-5');
    lformActor(['PORTAL'], employeeId: $mine->getKey());

    $page = lformPage();

    expect(array_column($page->viewData('applyTypes'), 'label'))->toBe(['Vacation Leave', 'Sick Leave', 'Leave Without Pay']);
    $page->assertSee('Sec. 51, Rule XVI, Omnibus Rules Implementing E.O. No. 292')
        ->assertSee('Others')
        ->assertSee('Official Business');
});

it('leaves out a type the employee may not use', function () {
    lformType('Vacation Leave');
    $maternity = lformType('Maternity Leave');
    $maternity->forceFill(['eligible_sex' => 'FEMALE'])->save();
    $mine = lformEmployee('EMP-6', ['sex' => 'MALE']);
    lformActor(['PORTAL'], employeeId: $mine->getKey());

    expect(array_column(lformPage()->viewData('applyTypes'), 'label'))->toBe(['Vacation Leave']);
});

it('answers only the picked type\'s details, and asks neither details nor commutation on official business', function () {
    lformType('Vacation Leave', 'VACATION');
    $mine = lformEmployee('EMP-7');
    lformActor(['PORTAL'], employeeId: $mine->getKey());

    lformPage()
        ->assertDontSeeHtml('data-leave-details')
        ->assertDontSeeHtml('data-commutation')
        ->assertSee('In case of Sick Leave')
        ->set('type', 'VACATION_LEAVE')
        ->assertSeeHtml('data-leave-details="VACATION"')
        ->assertDontSeeHtml('data-leave-details="SICK"')
        ->assertSeeHtml('data-commutation')
        ->set('type', 'BUSINESS_TRAVEL')
        ->assertDontSeeHtml('data-leave-details')
        ->assertDontSeeHtml('data-commutation');
});

it('counts the working days applied for, and shows none on official business', function () {
    lformType('Vacation Leave');
    $mine = lformEmployee('EMP-8');
    lformCredit($mine, 'VACATION_LEAVE', 15);
    lformActor(['PORTAL'], employeeId: $mine->getKey());

    $page = lformPage()->set('type', 'VACATION_LEAVE')->set('start', '2026-11-17')->set('end', '2026-11-19');

    expect($page->viewData('creditHint')['needs'])->toBe(3.0);
    $page->assertSeeHtml('data-working-days>3</dd>');

    expect($page->set('type', 'BUSINESS_TRAVEL')->viewData('creditHint'))->toBeNull();
});

/*
|--------------------------------------------------------------------------
| Section 7
|--------------------------------------------------------------------------
*/

it('states the standing today and what the draft would take from it', function () {
    lformType('Vacation Leave');
    lformType('Sick Leave');
    $mine = lformEmployee('EMP-9');
    lformCredit($mine, 'VACATION_LEAVE', 15);
    lformCredit($mine, 'SICK_LEAVE', 10);
    lformActor(['PORTAL'], employeeId: $mine->getKey());

    $page = lformPage()->set('type', 'VACATION_LEAVE')->set('start', '2026-11-17')->set('end', '2026-11-18');

    expect($page->viewData('certification'))->toBe([
        ['name' => 'Vacation Leave', 'unit' => 'DAYS', 'earned' => 15.0, 'less' => 2.0, 'balance' => 13.0],
        ['name' => 'Sick Leave', 'unit' => 'DAYS', 'earned' => 10.0, 'less' => 0.0, 'balance' => 10.0],
    ]);
    $page->assertSeeHtml('data-leave-certification')->assertSee('Less this application');
});

it('gives no column to a type with no credit entered', function () {
    lformType('Vacation Leave');
    lformType('Adoption Leave');
    $mine = lformEmployee('EMP-17');
    lformCredit($mine, 'VACATION_LEAVE', 15);
    lformActor(['PORTAL'], employeeId: $mine->getKey());

    expect(array_column(lformPage()->viewData('certification'), 'name'))->toBe(['Vacation Leave']);
});

it('takes only what the credits cover, as the without-pay notice says', function () {
    lformType('Vacation Leave');
    $mine = lformEmployee('EMP-10');
    lformCredit($mine, 'VACATION_LEAVE', 1);
    lformActor(['PORTAL'], employeeId: $mine->getKey());

    $page = lformPage()->set('type', 'VACATION_LEAVE')->set('start', '2026-11-17')->set('end', '2026-11-18');

    expect($page->viewData('certification')[0])->toMatchArray(['earned' => 1.0, 'less' => 1.0, 'balance' => 0.0]);
    $page->assertSeeHtml('data-credit-notice');
});

it('never reads another employee\'s credits into the certification', function () {
    lformType('Vacation Leave');
    $other = lformEmployee('EMP-11', ['first_name' => 'Other']);
    lformCredit($other, 'VACATION_LEAVE', 40);
    $mine = lformEmployee('EMP-12');
    lformCredit($mine, 'VACATION_LEAVE', 5);
    lformActor(['PORTAL', 'LEAVES'], allData: true, employeeId: $mine->getKey());

    expect(lformPage()->viewData('certification')[0]['earned'])->toBe(5.0);
});

/*
|--------------------------------------------------------------------------
| Filing
|--------------------------------------------------------------------------
*/

it('files the form for the session\'s own employee and closes the dialog', function () {
    lformType('Vacation Leave', 'VACATION');
    $mine = lformEmployee('EMP-13');
    lformCredit($mine, 'VACATION_LEAVE', 15);
    lformActor(['PORTAL'], employeeId: $mine->getKey());

    lformPage()
        ->set('applyOpen', true)
        ->set('type', 'VACATION_LEAVE')
        ->set('leaveDetail', 'WITHIN_PH')
        ->assertSeeHtml('data-leave-detail-note')
        ->set('leaveDetailNote', 'Baguio')
        ->set('commutation', 'REQUESTED')
        ->set('start', '2026-11-17')->set('end', '2026-11-17')
        ->call('file')
        ->assertSet('applyOpen', false)
        ->assertSet('type', '')
        ->assertSee('Within the Philippines: Baguio');

    $request = LeaveTravelRequest::query()->sole();
    expect($request->employee_id)->toBe($mine->getKey())
        ->and($request->only(['leave_detail', 'leave_detail_note', 'commutation']))
        ->toBe(['leave_detail' => 'WITHIN_PH', 'leave_detail_note' => 'Baguio', 'commutation' => 'REQUESTED'])
        ->and(AuditLog::query()->where('action', 'REQUEST_FILED')->count())->toBe(1);
});

it('keeps the dialog and the draft open when the filing is refused', function () {
    lformType('Vacation Leave', 'VACATION');
    $mine = lformEmployee('EMP-14');
    lformActor(['PORTAL'], employeeId: $mine->getKey());

    lformPage()
        ->set('applyOpen', true)
        ->set('type', 'VACATION_LEAVE')
        ->set('start', '2026-11-17')->set('end', '2026-11-17')
        ->call('file')
        ->assertSet('applyOpen', true)
        ->assertSet('type', 'VACATION_LEAVE');

    expect(LeaveTravelRequest::query()->count())->toBe(0);
});

it('files official business from the same form, with no details or commutation', function () {
    $mine = lformEmployee('EMP-15');
    lformActor(['PORTAL'], employeeId: $mine->getKey());

    lformPage()
        ->set('applyOpen', true)
        ->set('type', 'BUSINESS_TRAVEL')
        ->set('start', '2026-11-17')->set('end', '2026-11-17')
        ->call('file')
        ->assertSet('applyOpen', false);

    expect(LeaveTravelRequest::query()->sole()->only(['request_type', 'leave_detail', 'commutation']))
        ->toBe(['request_type' => 'BUSINESS_TRAVEL', 'leave_detail' => null, 'commutation' => null]);
});

it('gives way to the cash conversion dialog for a monetization', function () {
    $mine = lformEmployee('EMP-16');
    lformActor(['PORTAL'], employeeId: $mine->getKey());

    lformPage()
        ->set('applyOpen', true)
        ->call('convertCredits')
        ->assertSet('applyOpen', false)
        ->assertSet('cashOutOpen', true);
});

/*
|--------------------------------------------------------------------------
| Every field, one by one
|--------------------------------------------------------------------------
*/

it('names the office the employee\'s position sits in', function (bool $inUnit, string $shown) {
    $department = new OrgNode;
    $department->forceFill(['code' => '1', 'kind' => OrgNode::KIND_DEPARTMENT, 'name' => 'Finance Department', 'parent_id' => null, 'sort_order' => 0])->save();
    $unit = new OrgNode;
    $unit->forceFill(['code' => '2', 'kind' => OrgNode::KIND_UNIT, 'name' => 'Payroll Unit', 'parent_id' => $department->getKey(), 'sort_order' => 0])->save();
    $position = new Position;
    $position->forceFill(['code' => '1', 'name' => 'Accountant', 'org_node_id' => $inUnit ? $unit->getKey() : $department->getKey(), 'sort_order' => 0])->save();
    $mine = lformEmployee('EMP-20', ['position_id' => $position->getKey()]);
    lformActor(['PORTAL'], employeeId: $mine->getKey());

    expect(lformPage()->viewData('applicant')['office'])->toBe($shown);
})->with([
    'in the department itself' => [false, 'Finance Department'],
    'in a unit' => [true, 'Finance Department · Payroll Unit'],
]);

it('shows a dash for what the record does not hold', function () {
    $mine = lformEmployee('EMP-21', ['middle_name' => null, 'position' => null, 'monthly_rate' => 0]);
    lformActor(['PORTAL'], employeeId: $mine->getKey());

    lformPage()
        ->assertSeeHtml("data-applicant-office>\u{2014}</dd>")
        ->assertSeeHtml("data-applicant-middle>\u{2014}</dd>")
        ->assertSeeHtml("data-applicant-position>\u{2014}</dd>")
        ->assertSeeHtml("data-applicant-salary>\u{2014}</dd>");
});

it('dates the filing by the Manila day, not the UTC one', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-16 16:30:00', 'UTC'));
    $mine = lformEmployee('EMP-22');
    lformActor(['PORTAL'], employeeId: $mine->getKey());

    expect(lformPage()->viewData('applicant')['filed'])->toBe('Nov 17, 2026');
});

it('keeps each detail of leave the form offers', function (string $section, string $name, string $token, string $detail, string $note, string $keptDetail, ?string $keptNote) {
    lformType($name, $section);
    $mine = lformEmployee('EMP-23');
    lformActor(['PORTAL'], employeeId: $mine->getKey());

    lformPage()
        ->set('type', $token)->set('leaveDetail', $detail)->set('leaveDetailNote', $note)
        ->set('start', '2026-11-17')->set('end', '2026-11-17')
        ->call('file')
        ->assertSet('applyOpen', false);

    expect(LeaveTravelRequest::query()->sole()->only(['leave_detail', 'leave_detail_note']))
        ->toBe(['leave_detail' => $keptDetail, 'leave_detail_note' => $keptNote]);
})->with([
    'within the Philippines, said where' => ['VACATION', 'Vacation Leave', 'VACATION_LEAVE', 'WITHIN_PH', 'Baguio', 'WITHIN_PH', 'Baguio'],
    'within the Philippines, left blank' => ['VACATION', 'Vacation Leave', 'VACATION_LEAVE', 'WITHIN_PH', '', 'WITHIN_PH', null],
    'abroad' => ['VACATION', 'Vacation Leave', 'VACATION_LEAVE', 'ABROAD', 'Japan', 'ABROAD', 'Japan'],
    'special privilege shares the section' => ['VACATION', 'Special Privilege Leave', 'SPECIAL_PRIVILEGE_LEAVE', 'ABROAD', 'Korea', 'ABROAD', 'Korea'],
    'in hospital' => ['SICK', 'Sick Leave', 'SICK_LEAVE', 'IN_HOSPITAL', 'Dengue', 'IN_HOSPITAL', 'Dengue'],
    'out patient' => ['SICK', 'Sick Leave', 'SICK_LEAVE', 'OUT_PATIENT', 'Flu', 'OUT_PATIENT', 'Flu'],
    'special leave benefits for women' => ['WOMEN', 'Special Leave Benefits for Women', 'SPECIAL_LEAVE_BENEFITS_FOR_WOMEN', '', 'Myoma', 'ILLNESS', 'Myoma'],
    'master\'s degree' => ['STUDY', 'Study Leave', 'STUDY_LEAVE', 'MASTERS', '', 'MASTERS', null],
    'BAR/Board review' => ['STUDY', 'Study Leave', 'STUDY_LEAVE', 'BAR_BOARD', '', 'BAR_BOARD', null],
]);

it('refuses a detail of leave left unanswered, keeping the draft', function (string $section, string $name, string $token, string $detail) {
    lformType($name, $section);
    $mine = lformEmployee('EMP-24');
    lformActor(['PORTAL'], employeeId: $mine->getKey());

    lformPage()
        ->set('applyOpen', true)->set('type', $token)->set('leaveDetail', $detail)
        ->set('start', '2026-11-17')->set('end', '2026-11-17')
        ->call('file')
        ->assertSet('applyOpen', true)
        ->assertSet('type', $token);

    expect(LeaveTravelRequest::query()->count())->toBe(0);
})->with([
    'vacation, nothing picked' => ['VACATION', 'Vacation Leave', 'VACATION_LEAVE', ''],
    'abroad without where' => ['VACATION', 'Vacation Leave', 'VACATION_LEAVE', 'ABROAD'],
    'sick, nothing picked' => ['SICK', 'Sick Leave', 'SICK_LEAVE', ''],
    'in hospital without the illness' => ['SICK', 'Sick Leave', 'SICK_LEAVE', 'IN_HOSPITAL'],
    'out patient without the illness' => ['SICK', 'Sick Leave', 'SICK_LEAVE', 'OUT_PATIENT'],
    'women without the illness' => ['WOMEN', 'Special Leave Benefits for Women', 'SPECIAL_LEAVE_BENEFITS_FOR_WOMEN', ''],
    'study, nothing picked' => ['STUDY', 'Study Leave', 'STUDY_LEAVE', ''],
]);

it('skips the weekend in the working days, and counts every day for a calendar type', function (string $count, float $days) {
    $type = lformType('Vacation Leave');
    $type->forceFill(['day_count' => $count])->save();
    $mine = lformEmployee('EMP-25');
    lformCredit($mine, 'VACATION_LEAVE', 15);
    lformActor(['PORTAL'], employeeId: $mine->getKey());

    $page = lformPage()->set('type', 'VACATION_LEAVE')->set('start', '2026-11-20')->set('end', '2026-11-23');

    expect($page->viewData('creditHint')['needs'])->toBe($days);
})->with([
    'working days' => ['WORKING', 2.0],
    'calendar days' => ['CALENDAR', 4.0],
]);

it('keeps the day type, which half and the reason', function () {
    lformType('Vacation Leave');
    $mine = lformEmployee('EMP-26');
    lformCredit($mine, 'VACATION_LEAVE', 15);
    lformActor(['PORTAL'], employeeId: $mine->getKey());

    $page = lformPage()
        ->set('type', 'VACATION_LEAVE')->set('start', '2026-11-17')->set('end', '2026-11-17')
        ->set('dayType', 'HALF_DAY')->set('half', 'AM')->set('reason', 'Family errand');

    expect($page->viewData('creditHint')['needs'])->toBe(0.5);
    $page->assertSeeHtml('data-working-days>0.5</dd>')->call('file');

    expect(LeaveTravelRequest::query()->sole()->only(['day_type', 'half', 'reason', 'start_date', 'end_date']))
        ->toMatchArray(['day_type' => 'HALF_DAY', 'half' => 'AM', 'reason' => 'Family errand']);
});

it('says when the dates are out of order and turns Submit off', function () {
    lformType('Vacation Leave');
    $mine = lformEmployee('EMP-27');
    lformActor(['PORTAL'], employeeId: $mine->getKey());

    $page = lformPage()->set('type', 'VACATION_LEAVE')->set('start', '2026-11-20')->set('end', '2026-11-17');

    expect($page->viewData('dateError'))->not->toBeNull()
        ->and($page->viewData('submitDisabled'))->toBeTrue();
    $page->assertSeeHtml('data-leave-consequences');
});

it('records commutation as not requested unless asked, and refuses an unknown value', function () {
    lformType('Vacation Leave');
    $mine = lformEmployee('EMP-28');
    lformActor(['PORTAL'], employeeId: $mine->getKey());

    lformPage()->set('type', 'VACATION_LEAVE')->set('start', '2026-11-17')->set('end', '2026-11-17')
        ->set('commutation', 'MAYBE')->call('file')->assertSet('type', 'VACATION_LEAVE')
        ->set('commutation', 'NOT_REQUESTED')->call('file')->assertSet('type', '');

    expect(LeaveTravelRequest::query()->sole()->commutation)->toBe('NOT_REQUESTED');
});

it('offers Convert credits to cash only while a credit can be converted', function () {
    $type = lformType('Vacation Leave');
    $mine = lformEmployee('EMP-29');
    lformCredit($mine, 'VACATION_LEAVE', 15);
    lformActor(['PORTAL'], employeeId: $mine->getKey());

    lformPage()->assertDontSeeHtml('data-apply-convert')->assertSee('None of your credits can be converted.');

    $type->forceFill(['can_cash_out' => true])->save();
    Once::flush();

    lformPage()->assertSeeHtml('data-apply-convert')->assertDontSee('None of your credits can be converted.');
});

it('states an hours type in hours and a yearly type by the days left', function () {
    $hours = lformType('Compensatory Time');
    $hours->forceFill(['unit' => 'HOURS'])->save();
    $yearly = lformType('Special Privilege Leave');
    $yearly->forceFill(['filing' => 'YEARLY', 'allowance_days' => 3])->save();
    $mine = lformEmployee('EMP-30');
    lformCredit($mine, 'COMPENSATORY_TIME', 8);
    lformActor(['PORTAL'], employeeId: $mine->getKey());

    $page = lformPage()->set('type', 'SPECIAL_PRIVILEGE_LEAVE')->set('start', '2026-11-17')->set('end', '2026-11-17');

    expect($page->viewData('certification'))->toBe([
        ['name' => 'Special Privilege Leave', 'unit' => 'DAYS', 'earned' => 3.0, 'less' => 1.0, 'balance' => 2.0],
        ['name' => 'Compensatory Time', 'unit' => 'HOURS', 'earned' => 8.0, 'less' => 0.0, 'balance' => 8.0],
    ]);
    $page->assertSee('Compensatory Time (hours)');
});

it('draws from the fallback type once the picked type runs out', function () {
    lformType('Sick Leave');
    lformType('Vacation Leave');
    $mine = lformEmployee('EMP-31');
    lformCredit($mine, 'SICK_LEAVE', 1);
    lformCredit($mine, 'VACATION_LEAVE', 10);
    $rule = new LeaveRule;
    $rule->forceFill([
        'scope' => 'SICK_LEAVE', 'effective_from' => '2026-01-01', 'params' => [...LeaveTypeRule::none()->toArray(), 'fallback' => 'VACATION_LEAVE'],
        'status' => LeaveRule::APPROVED, 'prepared_by_name' => 'Preparer', 'approved_by_name' => 'Approver', 'approved_at' => CarbonImmutable::now('UTC'),
    ])->save();
    lformActor(['PORTAL'], employeeId: $mine->getKey());

    $page = lformPage()->set('type', 'SICK_LEAVE')->set('start', '2026-11-17')->set('end', '2026-11-19');

    expect($page->viewData('certification'))->toBe([
        ['name' => 'Vacation Leave', 'unit' => 'DAYS', 'earned' => 10.0, 'less' => 2.0, 'balance' => 8.0],
        ['name' => 'Sick Leave', 'unit' => 'DAYS', 'earned' => 1.0, 'less' => 1.0, 'balance' => 0.0],
    ]);
});

it('takes a below-zero draw from the picked type alone', function () {
    $type = lformType('Vacation Leave');
    $type->forceFill(['below_zero_limit' => 5])->save();
    $mine = lformEmployee('EMP-32');
    lformCredit($mine, 'VACATION_LEAVE', 1);
    lformActor(['PORTAL'], employeeId: $mine->getKey());

    $page = lformPage()->set('type', 'VACATION_LEAVE')->set('start', '2026-11-17')->set('end', '2026-11-19');

    expect($page->viewData('certification')[0])->toMatchArray(['earned' => 1.0, 'less' => 3.0, 'balance' => -2.0]);
    $page->assertSeeHtml('data-credit-below-zero');
});

it('writes nothing to section 7 on filing', function () {
    lformType('Vacation Leave');
    $mine = lformEmployee('EMP-33');
    lformActor(['PORTAL'], employeeId: $mine->getKey());

    lformPage()->set('type', 'VACATION_LEAVE')->set('start', '2026-11-17')->set('end', '2026-11-17')->call('file');

    expect(LeaveTravelRequest::query()->sole()->only(['status', 'recommendation', 'is_paid', 'disapproval_reason', 'reviewed_by']))
        ->toBe(['status' => 'PENDING', 'recommendation' => null, 'is_paid' => null, 'disapproval_reason' => null, 'reviewed_by' => null]);
});
