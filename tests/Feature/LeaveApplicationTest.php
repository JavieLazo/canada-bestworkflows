<?php

declare(strict_types=1);

use App\Livewire\Leaves\Index;
use App\Livewire\Portal\PortalPage;
use App\Livewire\Records\LeaveCreditSettingsPage;
use App\Models\AuditLog;
use App\Models\EmployeeProfile;
use App\Models\LeaveCashOut;
use App\Models\LeaveCredit;
use App\Models\LeaveTravelRequest;
use App\Models\LeaveType;
use App\Models\Notification;
use App\Models\Role;
use App\Models\RoleModuleGrant;
use App\Models\User;
use App\Modules\Timekeeping\Application\Commands\FileAbsence;
use App\Modules\Timekeeping\Application\Commands\RecommendAbsence;
use App\Modules\Timekeeping\Application\Commands\RequestLeaveCashOut;
use App\Modules\Timekeeping\Application\Commands\ReviewAbsence;
use App\Modules\Timekeeping\Application\Commands\SaveLeaveTypeCredits;
use App\Modules\Timekeeping\Infrastructure\AccountReferences;
use App\Support\Access;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Once;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Support\LeaveRuleFixture;
use Tests\Support\ScheduleFixture;

/**
 * The leave application's remaining parts. DETAILS OF LEAVE: each leave
 * type says which details a request asks (`leave_types.leave_details`, set
 * on Leave Settings; existing types start from their names), and
 * FileAbsence requires that choice, and the text it says to specify, on a
 * leave of that type — from My Portal and from HR's "File for an
 * employee" alike. COMMUTATION is recorded on every leave, "not requested"
 * unless said. Official business carries neither, and neither changes pay.
 * RECOMMENDATION: an all-data LEAVES holder records "For approval" or "For
 * disapproval due to …" on a pending request (RecommendAbsence); the
 * request stays pending and may be decided either way. A CASH CONVERSION
 * says its purpose: an employee's own request is a monetization of leave
 * credits (terminal leave is HR's, CashConversionRulesTest).
 */

/** @param  list<string>  $modules */
function lappRole(array $modules, bool $allData): Role
{
    $role = new Role;
    $role->forceFill(['name' => 'Application '.Str::random(6), 'all_data' => $allData, 'is_system' => false])->save();
    foreach ($modules as $module) {
        $grant = new RoleModuleGrant;
        $grant->forceFill(['role_id' => $role->getKey(), 'module' => $module])->save();
    }

    return $role;
}

/** @param  list<string>  $modules */
function lappActor(array $modules, bool $allData = false, ?string $employeeId = null): User
{
    $user = User::factory()->create(['role_id' => lappRole($modules, $allData)->getKey(), 'employee_id' => $employeeId]);
    test()->actingAs($user);
    app()->forgetInstance(Access::class);

    return $user;
}

function lappEmployee(string $code = 'EMP-001', string $first = 'Liza', string $last = 'Domingo'): EmployeeProfile
{
    $employee = new EmployeeProfile;
    $employee->forceFill([
        'employee_code' => $code, 'first_name' => $first, 'last_name' => $last,
        'employment_status' => 'Regular', 'date_hired' => '2024-01-01', 'is_active' => true,
    ])->save();

    ScheduleFixture::standard($employee);

    return $employee;
}

function lappType(string $name, string $details = 'NONE'): LeaveType
{
    $type = new LeaveType;
    $type->forceFill(['code' => 'LA-'.Str::random(6), 'name' => $name, 'leave_details' => $details])->save();

    return $type;
}

/** @param  array<string, mixed>  $overrides */
function lappRequest(EmployeeProfile $employee, string $code, array $overrides = []): LeaveTravelRequest
{
    $request = new LeaveTravelRequest;
    $request->forceFill(array_merge([
        'code' => $code, 'employee_id' => $employee->getKey(), 'request_type' => 'SICK_LEAVE', 'day_type' => 'WHOLE_DAY',
        'start_date' => '2026-11-16', 'end_date' => '2026-11-16', 'reason' => 'Check-up', 'status' => 'PENDING',
    ], $overrides))->save();

    return $request;
}

/** The employee's own portal sign-in. */
function lappSelf(EmployeeProfile $employee): Access
{
    return Access::forActor(lappActor(['PORTAL'], employeeId: $employee->getKey()));
}

/**
 * Runs `$run` as the host stands before `$file` has run: its columns
 * dropped FIRST, before anything is seeded, then restored through the
 * migration's own up(). On MariaDB the DDL commits RefreshDatabase's
 * transaction, so one is reopened around the run and again for the
 * rollback at the end (the testing guide).
 */
function lappWithoutColumns(string $file, Closure $run): void
{
    $engine = in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true);
    $migration = require base_path("database/migrations/{$file}");
    $migration->down();
    Once::flush();
    if ($engine) {
        DB::connection()->getPdo()->beginTransaction();
    }

    try {
        $run();
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
}

/*
|--------------------------------------------------------------------------
| Details of leave and commutation, filed
|--------------------------------------------------------------------------
*/

it('requires the details a vacation leave asks, and keeps them with the commutation', function () {
    lappType('Vacation Leave', 'VACATION');
    $employee = lappEmployee();
    $self = lappSelf($employee);

    expect(fn () => app(FileAbsence::class)->handle($self, 'VACATION_LEAVE', 'WHOLE_DAY', '2026-11-16', '2026-11-17', ''))
        ->toThrow(RuntimeException::class, 'Say where the leave will be spent: within the Philippines or abroad.');
    expect(fn () => app(FileAbsence::class)->handle($self, 'VACATION_LEAVE', 'WHOLE_DAY', '2026-11-16', '2026-11-17', '', null, 'ABROAD', ' '))
        ->toThrow(RuntimeException::class, 'Specify where abroad the leave will be spent.');
    expect(fn () => app(FileAbsence::class)->handle($self, 'VACATION_LEAVE', 'WHOLE_DAY', '2026-11-16', '2026-11-17', '', null, 'ABROAD', 'Japan', 'MAYBE'))
        ->toThrow(RuntimeException::class, 'Choose whether commutation is requested.');
    expect(LeaveTravelRequest::query()->count())->toBe(0)
        ->and(AuditLog::query()->where('action', 'REQUEST_FILED')->exists())->toBeFalse();

    app(FileAbsence::class)->handle($self, 'VACATION_LEAVE', 'WHOLE_DAY', '2026-11-16', '2026-11-17', '', null, 'ABROAD', '  Japan ', 'REQUESTED');

    $row = LeaveTravelRequest::query()->sole();
    expect($row->leave_detail)->toBe('ABROAD')
        ->and($row->leave_detail_note)->toBe('Japan')
        ->and($row->commutation)->toBe('REQUESTED')
        ->and($row->status)->toBe('PENDING');
});

it('keeps an illness on the request alone, never in the audit sentence or the notice', function () {
    lappType('Sick Leave', 'SICK');
    $employee = lappEmployee();
    lappActor(['LEAVES'], allData: true);
    $self = lappSelf($employee);

    expect(fn () => app(FileAbsence::class)->handle($self, 'SICK_LEAVE', 'WHOLE_DAY', '2026-11-16', '2026-11-16', '', null, 'IN_HOSPITAL', ''))
        ->toThrow(RuntimeException::class, 'Specify the illness.');

    app(FileAbsence::class)->handle($self, 'SICK_LEAVE', 'WHOLE_DAY', '2026-11-16', '2026-11-16', '', null, 'IN_HOSPITAL', 'Dengue');

    $row = LeaveTravelRequest::query()->sole();
    expect($row->leave_detail)->toBe('IN_HOSPITAL')
        ->and($row->leave_detail_note)->toBe('Dengue')
        ->and($row->commutation)->toBe('NOT_REQUESTED')
        ->and(AuditLog::query()->where('action', 'REQUEST_FILED')->sole()->details)->not->toContain('Dengue')
        ->and(Notification::query()->where('kind', 'LEAVE_FILED')->sole()->body)->not->toContain('Dengue');
});

it('asks only the illness for special leave benefits for women, and only the purpose for study leave', function () {
    lappType('Special Leave Benefits for Women', 'WOMEN');
    lappType('Study Leave', 'STUDY');
    $employee = lappEmployee();
    $self = lappSelf($employee);

    app(FileAbsence::class)->handle($self, 'SPECIAL_LEAVE_BENEFITS_FOR_WOMEN', 'WHOLE_DAY', '2026-11-16', '2026-11-16', '', null, null, 'Myoma');
    app(FileAbsence::class)->handle($self, 'STUDY_LEAVE', 'WHOLE_DAY', '2026-11-23', '2026-11-23', '', null, 'BAR_BOARD', 'not kept');

    $women = LeaveTravelRequest::query()->where('request_type', 'SPECIAL_LEAVE_BENEFITS_FOR_WOMEN')->sole();
    $study = LeaveTravelRequest::query()->where('request_type', 'STUDY_LEAVE')->sole();
    expect([$women->leave_detail, $women->leave_detail_note])->toBe(['ILLNESS', 'Myoma'])
        ->and([$study->leave_detail, $study->leave_detail_note])->toBe(['BAR_BOARD', null]);
});

it('keeps no details for a type that asks none, and nothing at all on official business', function () {
    lappType('Emergency Leave');
    $employee = lappEmployee();
    $self = lappSelf($employee);

    app(FileAbsence::class)->handle($self, 'EMERGENCY_LEAVE', 'WHOLE_DAY', '2026-11-16', '2026-11-16', '', null, 'ABROAD', 'Japan');
    app(FileAbsence::class)->handle($self, 'BUSINESS_TRAVEL', 'WHOLE_DAY', '2026-11-23', '2026-11-23', '', null, 'ABROAD', 'Japan', 'REQUESTED');

    $leave = LeaveTravelRequest::query()->where('request_type', 'EMERGENCY_LEAVE')->sole();
    $travel = LeaveTravelRequest::query()->where('request_type', 'BUSINESS_TRAVEL')->sole();
    expect([$leave->leave_detail, $leave->leave_detail_note, $leave->commutation])->toBe([null, null, 'NOT_REQUESTED'])
        ->and([$travel->leave_detail, $travel->leave_detail_note, $travel->commutation])->toBe([null, null, null]);
});

it('asks the same details when HR files for an employee', function () {
    lappType('Vacation Leave', 'VACATION');
    $employee = lappEmployee();
    $manager = Access::forActor(lappActor(['LEAVES'], allData: true));

    expect(fn () => app(FileAbsence::class)->forEmployee($manager, $employee->id, 'VACATION_LEAVE', 'WHOLE_DAY', '2026-11-16', '2026-11-16', ''))
        ->toThrow(RuntimeException::class, 'Say where the leave will be spent');

    app(FileAbsence::class)->forEmployee($manager, $employee->id, 'VACATION_LEAVE', 'WHOLE_DAY', '2026-11-16', '2026-11-16', '', null, 'WITHIN_PH', null, 'REQUESTED');

    expect(LeaveTravelRequest::query()->sole()->only(['leave_detail', 'leave_detail_note', 'commutation']))
        ->toBe(['leave_detail' => 'WITHIN_PH', 'leave_detail_note' => null, 'commutation' => 'REQUESTED']);
});

it('files as before, asking nothing, until the request columns exist', function () {
    lappWithoutColumns('2026_11_08_0003_add_leave_details_to_leave_travel_requests_table.php', function (): void {
        lappType('Vacation Leave', 'VACATION');
        $employee = lappEmployee();
        $self = lappSelf($employee);

        app(FileAbsence::class)->handle($self, 'VACATION_LEAVE', 'WHOLE_DAY', '2026-11-16', '2026-11-16', '');

        expect(LeaveTravelRequest::query()->where('employee_id', $employee->id)->count())->toBe(1);
        Livewire::test(PortalPage::class)->set('tab', 'requests')->set('type', 'VACATION_LEAVE')
            ->assertDontSeeHtml('data-leave-details')
            ->assertDontSeeHtml('data-commutation');
    });
});

/*
|--------------------------------------------------------------------------
| The filing forms
|--------------------------------------------------------------------------
*/

it("draws the picked type's details in My Portal, files them and lists them under the request", function () {
    lappType('Sick Leave', 'SICK');
    lappType('Vacation Leave', 'VACATION');
    $employee = lappEmployee();
    lappSelf($employee);

    $page = Livewire::test(PortalPage::class)->set('tab', 'requests')
        ->assertDontSeeHtml('data-leave-details')
        ->set('type', 'SICK_LEAVE')
        ->assertSeeHtml('data-leave-details="SICK"')
        ->assertSee('In case of Sick Leave')
        ->assertSeeHtml('data-commutation')
        ->assertDontSeeHtml('data-leave-detail-note')
        ->set('leaveDetail', 'OUT_PATIENT')
        ->assertSeeHtml('data-leave-detail-note')
        ->assertSee('Specify illness');

    // Another type asks other details: the ones picked go.
    $page->set('type', 'VACATION_LEAVE')
        ->assertSet('leaveDetail', '')
        ->assertSeeHtml('data-leave-details="VACATION"')
        ->set('type', 'SICK_LEAVE')
        ->set('leaveDetail', 'OUT_PATIENT')->set('leaveDetailNote', 'Flu')->set('commutation', 'REQUESTED')
        ->set('start', '2026-11-16')->set('end', '2026-11-16')
        ->call('file')
        ->assertSet('leaveDetail', '')
        ->assertSet('leaveDetailNote', '')
        ->assertSet('commutation', 'NOT_REQUESTED')
        ->assertSeeHtml('data-request-details')
        ->assertSee('Out Patient: Flu · Commutation requested');

    expect(LeaveTravelRequest::query()->sole()->only(['leave_detail', 'leave_detail_note', 'commutation']))
        ->toBe(['leave_detail' => 'OUT_PATIENT', 'leave_detail_note' => 'Flu', 'commutation' => 'REQUESTED']);
});

it("draws the type's details in HR's filing dialog and shows them in the review dialog", function () {
    lappType('Vacation Leave', 'VACATION');
    $employee = lappEmployee();
    lappActor(['LEAVES'], allData: true);

    $page = Livewire::test(Index::class)
        ->call('openFile')
        ->call('employeePicked', 'file-employee', $employee->id)
        ->set('fileForm.type', 'VACATION_LEAVE')
        ->assertSeeHtml('data-leave-details="VACATION"')
        ->set('fileForm.leave_detail', 'ABROAD')
        ->set('fileForm.leave_detail_note', 'Singapore')
        ->set('fileForm.start', '2026-11-16')->set('fileForm.end', '2026-11-16')
        ->call('fileForEmployee')
        ->assertSet('fileOpen', false);

    $row = LeaveTravelRequest::query()->sole();
    expect([$row->leave_detail, $row->leave_detail_note, $row->commutation])->toBe(['ABROAD', 'Singapore', 'NOT_REQUESTED']);

    $page->call('show', $row->id)
        ->assertSeeHtml('data-leave-detail>Abroad: Singapore</dd>')
        ->assertSeeHtml('data-commutation-value>Not requested</dd>');
});

/*
|--------------------------------------------------------------------------
| The leave type's setting
|--------------------------------------------------------------------------
*/

it('sets which details a leave type asks on Leave Settings, and refuses an unknown one', function () {
    $type = lappType('Field Leave');
    $manager = Access::forActor(lappActor(['RECORDS', 'LEAVES'], allData: true));
    $settings = ['earns_credits' => false, 'unit' => 'DAYS', 'charge_late' => false, 'charge_absence' => false, 'charge_order' => '0', 'below_zero_limit' => '0'];

    expect(fn () => app(SaveLeaveTypeCredits::class)->handle($manager, $type->id, [...$settings, 'leave_details' => 'ELSEWHERE']))
        ->toThrow(RuntimeException::class, 'Choose which details of leave a request asks for.');
    expect($type->fresh()->leave_details)->toBe('NONE');

    app(SaveLeaveTypeCredits::class)->handle($manager, $type->id, [...$settings, 'leave_details' => 'SICK']);

    expect($type->fresh()->leave_details)->toBe('SICK')
        ->and(AuditLog::query()->where('action', 'LEAVE_TYPE_CREDITS_SET')->sole()->details)->toContain('asks Sick Leave details');

    Livewire::test(LeaveCreditSettingsPage::class)
        ->assertSeeHtml('data-leave-details-asked')
        ->call('openType', $type->id)
        ->assertSet('typeForm.leave_details', 'SICK')
        ->assertSeeHtml('data-leave-details-setting')
        ->set('typeForm.leave_details', 'STUDY')
        ->call('saveType');
    expect($type->fresh()->leave_details)->toBe('STUDY');
});

it('starts the types already set up from their names, never over what HR chose, and runs twice', function () {
    $types = [];
    foreach (['Vacation Leave', 'Special Privilege Leave', 'Sick Leave', 'Special Leave Benefits for Women', 'Study Leave', 'Maternity Leave', 'Violence Against Women and Their Children Leave'] as $name) {
        $types[$name] = lappType($name);
    }
    $chosen = lappType('Sick Leave Extended', 'STUDY');
    $migration = require base_path('database/migrations/2026_11_08_0002_add_leave_details_to_leave_types_table.php');

    $migration->up();
    $migration->up();

    expect(array_map(static fn (LeaveType $type): string => (string) $type->fresh()->leave_details, $types))->toBe([
        'Vacation Leave' => 'VACATION',
        'Special Privilege Leave' => 'VACATION',
        'Sick Leave' => 'SICK',
        'Special Leave Benefits for Women' => 'WOMEN',
        'Study Leave' => 'STUDY',
        'Maternity Leave' => 'NONE',
        'Violence Against Women and Their Children Leave' => 'NONE',
    ])->and($chosen->fresh()->leave_details)->toBe('STUDY');
});

it('adds and drops the new columns twice without error', function (string $file, string $table, array $columns) {
    $migration = require base_path("database/migrations/{$file}");
    $engine = in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true);

    try {
        $migration->down();
        $migration->down();
        foreach ($columns as $column) {
            expect(Schema::hasColumn($table, $column))->toBeFalse();
        }
        $migration->up();
        $migration->up();
        foreach ($columns as $column) {
            expect(Schema::hasColumn($table, $column))->toBeTrue();
        }
    } finally {
        $migration->up();
        Once::flush();
        if ($engine) {
            DB::connection()->getPdo()->beginTransaction();
        }
    }
})->with([
    'leave types' => ['2026_11_08_0002_add_leave_details_to_leave_types_table.php', 'leave_types', ['leave_details']],
    'request details' => ['2026_11_08_0003_add_leave_details_to_leave_travel_requests_table.php', 'leave_travel_requests', ['leave_detail', 'leave_detail_note', 'commutation']],
    'recommendation' => ['2026_11_08_0004_add_recommendation_to_leave_travel_requests_table.php', 'leave_travel_requests', ['recommendation', 'recommendation_reason', 'recommended_by', 'recommended_at']],
    'cash-out purpose' => ['2026_11_08_0005_add_purpose_to_leave_cash_outs_table.php', 'leave_cash_outs', ['purpose']],
]);

/*
|--------------------------------------------------------------------------
| Recommendation
|--------------------------------------------------------------------------
*/

it('records a recommendation on a pending request and leaves it pending, telling the other leave managers', function () {
    $employee = lappEmployee();
    $request = lappRequest($employee, 'LR-001');
    $colleague = lappActor(['LEAVES'], allData: true);
    $manager = lappActor(['LEAVES'], allData: true);

    $message = app(RecommendAbsence::class)->handle(Access::forActor($manager), $request->id, 'FOR_APPROVAL', 'not kept');

    $fresh = $request->fresh();
    expect($message)->toBe('Request LR-001 recommended for approval.')
        ->and($fresh->status)->toBe('PENDING')
        ->and($fresh->recommendation)->toBe('FOR_APPROVAL')
        ->and($fresh->recommendation_reason)->toBeNull()
        ->and($fresh->recommended_by)->toBe((int) $manager->getKey())
        ->and($fresh->recommended_at)->not->toBeNull()
        ->and($fresh->reviewed_by)->toBeNull()
        ->and(AuditLog::query()->where('action', 'REQUEST_RECOMMENDED')->sole()->details)->toBe('Request LR-001 recommended for approval')
        ->and(Notification::query()->where('kind', 'LEAVE_RECOMMENDED')->where('user_id', $colleague->getKey())->sole()->title)->toBe('Request LR-001 recommended for approval')
        ->and(Notification::query()->where('kind', 'LEAVE_RECOMMENDED')->where('user_id', $manager->getKey())->exists())->toBeFalse();

    // A later recommendation replaces it; the same one again writes nothing.
    app(RecommendAbsence::class)->handle(Access::forActor($manager), $request->id, 'FOR_DISAPPROVAL', '  Short of staff that week. ');
    app(RecommendAbsence::class)->handle(Access::forActor($manager), $request->id, 'FOR_DISAPPROVAL', 'Short of staff that week.');
    expect($request->fresh()->only(['recommendation', 'recommendation_reason']))->toBe(['recommendation' => 'FOR_DISAPPROVAL', 'recommendation_reason' => 'Short of staff that week.'])
        ->and(AuditLog::query()->where('action', 'REQUEST_RECOMMENDED')->count())->toBe(2);

    // Whoever decides may go against it.
    app(ReviewAbsence::class)->handle(Access::forActor($colleague), $request->id, 'APPROVED', false);
    expect($request->fresh()->status)->toBe('APPROVED')
        ->and($request->fresh()->recommendation)->toBe('FOR_DISAPPROVAL');
});

it('refuses a recommendation without all-data leave access, without a reason for disapproval, or once decided', function (string $case) {
    $employee = lappEmployee();
    $request = lappRequest($employee, 'LR-001', $case === 'decided' ? ['status' => 'APPROVED', 'is_paid' => true] : []);
    $actor = Access::forActor(lappActor(['LEAVES'], allData: $case !== 'viewer'));
    [$recommendation, $reason, $message] = match ($case) {
        'viewer' => ['FOR_APPROVAL', null, 'Your role cannot manage organization-wide data.'],
        'no reason' => ['FOR_DISAPPROVAL', '   ', 'Say why disapproval is recommended.'],
        'too long' => ['FOR_DISAPPROVAL', str_repeat('x', 1001), 'Keep the reason to 1000 characters.'],
        'unknown' => ['MAYBE', null, 'Choose for approval or for disapproval.'],
        default => ['FOR_APPROVAL', null, 'That request has already been reviewed.'],
    };

    expect(fn () => app(RecommendAbsence::class)->handle($actor, $request->id, $recommendation, $reason))
        ->toThrow(RuntimeException::class, $message);
    expect($request->fresh()->recommendation)->toBeNull()
        ->and(AuditLog::query()->where('action', 'REQUEST_RECOMMENDED')->exists())->toBeFalse();
})->with(['viewer', 'no reason', 'too long', 'unknown', 'decided']);

it('records the recommendation from the review dialog and shows it there', function () {
    $employee = lappEmployee();
    $request = lappRequest($employee, 'LR-001');
    lappActor(['LEAVES'], allData: true);

    Livewire::test(Index::class)
        ->call('show', $request->id)
        ->assertSeeHtml('data-recommend')
        ->assertDontSeeHtml('data-recommendation-form')
        ->set('recommending', true)
        ->assertSeeHtml('data-recommendation-form')
        ->set('recommendation', 'FOR_DISAPPROVAL')
        ->assertSee('For disapproval due to')
        ->call('recommend')
        ->assertSet('recommendationError', 'Say why disapproval is recommended.')
        ->set('recommendationReason', 'Short of staff that week.')
        ->call('recommend')
        ->assertSet('recommending', false)
        ->assertSet('recommendationError', '')
        ->assertSet('detailOpen', true)
        ->assertSeeHtml('data-recommendation-text>For disapproval due to: Short of staff that week.</p>')
        ->assertSee('Change recommendation…');
    expect($request->fresh()->status)->toBe('PENDING')
        ->and($request->fresh()->recommendation)->toBe('FOR_DISAPPROVAL');

    // A viewer without all-data cannot record one through the action either.
    lappActor(['LEAVES'], allData: false, employeeId: $employee->getKey());
    Livewire::test(Index::class)
        ->call('show', $request->id)
        ->set('recommending', true)
        ->set('recommendation', 'FOR_APPROVAL')
        ->call('recommend');
    expect($request->fresh()->recommendation)->toBe('FOR_DISAPPROVAL')
        ->and(AuditLog::query()->where('action', 'REQUEST_RECOMMENDED')->count())->toBe(1);
});

it('keeps an account that recorded a recommendation', function () {
    $employee = lappEmployee();
    $request = lappRequest($employee, 'LR-001');
    $manager = lappActor(['LEAVES'], allData: true);
    $references = app(AccountReferences::class);
    expect($references->references($manager->getKey()))->toBeFalse();

    app(RecommendAbsence::class)->handle(Access::forActor($manager), $request->id, 'FOR_APPROVAL');

    expect($references->references($manager->getKey()))->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| Cash conversion purpose
|--------------------------------------------------------------------------
*/

it('keeps an employee\'s own cash conversion as a monetization of leave credits, and shows it', function () {
    $this->travelTo(new DateTimeImmutable('2026-09-10T10:00:00+08:00'));
    $employee = lappEmployee();
    $manager = Access::forActor(lappActor(['LEAVES', 'RECORDS'], allData: true));
    $type = lappType('Vacation Leave');
    app(SaveLeaveTypeCredits::class)->handle($manager, $type->id, ['earns_credits' => true, 'unit' => 'DAYS', 'charge_late' => false, 'charge_absence' => false, 'charge_order' => '0', 'below_zero_limit' => '0', 'can_cash_out' => true]);
    $credit = new LeaveCredit;
    $credit->forceFill(['employee_id' => $employee->id, 'year' => 2026, 'request_type' => 'VACATION_LEAVE', 'credits' => 10.0])->save();
    LeaveRuleFixture::approved('2026-01-01', ['earning' => []]);
    $self = lappSelf($employee);

    app(RequestLeaveCashOut::class)->handle($self, 'VACATION_LEAVE', '1', '');
    expect(LeaveCashOut::query()->sole()->purpose)->toBe('MONETIZATION');
    LeaveCashOut::query()->delete();

    Livewire::test(PortalPage::class)->set('tab', 'requests')
        ->set('cashOutOpen', true)
        ->assertDontSeeHtml('data-cash-out-purpose-field')
        ->set('cashOutType', 'VACATION_LEAVE')->set('cashOutDays', '2')
        ->call('requestCashOut')
        ->assertSeeHtml('data-cash-out-purpose>Monetization of Leave Credits</span>');
    expect(LeaveCashOut::query()->sole()->purpose)->toBe('MONETIZATION');
});
