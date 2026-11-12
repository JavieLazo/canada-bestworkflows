<?php

declare(strict_types=1);

use App\Livewire\Leaves\Index;
use App\Livewire\Leaves\LeaveCredits;
use App\Livewire\Portal\PortalPage;
use App\Livewire\Records\LeaveCreditSettingsPage;
use App\Models\AuditLog;
use App\Models\EmployeeEmploymentHistory;
use App\Models\EmployeeProfile;
use App\Models\EmploymentStatusOption;
use App\Models\LeaveAcquisition;
use App\Models\LeaveCashOut;
use App\Models\LeaveCredit;
use App\Models\LeaveEarningPosting;
use App\Models\LeaveTravelRequest;
use App\Models\LeaveType;
use App\Models\Role;
use App\Models\RoleModuleGrant;
use App\Models\User;
use App\Modules\Timekeeping\Application\Commands\FileAbsence;
use App\Modules\Timekeeping\Application\Commands\RequestLeaveCashOut;
use App\Modules\Timekeeping\Application\Commands\ReviewAbsence;
use App\Modules\Timekeeping\Application\Commands\ReviewLeaveCashOut;
use App\Modules\Timekeeping\Application\Commands\SaveLeaveRule;
use App\Modules\Timekeeping\Application\Commands\SaveLeaveTypeCredits;
use App\Modules\Timekeeping\Application\PostLeaveEarnings;
use App\Modules\Timekeeping\Application\Queries\LeaveRuleRecords;
use App\Modules\Timekeeping\Application\Queries\LeaveTypeCreditRecords;
use App\Modules\Timekeeping\Contracts\LeaveChargeSource;
use App\Support\Access;
use App\Support\ManilaClock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Once;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Support\ScheduleFixture;

/**
 * WHO MAY USE A LEAVE TYPE is HR's setting on Leave Settings: every
 * employment status or the chosen ones, and anyone or one sex. An employee
 * is judged on the status and sex their record holds TODAY; a record with no
 * status on the list, or no sex, is left out of a type restricted by it. A
 * type an employee may not use is not offered to them, cannot be filed or
 * approved with pay, earns nothing (its months are closed, so a later change
 * of status never earns them back), is not charged for attendance and is not
 * converted to cash; credits they already hold stay. The waiting period
 * counts from the day the employee became eligible.
 */

/** @param  list<string>  $modules */
function leligActor(array $modules = ['LEAVES', 'RECORDS', 'PAYROLL', 'PORTAL'], bool $allData = true, ?string $employeeId = null): Access
{
    $role = new Role;
    $role->forceFill(['name' => 'Lelig '.Str::random(6), 'all_data' => $allData, 'is_system' => false])->save();
    foreach ($modules as $module) {
        $grant = new RoleModuleGrant;
        $grant->forceFill(['role_id' => $role->getKey(), 'module' => $module])->save();
    }
    test()->actingAs(User::factory()->create(['role_id' => $role->getKey(), 'employee_id' => $employeeId]));
    app()->forgetInstance(Access::class);

    return app(Access::class);
}

function leligStatus(string $name): string
{
    $option = new EmploymentStatusOption;
    $option->forceFill(['code' => (string) random_int(100, 999999), 'name' => $name])->save();

    return $option->id;
}

/** An employee with a HIRED row in Employment History, as a hire through the wizard has. */
function leligEmployee(string $status, ?string $sex = null, string $hired = '2025-06-01'): EmployeeProfile
{
    $employee = new EmployeeProfile;
    $employee->forceFill([
        'employee_code' => 'LELIG-'.Str::random(8), 'first_name' => 'Eli', 'last_name' => 'Gible '.Str::random(4),
        'employment_status' => $status, 'sex' => $sex, 'date_hired' => $hired, 'is_active' => true,
    ])->save();
    leligHistory($employee, 'HIRED', $hired, 'position,employment_status,date_hired');

    ScheduleFixture::standard($employee);

    return $employee;
}

function leligHistory(EmployeeProfile $employee, string $event, string $effective, string $changed): void
{
    $row = new EmployeeEmploymentHistory;
    $row->forceFill([
        'employee_id' => $employee->id, 'event_type' => $event, 'effective_date' => $effective, 'changed_fields' => $changed,
        'employment_status' => $employee->employment_status, 'date_hired' => $employee->date_hired->format('Y-m-d'), 'is_active' => true, 'monthly_rate' => 0,
    ])->save();
}

/** The record changes status on `$effective`, recorded the way an Employee Details save records it. */
function leligConvert(EmployeeProfile $employee, string $status, string $effective): void
{
    $employee->forceFill(['employment_status' => $status])->save();
    leligHistory($employee, 'UPDATED', $effective, 'employment_status');
}

/**
 * A leave type with its settings saved through the command.
 *
 * @param  array<string, mixed>  $settings
 */
function leligType(Access $access, string $name, array $settings = []): LeaveType
{
    $type = new LeaveType;
    $type->forceFill(['code' => 'LE-'.Str::random(6), 'name' => $name])->save();
    app(SaveLeaveTypeCredits::class)->handle($access, $type->id, [
        'earns_credits' => false, 'unit' => 'DAYS', 'charge_late' => false, 'charge_absence' => false,
        'charge_order' => '0', 'below_zero_limit' => '0', ...$settings,
    ]);

    return $type->refresh();
}

/** @return array<string, mixed> */
function leligOnly(string ...$statusIds): array
{
    return ['status_mode' => 'SOME', 'statuses' => $statusIds];
}

/** @param  array<string, mixed>  $overrides */
function leligRule(Access $access, string $typeId, string $manilaDay, array $overrides = []): void
{
    leligFreeze($manilaDay);
    app(SaveLeaveRule::class)->handle($access, $typeId, array_merge([
        'waiting_months' => null, 'waiting_extends' => false, 'on_acquire' => 'ZERO',
        'earning' => ['amount' => '1.25', 'method' => 'FLAT', 'table' => [], 'partial' => 'FULL', 'cap' => null], 'fallback' => null,
    ], $overrides));
}

function leligFreeze(string $manilaDay): void
{
    test()->travelTo(CarbonImmutable::parse($manilaDay.' 09:00:00', ManilaClock::TZ)->utc());
}

function leligCredit(EmployeeProfile $employee, string $token, float $credits, int $year = 2026): void
{
    $credit = new LeaveCredit;
    $credit->forceFill(['employee_id' => $employee->id, 'year' => $year, 'request_type' => $token, 'credits' => $credits])->save();
}

/*
|--------------------------------------------------------------------------
| The setting
|--------------------------------------------------------------------------
*/

it('saves who may use a leave type, re-resolving each status against the Employment list, and says so in the audit line', function () {
    $manager = leligActor();
    $permanent = leligStatus('Permanent');
    leligStatus('Job Order');
    $type = leligType($manager, 'Maternity Leave', [...leligOnly($permanent), 'sex' => 'FEMALE', 'filing' => 'OCCASION', 'allowance_days' => '105', 'day_count' => 'CALENDAR']);

    expect($type->eligible_statuses)->toBe([$permanent])
        ->and($type->eligible_sex)->toBe('FEMALE')
        ->and(LeaveTypeCreditRecords::of('MATERNITY_LEAVE')->eligibility->describe([$permanent => 'Permanent']))->toBe('Permanent · female employees only')
        ->and(AuditLog::query()->where('action', 'LEAVE_TYPE_CREDITS_SET')->where('entity_id', $type->id)->latest('created_at')->value('details'))
        ->toContain('for Permanent · female employees only');

    $before = $type->only(['eligible_statuses', 'eligible_sex']);
    expect(fn () => app(SaveLeaveTypeCredits::class)->handle($manager, $type->id, ['earns_credits' => false, 'unit' => 'DAYS', 'charge_late' => false, 'charge_absence' => false, 'charge_order' => '0', 'below_zero_limit' => '0', ...leligOnly('not-on-the-list')]))
        ->toThrow(RuntimeException::class, 'from the Employment list')
        ->and(fn () => app(SaveLeaveTypeCredits::class)->handle($manager, $type->id, ['earns_credits' => false, 'unit' => 'DAYS', 'charge_late' => false, 'charge_absence' => false, 'charge_order' => '0', 'below_zero_limit' => '0', 'status_mode' => 'SOME', 'statuses' => []]))
        ->toThrow(RuntimeException::class, 'at least one employment status')
        ->and($type->refresh()->only(['eligible_statuses', 'eligible_sex']))->toBe($before);

    // Back to everyone: a save with no choice clears both.
    leligType($manager, 'Spare Leave');
    app(SaveLeaveTypeCredits::class)->handle($manager, $type->id, ['earns_credits' => false, 'unit' => 'DAYS', 'charge_late' => false, 'charge_absence' => false, 'charge_order' => '0', 'below_zero_limit' => '0', 'filing' => 'OCCASION', 'allowance_days' => '105']);
    expect($type->refresh()->eligible_statuses)->toBeNull()->and($type->eligible_sex)->toBeNull();
});

it('sets who may use a type from the Leave Settings dialog, showing whom the choice reaches before it is saved', function () {
    $manager = leligActor();
    $permanent = leligStatus('Permanent');
    leligStatus('Job Order');
    leligEmployee('Permanent', 'Female');
    leligEmployee('Job Order', 'Male');
    leligEmployee('Job Order', 'Female');
    $type = leligType($manager, 'Vacation Leave');

    Livewire::test(LeaveCreditSettingsPage::class)
        ->call('openType', $type->id)
        ->set('typeForm.status_mode', 'SOME')
        ->set("typeForm.statuses.{$permanent}", true)
        ->assertSeeHtml('data-leave-eligibility-reach')
        ->assertSee('1 active employee may use it; left out: 2 Job Order.')
        ->call('saveType')
        ->assertSeeHtml('data-leave-eligibility')
        ->assertSee('for Permanent');

    expect($type->refresh()->eligible_statuses)->toBe([$permanent]);
});

/*
|--------------------------------------------------------------------------
| Filing and approval
|--------------------------------------------------------------------------
*/

it('refuses a filing of a type the employee may not use, by status and by sex, with no leave rule in force', function () {
    leligFreeze('2026-09-20');
    $manager = leligActor();
    $permanent = leligStatus('Permanent');
    leligStatus('Job Order');
    leligType($manager, 'Vacation Leave', leligOnly($permanent));
    leligType($manager, 'Maternity Leave', ['sex' => 'FEMALE', 'filing' => 'OCCASION', 'allowance_days' => '105', 'day_count' => 'CALENDAR']);
    $jobOrder = leligEmployee('Job Order', 'Female');
    $man = leligEmployee('Permanent', 'Male');
    $unrecorded = leligEmployee('Permanent');
    $woman = leligEmployee('Permanent', 'Female');

    $before = LeaveTravelRequest::query()->count();
    expect(fn () => app(FileAbsence::class)->forEmployee($manager, $jobOrder->id, 'VACATION_LEAVE', 'WHOLE_DAY', '2026-09-22', '2026-09-22', ''))
        ->toThrow(RuntimeException::class, 'Vacation Leave is not available to Job Order employees.')
        ->and(fn () => app(FileAbsence::class)->forEmployee($manager, $man->id, 'MATERNITY_LEAVE', 'WHOLE_DAY', '2026-09-22', '2026-09-22', ''))
        ->toThrow(RuntimeException::class, 'Maternity Leave is for female employees only.')
        ->and(fn () => app(FileAbsence::class)->forEmployee($manager, $unrecorded->id, 'MATERNITY_LEAVE', 'WHOLE_DAY', '2026-09-22', '2026-09-22', ''))
        ->toThrow(RuntimeException::class, 'No sex is recorded')
        ->and(LeaveTravelRequest::query()->count())->toBe($before);

    // Those the types are for file as before; a sex restriction leaves the status alone.
    app(FileAbsence::class)->forEmployee($manager, $woman->id, 'VACATION_LEAVE', 'WHOLE_DAY', '2026-09-22', '2026-09-22', '');
    app(FileAbsence::class)->forEmployee($manager, $jobOrder->id, 'MATERNITY_LEAVE', 'WHOLE_DAY', '2026-09-22', '2026-09-23', '');
    expect(LeaveTravelRequest::query()->count())->toBe($before + 2);
});

it('approves a pending request of a type the employee no longer may use only as unpaid leave', function () {
    leligFreeze('2026-09-20');
    $manager = leligActor();
    $permanent = leligStatus('Permanent');
    leligStatus('Job Order');
    leligType($manager, 'Vacation Leave', leligOnly($permanent));
    $employee = leligEmployee('Permanent');
    leligCredit($employee, 'VACATION_LEAVE', 5);
    app(FileAbsence::class)->forEmployee($manager, $employee->id, 'VACATION_LEAVE', 'WHOLE_DAY', '2026-09-22', '2026-09-22', '');
    $request = LeaveTravelRequest::query()->where('employee_id', $employee->id)->sole();
    leligConvert($employee, 'Job Order', '2026-09-20');

    expect(fn () => app(ReviewAbsence::class)->handle($manager, $request->id, 'APPROVED', true))
        ->toThrow(RuntimeException::class, 'Approve it as unpaid leave or disapprove it.')
        ->and($request->refresh()->status)->toBe('PENDING');

    app(ReviewAbsence::class)->handle($manager, $request->id, 'APPROVED', false);
    expect($request->refresh()->status)->toBe('APPROVED')->and($request->is_paid)->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| Earning, the waiting period and deductions
|--------------------------------------------------------------------------
*/

it('earns nothing while the employee may not use the type, closes those months, and earns from the day they became eligible', function () {
    $manager = leligActor();
    $permanent = leligStatus('Permanent');
    leligStatus('Job Order');
    $type = leligType($manager, 'Vacation Leave', [...leligOnly($permanent), 'earns_credits' => true]);
    $jobOrder = leligEmployee('Job Order');
    $regular = leligEmployee('Permanent');
    leligRule($manager, $type->id, '2026-01-01');

    leligFreeze('2026-02-02');
    app(PostLeaveEarnings::class)->run('2026-02-02');
    $january = LeaveEarningPosting::query()->where('employee_id', $jobOrder->id)->where('period', '2026-01')->sole();
    expect($january->days)->toBe(0.0)->and($january->entry_id)->toBeNull()
        ->and(LeaveCredit::query()->where('employee_id', $jobOrder->id)->exists())->toBeFalse();

    // Made Permanent on 16 March: February ended before it and is closed too; March earns.
    leligConvert($jobOrder, 'Permanent', '2026-03-16');
    leligFreeze('2026-04-01');
    app(PostLeaveEarnings::class)->run('2026-04-01');
    expect(LeaveEarningPosting::query()->where('employee_id', $jobOrder->id)->orderBy('period')->pluck('days', 'period')->all())
        ->toBe(['2026-01' => 0.0, '2026-02' => 0.0, '2026-03' => 1.25])
        ->and((float) LeaveCredit::query()->where('employee_id', $jobOrder->id)->where('request_type', 'VACATION_LEAVE')->value('credits'))->toBe(1.25)
        // Someone eligible all along earned every month.
        ->and((float) LeaveCredit::query()->where('employee_id', $regular->id)->where('request_type', 'VACATION_LEAVE')->value('credits'))->toBe(3.75);

    // A second run writes nothing more.
    $count = LeaveEarningPosting::query()->count();
    app(PostLeaveEarnings::class)->run('2026-04-01');
    expect(LeaveEarningPosting::query()->count())->toBe($count);
});

it('counts a waiting period from the day the employee became eligible, not from the date hired', function () {
    $manager = leligActor();
    $permanent = leligStatus('Permanent');
    leligStatus('Job Order');
    $type = leligType($manager, 'Vacation Leave', [...leligOnly($permanent), 'earns_credits' => true]);
    $employee = leligEmployee('Job Order');
    leligRule($manager, $type->id, '2026-01-01', ['waiting_months' => '1']);
    leligConvert($employee, 'Permanent', '2026-03-16');

    leligFreeze('2026-04-01');
    app(PostLeaveEarnings::class)->run('2026-04-01');
    expect(LeaveAcquisition::query()->where('employee_id', $employee->id)->exists())->toBeFalse()
        ->and(app(LeaveRuleRecords::class)->standing($manager, [$employee->id])[$employee->id] ?? [])->toContain('Vacation Leave not acquired yet · Apr 16, 2026 at the earliest');

    leligFreeze('2026-04-20');
    app(PostLeaveEarnings::class)->run('2026-04-20');
    $row = LeaveAcquisition::query()->where('employee_id', $employee->id)->sole();
    expect($row->acquired_on->format('Y-m-d'))->toBe('2026-04-16')
        ->and($row->basis['eligible_from'])->toBe('2026-03-16');
});

it('leaves a type the employee may not use out of payroll\'s attendance deductions, and dates the others from the day they became eligible', function () {
    $manager = leligActor();
    $permanent = leligStatus('Permanent');
    leligStatus('Job Order');
    $type = leligType($manager, 'Vacation Leave', [...leligOnly($permanent), 'earns_credits' => true, 'charge_late' => true, 'charge_absence' => true, 'charge_order' => '1']);
    $jobOrder = leligEmployee('Job Order');
    $converted = leligEmployee('Job Order');
    leligConvert($converted, 'Permanent', '2026-02-10');
    leligRule($manager, $type->id, '2026-01-01');
    leligFreeze('2026-02-20');

    $facts = app(LeaveChargeSource::class)->forPayroll($manager, [$jobOrder->id, $converted->id], '2026-02-01', '2026-02-15');
    expect($facts)->not->toHaveKey($jobOrder->id)
        ->and($facts[$converted->id]['charges']['absence'])->toBe(['VACATION_LEAVE'])
        ->and($facts[$converted->id]['balances']['VACATION_LEAVE']['available_from'])->toBe('2026-02-10');
});

/*
|--------------------------------------------------------------------------
| Cash conversion and the screens
|--------------------------------------------------------------------------
*/

it('does not convert credits of a type the employee may not use, though they stay on the balance', function () {
    leligFreeze('2026-09-20');
    $manager = leligActor();
    $permanent = leligStatus('Permanent');
    leligStatus('Job Order');
    leligType($manager, 'Vacation Leave', [...leligOnly($permanent), 'can_cash_out' => true]);
    $employee = leligEmployee('Job Order');
    leligCredit($employee, 'VACATION_LEAVE', 10);
    $self = leligActor(['PORTAL'], false, $employee->id);

    expect(fn () => app(RequestLeaveCashOut::class)->handle($self, 'VACATION_LEAVE', '2', ''))
        ->toThrow(RuntimeException::class, 'Its credits cannot be converted to cash.')
        ->and(LeaveCashOut::query()->count())->toBe(0)
        ->and((float) LeaveCredit::query()->where('employee_id', $employee->id)->value('credits'))->toBe(10.0);
});

it('does not approve a cash conversion asked for before the employee stopped being eligible; rejecting it still works', function () {
    leligFreeze('2026-09-20');
    $manager = leligActor();
    $permanent = leligStatus('Permanent');
    leligStatus('Job Order');
    leligType($manager, 'Vacation Leave', [...leligOnly($permanent), 'can_cash_out' => true]);
    $employee = leligEmployee('Permanent');
    leligCredit($employee, 'VACATION_LEAVE', 10);
    app(RequestLeaveCashOut::class)->handle(leligActor(['PORTAL'], false, $employee->id), 'VACATION_LEAVE', '2', '');
    $request = LeaveCashOut::query()->sole();
    leligConvert($employee, 'Job Order', '2026-09-20');
    $manager = leligActor();

    expect(fn () => app(ReviewLeaveCashOut::class)->handle($manager, $request->id, true, ''))
        ->toThrow(RuntimeException::class, 'reject the request instead')
        ->and($request->refresh()->status)->toBe(LeaveCashOut::PENDING);

    app(ReviewLeaveCashOut::class)->handle($manager, $request->id, false, '');
    expect($request->refresh()->status)->toBe(LeaveCashOut::REJECTED);
});

it('offers an employee only the types they may use, in My Portal and in HR\'s filing, and warns before HR sets their credits', function () {
    leligFreeze('2026-09-20');
    $manager = leligActor();
    $permanent = leligStatus('Permanent');
    leligStatus('Job Order');
    leligType($manager, 'Vacation Leave', [...leligOnly($permanent), 'can_cash_out' => true]);
    leligType($manager, 'Sick Leave');
    leligType($manager, 'Maternity Leave', ['sex' => 'FEMALE', 'filing' => 'OCCASION', 'allowance_days' => '105', 'day_count' => 'CALENDAR']);
    $employee = leligEmployee('Job Order', 'Male');

    $values = static fn (array $options): array => array_column($options, 'value');

    // HR's filing offers what the PICKED employee may use.
    $hr = Livewire::test(Index::class)->call('openFile');
    expect($values($hr->viewData('requestOptions')))->toContain('VACATION_LEAVE');
    $hr->call('employeePicked', 'file-employee', $employee->id);
    expect($values($hr->viewData('requestOptions')))->not->toContain('VACATION_LEAVE')->toContain('SICK_LEAVE');

    // Leave Credits names why for a type that holds credits — not for a
    // type with set days, which holds none — and the Set credits dialog warns.
    Livewire::test(LeaveCredits::class)
        ->assertSee('Vacation Leave is not available to Job Order employees.')
        ->assertDontSee('Maternity Leave is for female employees only.')
        ->call('openCredits', $employee->id)
        ->assertSeeHtml('data-credit-not-eligible');

    leligCredit($employee, 'VACATION_LEAVE', 4);
    leligActor(['PORTAL'], false, $employee->id);
    $portal = Livewire::test(PortalPage::class)->set('tab', 'requests');
    expect($values($portal->viewData('requestOptions')))->not->toContain('VACATION_LEAVE')->not->toContain('MATERNITY_LEAVE')->toContain('SICK_LEAVE')
        // The credits held stay on the card, but are offered for no cash conversion.
        ->and($portal->viewData('balances')->pluck('request_type')->all())->toContain('VACATION_LEAVE')
        ->and($portal->viewData('cashOutTypes'))->toBe([])
        // The leave with set days lists only what he may use.
        ->and(array_column($portal->viewData('allowances'), 'name'))->not->toContain('Maternity Leave');
});

/*
|--------------------------------------------------------------------------
| The migration
|--------------------------------------------------------------------------
*/

it('adds who may use a leave type twice without error, everyone by default, and drops it on the way down', function () {
    // The DDL first, before anything is seeded: on MySQL/MariaDB it commits
    // the test's transaction, and rows seeded before it would stay.
    $migration = require base_path('database/migrations/2026_11_09_0005_add_eligibility_to_leave_types_table.php');
    $migration->down();
    $dropped = ! Schema::hasColumn('leave_types', 'eligible_statuses') && ! Schema::hasColumn('leave_types', 'eligible_sex');
    $migration->up();
    $migration->up();
    Once::flush();
    $engine = in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true);
    if ($engine) {
        DB::connection()->getPdo()->beginTransaction();
    }

    try {
        $type = leligType(leligActor(), 'Vacation Leave');
        expect($dropped)->toBeTrue()
            ->and(Schema::hasColumns('leave_types', ['eligible_statuses', 'eligible_sex']))->toBeTrue()
            ->and(DB::table('leave_types')->where('id', $type->id)->value('eligible_statuses'))->toBeNull()
            ->and(DB::table('leave_types')->where('id', $type->id)->value('eligible_sex'))->toBeNull();
    } finally {
        if ($engine) {
            DB::connection()->getPdo()->rollBack();
            DB::connection()->getPdo()->beginTransaction();
        }
    }
});

it('works as before until the cron adds the columns: everyone may use every type, and a restriction waits', function () {
    leligFreeze('2026-09-20');
    $migration = require base_path('database/migrations/2026_11_09_0005_add_eligibility_to_leave_types_table.php');
    $migration->down();
    Once::flush();
    $engine = in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true);
    if ($engine) {
        DB::connection()->getPdo()->beginTransaction();
    }

    try {
        $manager = leligActor();
        $permanent = leligStatus('Permanent');
        $type = leligType($manager, 'Vacation Leave');
        expect(LeaveTypeCreditRecords::restricted())->toBe([])
            ->and(fn () => app(SaveLeaveTypeCredits::class)->handle($manager, $type->id, ['earns_credits' => false, 'unit' => 'DAYS', 'charge_late' => false, 'charge_absence' => false, 'charge_order' => '0', 'below_zero_limit' => '0', ...leligOnly($permanent)]))
            ->toThrow(RuntimeException::class, 'once this installation\'s upgrade finishes');
        $employee = leligEmployee('Job Order');
        app(FileAbsence::class)->forEmployee($manager, $employee->id, 'VACATION_LEAVE', 'WHOLE_DAY', '2026-09-22', '2026-09-22', '');
        expect(LeaveTravelRequest::query()->where('employee_id', $employee->id)->exists())->toBeTrue();
        Livewire::test(LeaveCreditSettingsPage::class)->call('openType', $type->id)->assertDontSeeHtml('data-leave-eligibility-setting');
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
