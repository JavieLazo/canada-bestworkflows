<?php

declare(strict_types=1);

use App\Livewire\Leaves\Index;
use App\Livewire\Portal\PortalPage;
use App\Models\EmployeeProfile;
use App\Models\LeaveCredit;
use App\Models\LeaveTravelRequest;
use App\Models\LeaveType;
use App\Models\OrgNode;
use App\Models\Position;
use App\Models\Role;
use App\Models\RoleModuleGrant;
use App\Models\User;
use App\Modules\Timekeeping\Application\Commands\CancelAbsence;
use App\Modules\Timekeeping\Application\Commands\ReviewAbsence;
use App\Modules\Timekeeping\Application\Commands\SaveLeaveSignatory;
use App\Modules\Timekeeping\Application\LeaveFormFreeze;
use App\Modules\Timekeeping\Application\Queries\AbsenceRecords;
use App\Modules\Workforce\Contracts\EmployeeLeaveFacts;
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
 * AN APPROVED APPLICATION FOR LEAVE IS A RECORD. What its form states is
 * stored in the approving transaction, and nothing changed afterwards reaches
 * it: not the employee's record, the head of office, the signatories, the
 * leave types or the credits. A leave not approved still reads as the records
 * stand. Storing the salary lets no one new read it.
 */

/** @param  list<string>  $modules */
function alfActor(array $modules, bool $allData = false, ?string $employeeId = null): User
{
    $role = new Role;
    $role->forceFill(['name' => 'Alf '.Str::random(6), 'all_data' => $allData, 'is_system' => false])->save();
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
function alfEmployee(string $code, array $overrides = []): EmployeeProfile
{
    $employee = new EmployeeProfile;
    $employee->forceFill(array_merge([
        'employee_code' => $code, 'first_name' => 'Liza', 'last_name' => 'Domingo', 'position' => 'Accountant',
        'monthly_rate' => 35000, 'employment_status' => 'Regular', 'date_hired' => '2024-01-01', 'is_active' => true,
    ], $overrides))->save();

    ScheduleFixture::standard($employee);

    return $employee;
}

/** @return array{type: LeaveType, node: OrgNode, employee: EmployeeProfile, request: LeaveTravelRequest} */
function alfFiled(): array
{
    $type = new LeaveType;
    $type->forceFill(['code' => 'ALF-'.Str::random(6), 'name' => 'Vacation Leave', 'leave_details' => 'NONE'])->save();
    $node = new OrgNode;
    $node->forceFill(['code' => '1', 'kind' => OrgNode::KIND_DEPARTMENT, 'name' => 'Finance', 'parent_id' => null, 'sort_order' => 0, 'head_name' => 'Juan Cruz', 'head_designation' => 'Finance Manager'])->save();
    $position = new Position;
    $position->forceFill(['code' => 'P1', 'name' => 'Accountant', 'org_node_id' => $node->getKey(), 'sort_order' => 0])->save();
    $employee = alfEmployee('EMP-1', ['position_id' => $position->getKey()]);
    $credit = new LeaveCredit;
    $credit->forceFill(['employee_id' => $employee->getKey(), 'year' => 2026, 'request_type' => 'VACATION_LEAVE', 'credits' => 15])->save();
    $request = new LeaveTravelRequest;
    $request->forceFill([
        'code' => 'LR-1', 'employee_id' => $employee->getKey(), 'request_type' => 'VACATION_LEAVE', 'day_type' => 'WHOLE_DAY',
        'start_date' => '2026-11-17', 'end_date' => '2026-11-18', 'reason' => 'Family trip', 'status' => 'PENDING',
    ])->save();

    return ['type' => $type, 'node' => $node, 'employee' => $employee, 'request' => $request];
}

beforeEach(fn () => CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-16 09:00:00', LocalClock::zone())));
afterEach(fn () => CarbonImmutable::setTestNow());

it('keeps an approved application exactly as it was approved, whatever changes later', function () {
    ['type' => $type, 'node' => $node, 'employee' => $employee, 'request' => $request] = alfFiled();
    $access = Access::forActor(alfActor(['LEAVES'], allData: true));
    $first = alfEmployee('EMP-2', ['first_name' => 'Ana', 'last_name' => 'Reyes', 'position' => 'HR Head']);
    $second = alfEmployee('EMP-3', ['first_name' => 'Ben', 'last_name' => 'Tan', 'position' => 'New Head']);
    app(SaveLeaveSignatory::class)->handle($access, 'CERTIFIED', $first->getKey());
    app(ReviewAbsence::class)->handle($access, $request->id, 'APPROVED', true);

    app(SaveLeaveSignatory::class)->handle($access, 'CERTIFIED', $second->getKey());
    app(SaveLeaveSignatory::class)->handle($access, 'APPROVED', $second->getKey());
    $first->forceFill(['last_name' => 'Santos', 'position' => 'Retired'])->save();
    $employee->forceFill(['last_name' => 'Changed', 'position' => 'Manager', 'monthly_rate' => 50000])->save();
    $node->forceFill(['name' => 'Renamed Office', 'head_name' => 'Someone Else'])->save();
    $type->forceFill(['name' => 'Renamed Leave'])->save();
    LeaveCredit::query()->update(['credits' => 99]);

    alfActor(['PORTAL'], employeeId: $employee->getKey());
    $page = Livewire::test(PortalPage::class)->set('tab', 'requests')->call('viewFiled', $request->id)
        ->assertSeeHtml('data-officer-certified>Ana Reyes</dd>')
        ->assertSee('HR Head')
        ->assertSeeHtml('data-officer-approved>—</dd>')
        ->assertSeeHtml('data-applicant-last>Domingo</dd>')
        ->assertSeeHtml('data-applicant-position>Accountant</dd>')
        ->assertSeeHtml('data-applicant-office>Finance</dd>')
        ->assertSeeHtml('data-office-head>Juan Cruz</dd>')
        ->assertSeeHtml('data-applicant-salary>$35,000.00</dd>')
        ->assertSeeHtml('data-filed-with-pay>2</dd>');

    $form = $page->viewData('filedForm');
    expect($form['certification'][0])->toMatchArray(['earned' => 15.0, 'less' => 2.0, 'balance' => 13.0])
        ->and(array_column($form['types'], 'label'))->toBe(['Vacation Leave']);
});

it('shows Leave Management the same stored form', function () {
    ['employee' => $employee, 'request' => $request] = alfFiled();
    $access = Access::forActor(alfActor(['LEAVES'], allData: true));
    app(ReviewAbsence::class)->handle($access, $request->id, 'APPROVED', true);
    $employee->forceFill(['last_name' => 'Changed'])->save();
    app(SaveLeaveSignatory::class)->handle($access, 'APPROVED', null);

    Livewire::test(Index::class)->call('show', $request->id)->call('viewApplication')
        ->assertSeeHtml('data-applicant-last>Domingo</dd>')
        ->assertSeeHtml('data-officer-approved>—</dd>');
});

it('keeps following the records until the leave is approved', function () {
    ['employee' => $employee, 'request' => $request] = alfFiled();
    $access = Access::forActor(alfActor(['LEAVES'], allData: true));
    $ana = alfEmployee('EMP-2', ['first_name' => 'Ana', 'last_name' => 'Reyes']);
    app(SaveLeaveSignatory::class)->handle($access, 'CERTIFIED', $ana->getKey());
    $employee->forceFill(['last_name' => 'Changed'])->save();

    Livewire::test(Index::class)->call('show', $request->id)->call('viewApplication')
        ->assertSeeHtml('data-applicant-last>Changed</dd>')
        ->assertSeeHtml('data-officer-certified>Ana Reyes</dd>');

    expect($request->refresh()->approved_form)->toBeNull();
});

it('freezes nothing on a disapproval', function () {
    ['request' => $request] = alfFiled();
    app(ReviewAbsence::class)->handle(Access::forActor(alfActor(['LEAVES'], allData: true)), $request->id, 'REJECTED', null, 'Peak season');

    expect($request->refresh()->approved_form)->toBeNull();
});

it('freezes official business too', function () {
    ['request' => $request] = alfFiled();
    $request->forceFill(['request_type' => 'BUSINESS_TRAVEL'])->save();
    app(ReviewAbsence::class)->handle(Access::forActor(alfActor(['LEAVES'], allData: true)), $request->id, 'APPROVED');

    expect($request->refresh()->approved_form)->toHaveKey('officers');
});

it('never writes over a stored form', function () {
    ['request' => $request] = alfFiled();
    $access = Access::forActor(alfActor(['LEAVES'], allData: true));
    app(ReviewAbsence::class)->handle($access, $request->id, 'APPROVED', true);
    $stored = $request->refresh()->approved_form;
    app(SaveLeaveSignatory::class)->handle($access, 'APPROVED', null);

    app(LeaveFormFreeze::class)->capture($access, $request->id);

    expect($request->refresh()->approved_form)->toBe($stored);
});

it('leaves the stored form as approved when a later date is cancelled', function () {
    ['employee' => $employee, 'request' => $request] = alfFiled();
    $access = Access::forActor(alfActor(['LEAVES'], allData: true));
    app(ReviewAbsence::class)->handle($access, $request->id, 'APPROVED', true);
    app(CancelAbsence::class)->handle($access, $request->id, ['2026-11-18'], 'Plans changed');

    alfActor(['PORTAL'], employeeId: $employee->getKey());
    Livewire::test(PortalPage::class)->set('tab', 'requests')->call('viewFiled', $request->id)
        ->assertSeeHtml('data-filed-with-pay>2</dd>')
        ->assertSeeHtml('data-working-days>2</dd>');
});

it('undoes the approval when the form cannot be stored', function () {
    ['request' => $request] = alfFiled();
    $access = Access::forActor(alfActor(['LEAVES'], allData: true));
    app()->instance(EmployeeLeaveFacts::class, new class(app(EmployeeLeaveFacts::class)) implements EmployeeLeaveFacts
    {
        public function __construct(private readonly EmployeeLeaveFacts $real) {}

        public function forPosting(?array $ids = null): array
        {
            return $this->real->forPosting($ids);
        }

        public function statusTimelines(array $ids): array
        {
            return $this->real->statusTimelines($ids);
        }

        public function signatories(array $ids): array
        {
            return $this->real->signatories($ids);
        }

        public function applicant(string $employeeId): ?array
        {
            throw new RuntimeException('The record could not be read.');
        }
    });

    expect(fn () => app(ReviewAbsence::class)->handle($access, $request->id, 'APPROVED', true))->toThrow(RuntimeException::class);

    $request->refresh();
    expect($request->status)->toBe('PENDING')
        ->and($request->approved_form)->toBeNull()
        ->and($request->credit_days)->toBeNull();
});

it('hides the stored salary from a leave manager who cannot open the employee\'s record', function () {
    ['request' => $request] = alfFiled();
    app(ReviewAbsence::class)->handle(Access::forActor(alfActor(['LEAVES'], allData: true)), $request->id, 'APPROVED', true);

    Livewire::test(Index::class)->call('show', $request->id)->call('viewApplication')
        ->assertSeeHtml("data-applicant-salary>\u{2014}</dd>")
        ->assertDontSee('$35,000.00');
});

it('shows the stored salary to a leave manager who also holds the employee\'s record', function (string $module) {
    ['request' => $request] = alfFiled();
    app(ReviewAbsence::class)->handle(Access::forActor(alfActor(['LEAVES', $module], allData: true)), $request->id, 'APPROVED', true);

    Livewire::test(Index::class)->call('show', $request->id)->call('viewApplication')
        ->assertSeeHtml('data-applicant-salary>$35,000.00</dd>');
})->with(['EMPLOYEES', 'PAYROLL']);

it('stores the salary even when the approver may not read it', function () {
    ['request' => $request] = alfFiled();
    app(ReviewAbsence::class)->handle(Access::forActor(alfActor(['LEAVES'], allData: true)), $request->id, 'APPROVED', true);

    expect($request->refresh()->approved_form['applicant']['monthly_rate'])->toEqual(35000.0);
});

it('keeps the stored form out of the request lists', function () {
    ['request' => $request] = alfFiled();
    $access = Access::forActor(alfActor(['LEAVES'], allData: true));
    app(ReviewAbsence::class)->handle($access, $request->id, 'APPROVED', true);

    $view = app(AbsenceRecords::class)->list($access)->firstWhere('id', $request->id);

    expect(isset($view->approved_form))->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| Upgrading
|--------------------------------------------------------------------------
*/

it('adds the column once', function () {
    $migration = require base_path('database/migrations/2026_11_11_0006_add_approved_form_to_leave_travel_requests_table.php');
    $migration->up();
    $migration->up();

    expect(Schema::hasColumn('leave_travel_requests', 'approved_form'))->toBeTrue();
});

it('freezes the leaves already approved, once, and leaves the rest alone', function () {
    ['request' => $approved, 'employee' => $employee] = alfFiled();
    $access = Access::forActor(alfActor(['LEAVES'], allData: true));
    DB::table('leave_travel_requests')->where('id', $approved->id)->update(['status' => 'APPROVED', 'is_paid' => true, 'credit_days' => 2, 'updated_at' => '2026-11-01 00:00:00.000']);
    $pending = new LeaveTravelRequest;
    $pending->forceFill(['code' => 'LR-2', 'employee_id' => $employee->getKey(), 'request_type' => 'VACATION_LEAVE', 'day_type' => 'WHOLE_DAY', 'start_date' => '2026-12-01', 'end_date' => '2026-12-01', 'status' => 'PENDING'])->save();
    $migration = require base_path('database/migrations/2026_11_11_0007_freeze_approved_leave_forms_table.php');

    $migration->up();
    $stored = $approved->refresh()->approved_form;
    app(SaveLeaveSignatory::class)->handle($access, 'APPROVED', null);
    $migration->up();

    expect($stored['officers']['APPROVED']['name'])->toBe('')
        ->and($approved->refresh()->approved_form)->toBe($stored)
        ->and($approved->updated_at->format('Y-m-d'))->toBe('2026-11-01')
        ->and($pending->refresh()->approved_form)->toBeNull();
});

it('approves and reads as the records stand before the column exists', function () {
    $engine = in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true);
    $migration = require base_path('database/migrations/2026_11_11_0006_add_approved_form_to_leave_travel_requests_table.php');
    $migration->down();
    Once::flush();
    if ($engine) {
        DB::connection()->getPdo()->beginTransaction();
    }

    try {
        ['request' => $request, 'employee' => $employee] = alfFiled();
        app(ReviewAbsence::class)->handle(Access::forActor(alfActor(['LEAVES'], allData: true)), $request->id, 'APPROVED', true);
        $employee->forceFill(['last_name' => 'Changed'])->save();

        expect($request->refresh()->status)->toBe('APPROVED');
        Livewire::test(Index::class)->call('show', $request->id)->call('viewApplication')
            ->assertSeeHtml('data-applicant-last>Changed</dd>');
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
