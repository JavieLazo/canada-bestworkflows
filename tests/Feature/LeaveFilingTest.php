<?php

declare(strict_types=1);

use App\Domain\Attendance\DtrCalculator;
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
use App\Modules\Timekeeping\Application\Commands\CancelAbsence;
use App\Modules\Timekeeping\Application\Commands\FileAbsence;
use App\Modules\Timekeeping\Application\Commands\ReviewAbsence;
use App\Modules\Timekeeping\Application\Commands\SaveLeaveRule;
use App\Modules\Timekeeping\Application\Commands\SaveLeaveTypeCredits;
use App\Modules\Timekeeping\Application\LeaveBalances;
use App\Modules\Timekeeping\Application\Queries\LeaveCreditRecords;
use App\Modules\Timekeeping\Application\Queries\LeaveLedgerRecords;
use App\Modules\Timekeeping\Infrastructure\AttendanceInputQuery;
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
 * HOW A LEAVE TYPE IS FILED is HR's setting on Leave Settings — earned
 * credits, a set number of days each year or each time (counted in working
 * or calendar days), or without pay — and A SHORT BALANCE NEVER REFUSES A
 * LEAVE: approval pays the earliest counted days as far as the credits (or
 * the set days) reach and leaves the rest of the same request WITHOUT PAY.
 * The Daily Time Record and payroll read that request as a paid part and a
 * part without pay; the ledger shows both. A type filed without pay is
 * always approved without pay, and holds, earns and is charged no credits.
 * A type whose below zero limit HR set (0, the default, never) pays past
 * its credits down to minus that limit — after the fallback type's credits
 * — and says so at filing, in review and in the decision.
 */

/** @param list<string> $modules */
function lfilActor(array $modules = ['LEAVES', 'RECORDS', 'PORTAL'], bool $allData = true, ?string $employeeId = null): Access
{
    $role = new Role;
    $role->forceFill(['name' => 'Lfil '.Str::random(6), 'all_data' => $allData, 'is_system' => false])->save();
    foreach ($modules as $module) {
        $grant = new RoleModuleGrant;
        $grant->forceFill(['role_id' => $role->getKey(), 'module' => $module])->save();
    }
    test()->actingAs(User::factory()->create(['role_id' => $role->getKey(), 'employee_id' => $employeeId]));
    app()->forgetInstance(Access::class);

    return app(Access::class);
}

function lfilEmployee(): EmployeeProfile
{
    $employee = new EmployeeProfile;
    $employee->forceFill([
        'employee_code' => 'LFIL-'.Str::random(8), 'first_name' => 'Fay', 'last_name' => 'Filing '.Str::random(4),
        'employment_status' => 'Regular', 'date_hired' => '2024-01-01', 'is_active' => true,
    ])->save();

    ScheduleFixture::standard($employee);

    return $employee;
}

/**
 * A leave type with its settings saved through the command.
 *
 * @param  array<string, mixed>  $settings
 */
function lfilType(Access $access, string $name, array $settings = []): LeaveType
{
    $type = new LeaveType;
    $type->forceFill(['code' => 'LF-'.Str::random(6), 'name' => $name])->save();
    app(SaveLeaveTypeCredits::class)->handle($access, $type->id, [
        'earns_credits' => false, 'unit' => 'DAYS', 'charge_late' => false, 'charge_absence' => false,
        'charge_order' => '0', 'below_zero_limit' => '0', ...$settings,
    ]);

    return $type->refresh();
}

function lfilCredit(EmployeeProfile $employee, string $token, float $credits, int $year = 2026): void
{
    $credit = new LeaveCredit;
    $credit->forceFill(['employee_id' => $employee->id, 'year' => $year, 'request_type' => $token, 'credits' => $credits])->save();
}

function lfilRequest(EmployeeProfile $employee, string $token, string $start, string $end, string $dayType = 'WHOLE_DAY'): LeaveTravelRequest
{
    $request = new LeaveTravelRequest;
    $request->forceFill([
        'code' => (string) random_int(1000, 999999), 'employee_id' => $employee->id, 'request_type' => $token,
        'day_type' => $dayType, 'start_date' => $start, 'end_date' => $end, 'reason' => 'Test', 'status' => 'PENDING',
    ])->save();

    return $request;
}

/** @return array<string, bool|null> date => whether the DTR pays the leave that day */
function lfilDtrPay(EmployeeProfile $employee, string $from, string $to): array
{
    $inputs = app(AttendanceInputQuery::class)->forPosting([$employee->id], $from, $to)[$employee->id];
    $out = [];
    foreach (DtrCalculator::compute($inputs->logs, $inputs->shift, $inputs->leaves, $from, $to, []) as $day) {
        $out[$day->date] = $day->excusePaid;
    }

    return $out;
}

beforeEach(function () {
    // A Sunday before every date the tests file: all of them are ahead.
    $this->travelTo(CarbonImmutable::parse('2026-09-20 09:00:00', ManilaClock::TZ)->utc());
});

/*
|--------------------------------------------------------------------------
| Earned credits: paid as far as they reach
|--------------------------------------------------------------------------
*/

it('approves a short leave partly with pay: the credits pay the earliest days, the rest is without pay on the DTR and in the ledger', function () {
    $manager = lfilActor();
    lfilType($manager, 'Vacation Leave');
    $employee = lfilEmployee();
    lfilCredit($employee, 'VACATION_LEAVE', 2);
    // The employee's own account, so the decision has somewhere to land.
    User::factory()->create(['role_id' => Role::query()->value('id'), 'employee_id' => $employee->id]);
    $request = lfilRequest($employee, 'VACATION_LEAVE', '2026-09-28', '2026-10-02');

    $outcome = app(ReviewAbsence::class)->handle($manager, $request->id, 'APPROVED', true);

    $request->refresh();
    expect($outcome)->toBe('approved: 2 days paid, 3 days unpaid (not enough Vacation Leave credits)')
        ->and($request->is_paid)->toBeTrue()
        ->and($request->credits_year)->toBe(2026)
        ->and($request->credit_days)->toBe(2.0)
        ->and($request->credit_dates)->toBe(['2026-09-28', '2026-09-29'])
        ->and($request->unpaid_dates)->toBe(['2026-09-30', '2026-10-01', '2026-10-02'])
        ->and(LeaveBalances::balance($employee->id, 2026, 'VACATION_LEAVE'))->toBe(0.0)
        ->and(Notification::query()->where('kind', 'LEAVE_REVIEWED')->value('body'))->toBe('Approved: 2 days paid, 3 days unpaid (not enough Vacation Leave credits)');

    // The DTR (and so payroll) reads the paid part and the part without pay.
    expect(lfilDtrPay($employee, '2026-09-28', '2026-10-02'))->toBe([
        '2026-09-28' => true, '2026-09-29' => true, '2026-09-30' => false, '2026-10-01' => false, '2026-10-02' => false,
    ]);

    // The ledger books both, ahead of today.
    $row = collect(app(LeaveLedgerRecords::class)->summary($manager, $employee->id, 2026))->firstWhere('request_type', 'VACATION_LEAVE');
    expect([$row->booked_paid, $row->booked_unpaid])->toBe([2.0, 3.0]);
});

it('approves without pay whole when no credits cover it, or when the reviewer chooses so', function () {
    $manager = lfilActor();
    lfilType($manager, 'Vacation Leave');
    $none = lfilEmployee();
    $plenty = lfilEmployee();
    lfilCredit($plenty, 'VACATION_LEAVE', 5);
    $short = lfilRequest($none, 'VACATION_LEAVE', '2026-09-28', '2026-09-29');
    $chosen = lfilRequest($plenty, 'VACATION_LEAVE', '2026-09-28', '2026-09-29');

    expect(app(ReviewAbsence::class)->handle($manager, $short->id, 'APPROVED', true))->toBe('approved as unpaid leave (not enough Vacation Leave credits)')
        ->and(app(ReviewAbsence::class)->handle($manager, $chosen->id, 'APPROVED', false))->toBe('approved as unpaid leave');

    expect($short->refresh()->is_paid)->toBeFalse()
        ->and($short->credit_days)->toBeNull()
        ->and($short->unpaid_dates)->toBeNull()
        ->and($chosen->refresh()->is_paid)->toBeFalse()
        ->and(LeaveBalances::balance($plenty->id, 2026, 'VACATION_LEAVE'))->toBe(5.0)
        ->and(lfilDtrPay($none, '2026-09-28', '2026-09-29'))->toBe(['2026-09-28' => false, '2026-09-29' => false]);
});

/*
|--------------------------------------------------------------------------
| Below zero: an option per leave type, off until HR sets a limit
|--------------------------------------------------------------------------
*/

it('pays a short leave below zero as far as the type\'s limit allows, after the credits held, and leaves the rest without pay', function () {
    $manager = lfilActor();
    lfilType($manager, 'Vacation Leave', ['below_zero_limit' => '2']);
    $employee = lfilEmployee();
    lfilCredit($employee, 'VACATION_LEAVE', 1);

    Livewire::test(LeaveCreditSettingsPage::class)
        ->assertSeeHtml('data-leave-below-zero');

    // Filing (and My Portal's hint) says what approval would pay below zero, and what lies past the limit.
    $self = lfilActor(['PORTAL'], allData: false, employeeId: $employee->id);
    Livewire::test(PortalPage::class)->set('tab', 'requests')
        ->set('type', 'VACATION_LEAVE')->set('start', '2026-09-28')->set('end', '2026-10-02')
        ->assertSeeHtml('data-credit-below-zero')
        ->assertSee('2 days would be paid by taking your Vacation Leave to a negative balance of -2.000, if approved.');
    expect(app(FileAbsence::class)->handle($self, 'VACATION_LEAVE', 'WHOLE_DAY', '2026-09-28', '2026-10-02', ''))
        ->toBe('Your available Vacation Leave credits (1.000), and a negative balance of up to 2.000, cover 3 days of the 5 days; the other 2 days will be unpaid if approved.'
            .' 2 days would be paid by taking your Vacation Leave to a negative balance of -2.000, if approved.');
    $request = LeaveTravelRequest::query()->where('employee_id', $employee->id)->sole();

    $manager = lfilActor();
    Livewire::test(Index::class)->call('show', $request->id)
        ->assertSeeHtml('data-pay-below-zero')
        ->assertSee("2 days would be paid by taking the employee's Vacation Leave to a negative balance of -2.000, if approved.");

    $outcome = app(ReviewAbsence::class)->handle($manager, $request->id, 'APPROVED', true);

    $request->refresh();
    $decision = 'approved: 3 days paid, 2 days unpaid (beyond Vacation Leave\'s maximum negative balance); Vacation Leave goes to a negative balance of -2.000';
    expect($outcome)->toBe($decision)
        ->and($request->credit_days)->toBe(3.0)
        ->and($request->credit_dates)->toBe(['2026-09-28', '2026-09-29', '2026-09-30'])
        ->and($request->unpaid_dates)->toBe(['2026-10-01', '2026-10-02'])
        ->and(LeaveBalances::balance($employee->id, 2026, 'VACATION_LEAVE'))->toBe(-2.0)
        ->and(Notification::query()->where('kind', 'LEAVE_REVIEWED')->value('body'))->toBe(ucfirst($decision))
        ->and(AuditLog::query()->where('action', 'REQUEST_APPROVED')->value('details'))->toContain('Vacation Leave credits go below zero, to -2.000');
    expect(lfilDtrPay($employee, '2026-09-28', '2026-10-02'))->toBe([
        '2026-09-28' => true, '2026-09-29' => true, '2026-09-30' => true, '2026-10-01' => false, '2026-10-02' => false,
    ]);
});

it('spends the fallback type\'s credits before going below zero, and counts a debt the balance already carries against the limit', function () {
    $manager = lfilActor();
    lfilType($manager, 'Vacation Leave');
    $sick = lfilType($manager, 'Sick Leave', ['below_zero_limit' => '2']);
    app(SaveLeaveRule::class)->handle($manager, $sick->id, [
        'waiting_months' => null, 'waiting_extends' => false, 'on_acquire' => 'ZERO', 'earning' => null, 'fallback' => 'VACATION_LEAVE',
    ]);
    $employee = lfilEmployee();
    // Already one below zero, as a payroll's absence charge can leave it.
    lfilCredit($employee, 'SICK_LEAVE', -1);
    lfilCredit($employee, 'VACATION_LEAVE', 1);
    $request = lfilRequest($employee, 'SICK_LEAVE', '2026-09-28', '2026-10-01');

    // Vacation Leave's 1 credit first, then the 1 left of the limit: two days paid.
    expect(app(ReviewAbsence::class)->handle($manager, $request->id, 'APPROVED', true))
        ->toBe('approved: 2 days paid, 2 days unpaid (beyond Sick Leave\'s maximum negative balance); Sick Leave goes to a negative balance of -2.000. 1.000 credit was drawn from Vacation Leave');

    expect($request->refresh()->credit_days)->toBe(2.0)
        ->and($request->unpaid_dates)->toBe(['2026-09-30', '2026-10-01'])
        ->and(LeaveBalances::balance($employee->id, 2026, 'SICK_LEAVE'))->toBe(-2.0)
        ->and(LeaveBalances::balance($employee->id, 2026, 'VACATION_LEAVE'))->toBe(0.0);

    // At the floor, the next leave has nothing below zero left: it is without pay whole.
    $next = lfilRequest($employee, 'SICK_LEAVE', '2026-10-05', '2026-10-05');
    expect(app(ReviewAbsence::class)->handle($manager, $next->id, 'APPROVED', true))
        ->toBe('approved as unpaid leave (not enough Sick Leave credits)')
        ->and(LeaveBalances::balance($employee->id, 2026, 'SICK_LEAVE'))->toBe(-2.0);
});

it('draws nothing from a fallback type not yet acquired on the request\'s start, so what the credits lack is the type\'s own below zero part', function () {
    $manager = lfilActor();
    $vacation = lfilType($manager, 'Vacation Leave');
    $sick = lfilType($manager, 'Sick Leave', ['below_zero_limit' => '2']);
    $rule = ['waiting_months' => null, 'waiting_extends' => false, 'on_acquire' => 'ZERO', 'earning' => null, 'fallback' => null];
    app(SaveLeaveRule::class)->handle($manager, $vacation->id, [...$rule, 'waiting_months' => '6']);
    app(SaveLeaveRule::class)->handle($manager, $sick->id, [...$rule, 'fallback' => 'VACATION_LEAVE']);
    // Hired in June: Vacation Leave is not acquired until December at the earliest.
    $employee = lfilEmployee();
    $employee->forceFill(['date_hired' => '2026-06-01'])->save();
    lfilCredit($employee, 'SICK_LEAVE', 0);
    lfilCredit($employee, 'VACATION_LEAVE', 3);
    $request = lfilRequest($employee, 'SICK_LEAVE', '2026-09-28', '2026-09-29');

    expect(app(ReviewAbsence::class)->handle($manager, $request->id, 'APPROVED', true))
        ->toBe('approved as paid leave; Sick Leave goes to a negative balance of -2.000')
        ->and(LeaveBalances::balance($employee->id, 2026, 'SICK_LEAVE'))->toBe(-2.0)
        ->and(LeaveBalances::balance($employee->id, 2026, 'VACATION_LEAVE'))->toBe(3.0);
});

it('never takes leave below zero on a credit not entered for the year, or while a cash-out holds days of it', function () {
    $manager = lfilActor();
    lfilType($manager, 'Vacation Leave', ['below_zero_limit' => '2']);

    // No credit entered for 2026: the year's close would never carry a debt on it.
    $unentered = lfilEmployee();
    $first = lfilRequest($unentered, 'VACATION_LEAVE', '2026-09-28', '2026-09-28');
    expect(app(ReviewAbsence::class)->handle($manager, $first->id, 'APPROVED', true))
        ->toBe('approved as unpaid leave (not enough Vacation Leave credits)');

    // 5 credits, 3 of them held for an approved cash-out the next payroll pays: only the 2 free ones pay leave.
    $cashing = lfilEmployee();
    lfilCredit($cashing, 'VACATION_LEAVE', 5);
    $cashOut = new LeaveCashOut;
    $cashOut->forceFill([
        'employee_id' => $cashing->id, 'request_type' => 'VACATION_LEAVE', 'days' => 3,
        'status' => LeaveCashOut::APPROVED, 'requested_at' => CarbonImmutable::now('UTC'),
    ])->save();
    $second = lfilRequest($cashing, 'VACATION_LEAVE', '2026-09-28', '2026-10-01');
    expect(app(ReviewAbsence::class)->handle($manager, $second->id, 'APPROVED', true))
        ->toBe('approved: 2 days paid, 2 days unpaid (not enough Vacation Leave credits)')
        ->and(LeaveBalances::balance($cashing->id, 2026, 'VACATION_LEAVE'))->toBe(3.0);
});

/*
|--------------------------------------------------------------------------
| Without pay, set days each year, set days each time
|--------------------------------------------------------------------------
*/

it('files and approves a leave type filed without pay without pay, whatever was asked, and holds no credits for it', function () {
    $manager = lfilActor();
    $type = lfilType($manager, 'Unpaid Leave', ['filing' => 'UNPAID']);
    $employee = lfilEmployee();
    $request = lfilRequest($employee, 'UNPAID_LEAVE', '2026-09-28', '2026-09-28');

    expect(app(ReviewAbsence::class)->handle($manager, $request->id, 'APPROVED', true))->toBe('approved as unpaid leave')
        ->and($request->refresh()->is_paid)->toBeFalse()
        ->and(app(LeaveCreditRecords::class)->creditableTypes($manager))->not->toHaveKey('UNPAID_LEAVE')
        ->and($type->filing)->toBe('UNPAID');
});

it('pays a leave with set days each year up to what is left of the calendar year, and starts again the next year', function () {
    $manager = lfilActor();
    lfilType($manager, 'Special Privilege Leave', ['filing' => 'YEARLY', 'allowance_days' => '3']);
    $employee = lfilEmployee();

    $first = lfilRequest($employee, 'SPECIAL_PRIVILEGE_LEAVE', '2026-09-28', '2026-09-29');
    expect(app(ReviewAbsence::class)->handle($manager, $first->id, 'APPROVED', true))->toBe('approved as paid leave');
    expect($first->refresh()->credit_days)->toBe(2.0)->and($first->credits_year)->toBe(2026);

    // One day is left of the three: filing says so, approval pays one.
    $self = lfilActor(['PORTAL'], allData: false, employeeId: $employee->id);
    expect(app(FileAbsence::class)->handle($self, 'SPECIAL_PRIVILEGE_LEAVE', 'WHOLE_DAY', '2026-10-05', '2026-10-07', ''))
        ->toBe('Your Special Privilege Leave days left this year (1 of 3) cover 1 day of the 3 days; the other 2 days will be unpaid if approved.');
    $second = LeaveTravelRequest::query()->where('start_date', '2026-10-05')->sole();
    $manager = lfilActor();
    expect(app(ReviewAbsence::class)->handle($manager, $second->id, 'APPROVED', true))
        ->toBe('approved: 1 day paid, 2 days unpaid (Special Privilege Leave\'s annual allowance for 2026 used up)');
    expect($second->refresh()->unpaid_dates)->toBe(['2026-10-06', '2026-10-07'])
        ->and(LeaveCredit::query()->where('request_type', 'SPECIAL_PRIVILEGE_LEAVE')->count())->toBe(0)
        ->and(LeaveBalances::allowanceUsed($employee->id, 2026, 'SPECIAL_PRIVILEGE_LEAVE'))->toBe(3.0);

    // A new year gives the three days again.
    $next = lfilRequest($employee, 'SPECIAL_PRIVILEGE_LEAVE', '2027-01-05', '2027-01-05');
    expect(app(ReviewAbsence::class)->handle($manager, $next->id, 'APPROVED', true))->toBe('approved as paid leave')
        ->and($next->refresh()->credits_year)->toBe(2027);

    // Cancelling a paid future date gives it back to its year.
    app(CancelAbsence::class)->handle($manager, $first->id, ['2026-09-29'], 'Back at work');
    expect(LeaveBalances::allowanceUsed($employee->id, 2026, 'SPECIAL_PRIVILEGE_LEAVE'))->toBe(2.0);
});

it('pays a leave with set days each time, counted in calendar days', function () {
    $manager = lfilActor();
    lfilType($manager, 'Maternity Leave', ['filing' => 'OCCASION', 'allowance_days' => '5', 'day_count' => 'CALENDAR']);
    $employee = lfilEmployee();
    // Monday to Sunday: seven calendar days against five.
    $request = lfilRequest($employee, 'MATERNITY_LEAVE', '2026-09-28', '2026-10-04');

    app(ReviewAbsence::class)->handle($manager, $request->id, 'APPROVED', true);

    $request->refresh();
    expect($request->credit_days)->toBe(5.0)
        ->and($request->credit_dates)->toBe(['2026-09-28', '2026-09-29', '2026-09-30', '2026-10-01', '2026-10-02'])
        ->and($request->unpaid_dates)->toBe(['2026-10-03', '2026-10-04']);
});

/*
|--------------------------------------------------------------------------
| Leave Settings and the screens
|--------------------------------------------------------------------------
*/

it('sets how a leave type is filed on Leave Settings, and holds no credits for a type with set days', function () {
    $manager = lfilActor();
    $type = lfilType($manager, 'Solo Parent Leave', ['charge_absence' => true, 'can_cash_out' => true]);

    $page = Livewire::test(LeaveCreditSettingsPage::class)
        ->call('openType', $type->id)
        ->set('typeForm.filing', 'YEARLY')
        ->assertSeeHtml('data-leave-allowance')
        ->set('typeForm.allowance_days', '')
        ->call('saveType');
    expect($type->refresh()->filing)->toBe('CREDITS');

    $page->set('typeForm.allowance_days', '7')->call('saveType');
    $type->refresh();
    expect($type->filing)->toBe('YEARLY')
        ->and($type->allowance_days)->toBe(7.0)
        // A type that holds no credits is charged and cashed out for nothing.
        ->and($type->charge_absence)->toBeFalse()
        ->and((bool) $type->getAttribute('can_cash_out'))->toBeFalse()
        ->and(app(LeaveCreditRecords::class)->creditableTypes($manager))->not->toHaveKey('SOLO_PARENT_LEAVE');

    Livewire::test(LeaveCreditSettingsPage::class)
        ->assertSeeHtml('data-leave-filed="YEARLY"')
        ->assertSee('7 days each year');

    // Its rule may set a waiting period, but never an earning or a fallback.
    expect(fn () => app(SaveLeaveRule::class)->handle($manager, $type->id, [
        'waiting_months' => null, 'waiting_extends' => false, 'on_acquire' => 'ZERO',
        'earning' => ['amount' => '1', 'method' => 'FLAT', 'table' => [], 'partial' => 'FULL', 'cap' => null], 'fallback' => null,
    ]))->toThrow(RuntimeException::class, 'Only a leave type paid from earned leave credits accrues credits');
});

it('shows the reviewer what approving would leave without pay, and offers only a without-pay approval for a type filed without pay', function () {
    $manager = lfilActor();
    lfilType($manager, 'Vacation Leave');
    lfilType($manager, 'Unpaid Leave', ['filing' => 'UNPAID']);
    $employee = lfilEmployee();
    lfilCredit($employee, 'VACATION_LEAVE', 1);
    $short = lfilRequest($employee, 'VACATION_LEAVE', '2026-09-28', '2026-09-29');
    $unpaid = lfilRequest($employee, 'UNPAID_LEAVE', '2026-10-05', '2026-10-05');

    Livewire::test(Index::class)->call('show', $short->id)
        ->assertSeeHtml('data-pay-plan')
        ->assertSee("The employee's available Vacation Leave credits (1.000) cover 1 day of the 2 days; the other 1 day will be unpaid if approved.")
        ->assertSeeHtml('data-approve-paid');

    Livewire::test(Index::class)->call('show', $unpaid->id)
        ->assertSee('Approve as unpaid leave')
        ->assertDontSeeHtml('data-approve-paid');
});

it('lists my leave with set days on My Portal, with what is left this year', function () {
    $manager = lfilActor();
    lfilType($manager, 'Special Privilege Leave', ['filing' => 'YEARLY', 'allowance_days' => '3']);
    lfilType($manager, 'Paternity Leave', ['filing' => 'OCCASION', 'allowance_days' => '7']);
    $employee = lfilEmployee();
    $request = lfilRequest($employee, 'SPECIAL_PRIVILEGE_LEAVE', '2026-09-28', '2026-09-28');
    app(ReviewAbsence::class)->handle($manager, $request->id, 'APPROVED', true);
    lfilActor(['PORTAL'], allData: false, employeeId: $employee->id);

    Livewire::test(PortalPage::class)->set('tab', 'requests')
        ->assertSeeHtml('data-leave-allowances')
        ->assertSee('2 of 3 days left in 2026')
        ->assertSee('up to 7 days each time');
});

/*
|--------------------------------------------------------------------------
| The migrations and the starting setting
|--------------------------------------------------------------------------
*/

it('starts a new leave type filed as its name reads, which HR can change', function () {
    $named = new LeaveType;
    $named->forceFill(['code' => 'LF-LWOP', 'name' => 'Leave Without Pay'])->save();
    $other = new LeaveType;
    $other->forceFill(['code' => 'LF-BRV', 'name' => 'Bereavement Leave'])->save();

    expect($named->refresh()->filing)->toBe('UNPAID')
        ->and($other->refresh()->filing)->toBe('CREDITS');

    app(SaveLeaveTypeCredits::class)->handle(lfilActor(), $named->id, [
        'earns_credits' => false, 'unit' => 'DAYS', 'charge_late' => false, 'charge_absence' => false,
        'charge_order' => '0', 'below_zero_limit' => '0', 'filing' => 'CREDITS',
    ]);
    expect($named->refresh()->filing)->toBe('CREDITS');
});

it('adds how leave types are filed twice without error, setting "without pay" types to it, and drops it on the way down', function () {
    $migration = require base_path('database/migrations/2026_11_07_0001_add_filing_to_leave_types_table.php');
    $migration->down();
    Once::flush();
    expect(Schema::hasColumn('leave_types', 'filing'))->toBeFalse();
    DB::table('leave_types')->insert([
        ['id' => (string) Str::ulid(), 'code' => 'M-1', 'name' => 'Leave Without Pay', 'created_at' => now()->format('Y-m-d H:i:s.v')],
        ['id' => (string) Str::ulid(), 'code' => 'M-2', 'name' => 'Vacation Leave', 'created_at' => now()->format('Y-m-d H:i:s.v')],
    ]);

    $migration->up();
    $migration->up();

    expect(Schema::hasColumn('leave_types', 'filing'))->toBeTrue()
        ->and(Schema::hasColumn('leave_types', 'allowance_days'))->toBeTrue()
        ->and(Schema::hasColumn('leave_types', 'day_count'))->toBeTrue()
        ->and(DB::table('leave_types')->where('code', 'M-1')->value('filing'))->toBe('UNPAID')
        ->and(DB::table('leave_types')->where('code', 'M-2')->value('filing'))->toBe('CREDITS')
        ->and(DB::table('leave_types')->where('code', 'M-2')->value('day_count'))->toBe('WORKING');

    $requests = require base_path('database/migrations/2026_11_07_0002_add_unpaid_dates_to_leave_travel_requests_table.php');
    $requests->up();
    $requests->up();
    expect(Schema::hasColumn('leave_travel_requests', 'unpaid_dates'))->toBeTrue();
    Once::flush();
});
