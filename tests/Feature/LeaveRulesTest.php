<?php

declare(strict_types=1);

use App\Livewire\Leaves\Index;
use App\Livewire\Leaves\LeaveCredits;
use App\Livewire\Portal\PortalPage;
use App\Livewire\Records\LeaveCreditSettingsPage;
use App\Models\AttendanceLog;
use App\Models\AuditLog;
use App\Models\EmployeeProfile;
use App\Models\LeaveAcquisition;
use App\Models\LeaveCredit;
use App\Models\LeaveCreditEntry;
use App\Models\LeaveEarningPosting;
use App\Models\LeaveRule;
use App\Models\LeaveTravelRequest;
use App\Models\LeaveType;
use App\Models\Role;
use App\Models\RoleModuleGrant;
use App\Models\User;
use App\Modules\Timekeeping\Application\Commands\FileAbsence;
use App\Modules\Timekeeping\Application\Commands\ReviewAbsence;
use App\Modules\Timekeeping\Application\Commands\SaveLeaveRule;
use App\Modules\Timekeeping\Application\Commands\SaveLeaveTypeCredits;
use App\Modules\Timekeeping\Application\LeaveBalances;
use App\Modules\Timekeeping\Application\LeaveRules;
use App\Modules\Timekeeping\Application\PostLeaveEarnings;
use App\Modules\Timekeeping\Contracts\LeaveChargeSource;
use App\Support\Access;
use App\Support\ManilaClock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Once;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Support\ScheduleFixture;

/**
 * The leave rules: ONE rule per leave type and one general rule, the same
 * for every employee, changed in place by whoever manages the leave credit
 * settings and IN FORCE FROM THE DAY IT IS SAVED — no draft, no approval,
 * no back-dating. Underneath, each save is kept as that day's version, so
 * a past day still reads the rule it had. Before the first rule nobody has
 * terms. A type with a waiting period is acquired once, on a recorded day
 * — the months after the date hired, pushed back by whole days without pay
 * — and until that day it earns nothing, cannot be filed and is not
 * charged for attendance. A type may fall back on another when its own
 * credits run out.
 */

/** @param list<string> $modules */
function lrulActor(array $modules = ['LEAVES', 'RECORDS', 'PAYROLL'], bool $allData = true, ?string $employeeId = null): Access
{
    $role = new Role;
    $role->forceFill(['name' => 'Lrul '.Str::random(6), 'all_data' => $allData, 'is_system' => false])->save();
    foreach ($modules as $module) {
        $grant = new RoleModuleGrant;
        $grant->forceFill(['role_id' => $role->getKey(), 'module' => $module])->save();
    }
    test()->actingAs(User::factory()->create(['role_id' => $role->getKey(), 'employee_id' => $employeeId]));
    app()->forgetInstance(Access::class);

    return app(Access::class);
}

function lrulEmployee(string $hired = '2024-01-01'): EmployeeProfile
{
    $employee = new EmployeeProfile;
    $employee->forceFill([
        'employee_code' => 'LRUL-'.Str::random(8), 'first_name' => 'Rue', 'last_name' => 'Rule '.Str::random(4),
        'employment_status' => 'Regular', 'date_hired' => $hired, 'is_active' => true,
    ])->save();

    ScheduleFixture::standard($employee);

    return $employee;
}

/**
 * Vacation Leave (earns; charged for lateness and absences) and Sick Leave
 * (earns; charged for nothing).
 *
 * @return array<string, string> name => leave type id
 */
function lrulTypes(Access $access): array
{
    $ids = [];
    foreach ([['VL', 'Vacation Leave', true], ['SL', 'Sick Leave', false]] as [$code, $name, $charged]) {
        $type = new LeaveType;
        $type->forceFill(['code' => $code, 'name' => $name])->save();
        app(SaveLeaveTypeCredits::class)->handle($access, $type->id, ['earns_credits' => true, 'unit' => 'DAYS', 'charge_late' => $charged, 'charge_absence' => $charged, 'charge_order' => '1', 'below_zero_limit' => '0']);
        $ids[$name] = $type->id;
    }

    return $ids;
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function lrulTypeParams(array $overrides = []): array
{
    return array_merge([
        'waiting_months' => null, 'waiting_extends' => false, 'on_acquire' => 'ZERO',
        'earning' => ['amount' => '1.25', 'method' => 'FLAT', 'table' => [], 'partial' => 'FULL', 'cap' => null], 'fallback' => null,
    ], $overrides);
}

function lrulFreeze(string $manilaDay): void
{
    test()->travelTo(CarbonImmutable::parse($manilaDay.' 09:00:00', ManilaClock::TZ)->utc());
}

/**
 * Saves a rule ON `$manilaDay` — the only day it can take effect — leaving
 * the clock there.
 *
 * @param  array<string, mixed>  $params
 */
function lrulSaved(Access $access, ?string $typeId, string $manilaDay, array $params): string
{
    lrulFreeze($manilaDay);
    $id = app(SaveLeaveRule::class)->handle($access, $typeId, $params);
    if ($id === null) {
        throw new RuntimeException('The rule already said this.');
    }

    return $id;
}

/**
 * A full working day, 08:00 to 17:00, on each date.
 *
 * @param  list<string>  $dates
 */
function lrulPresent(EmployeeProfile $employee, array $dates): void
{
    foreach ($dates as $date) {
        foreach (['TIME_IN' => '08:00', 'TIME_OUT' => '17:00'] as $type => $time) {
            $punch = new AttendanceLog;
            $punch->forceFill([
                'employee_id' => (string) $employee->getKey(), 'log_type' => $type, 'source' => 'MANUAL',
                'is_geofenced' => true, 'is_anomaly' => false, 'logged_at' => ManilaClock::instant("{$date}T{$time}:00")->utc(),
            ])->save();
        }
    }
}

/**
 * Every Monday-to-Friday date of a range.
 *
 * @return list<string>
 */
function lrulWeekdays(string $from, string $to): array
{
    $dates = [];
    for ($day = CarbonImmutable::parse($from, 'UTC'); $day->format('Y-m-d') <= $to; $day = $day->addDay()) {
        if ($day->isWeekday()) {
            $dates[] = $day->format('Y-m-d');
        }
    }

    return $dates;
}

/*
|--------------------------------------------------------------------------
| One rule, in force from the day it is saved
|--------------------------------------------------------------------------
*/

it('saves a rule in force from today, changes it in place the same day, keeps each day\'s version, and refuses a holder without all data', function () {
    $access = lrulActor();
    $types = lrulTypes($access);
    $id = lrulSaved($access, $types['Vacation Leave'], '2026-06-01', lrulTypeParams(['waiting_months' => '6', 'waiting_extends' => true]));
    $first = LeaveRule::query()->findOrFail($id);
    expect($first->status)->toBe('APPROVED')->and($first->scope)->toBe('VACATION_LEAVE')
        ->and($first->effective_from->format('Y-m-d'))->toBe('2026-06-01')
        ->and($first->params['waiting_months'])->toBe(6)
        ->and($first->approved_by)->toBe((int) $access->userId)
        ->and(LeaveRules::book()->startsOn())->toBe('2026-06-01')
        ->and(AuditLog::query()->where('action', 'LEAVE_RULE_SAVED')->value('details'))->toContain('Vacation Leave rule from 2026-06-01')->toContain('acquired after 6 months');

    // A second change the same day replaces that day's version; saving what the rule already says writes nothing.
    $again = app(SaveLeaveRule::class)->handle($access, $types['Vacation Leave'], lrulTypeParams(['waiting_months' => '3']));
    expect($again)->toBe($id)->and(LeaveRule::query()->count())->toBe(1)
        ->and(LeaveRules::book()->typeRule('VACATION_LEAVE', '2026-06-01')->waitingMonths())->toBe(3)
        ->and(app(SaveLeaveRule::class)->handle($access, $types['Vacation Leave'], lrulTypeParams(['waiting_months' => '3'])))->toBeNull()
        ->and(AuditLog::query()->where('action', 'LEAVE_RULE_SAVED')->count())->toBe(2);

    // A change on a later day is that day's version: every earlier day still reads the rule it had.
    $later = lrulSaved($access, $types['Vacation Leave'], '2026-07-15', lrulTypeParams(['waiting_months' => '2']));
    expect($later)->not->toBe($id)->and(LeaveRule::query()->count())->toBe(2)
        ->and(LeaveRules::book()->typeRule('VACATION_LEAVE', '2026-05-31')->waitingMonths())->toBeNull()
        ->and(LeaveRules::book()->typeRule('VACATION_LEAVE', '2026-07-14')->waitingMonths())->toBe(3)
        ->and(LeaveRules::book()->typeRule('VACATION_LEAVE', '2026-07-15')->waitingMonths())->toBe(2);

    // Nothing dated after the day of a save outlives it: the rule saved is the rule from here on.
    $ahead = new LeaveRule;
    $ahead->forceFill(['scope' => 'VACATION_LEAVE', 'effective_from' => '2026-09-01', 'params' => lrulTypeParams(['waiting_months' => '9']), 'status' => 'APPROVED', 'approved_at' => CarbonImmutable::now('UTC')])->save();
    app(SaveLeaveRule::class)->handle($access, $types['Vacation Leave'], lrulTypeParams(['waiting_months' => '4']));
    expect(LeaveRule::query()->whereKey($ahead->id)->exists())->toBeFalse()
        ->and(LeaveRules::book()->typeRule('VACATION_LEAVE', '2026-12-01')->waitingMonths())->toBe(4);

    // A type's first save writes its version even when it says nothing: that is what puts the rules in force.
    $sick = app(SaveLeaveRule::class)->handle($access, $types['Sick Leave'], lrulTypeParams(['earning' => null]));
    expect($sick)->not->toBeNull()
        ->and(app(SaveLeaveRule::class)->handle($access, $types['Sick Leave'], lrulTypeParams(['earning' => null])))->toBeNull();

    // What is entered is validated, never judged against a minimum.
    expect(fn () => app(SaveLeaveRule::class)->handle($access, $types['Vacation Leave'], lrulTypeParams(['waiting_months' => '0'])))->toThrow(RuntimeException::class, '1 to 600 months')
        ->and(fn () => app(SaveLeaveRule::class)->handle($access, 'missing', lrulTypeParams()))->toThrow(RuntimeException::class, 'no longer exists');

    $before = LeaveRule::query()->count();
    $audits = AuditLog::query()->count();
    $viewer = lrulActor(['LEAVES', 'RECORDS'], allData: false);
    expect(fn () => app(SaveLeaveRule::class)->handle($viewer, $types['Sick Leave'], lrulTypeParams()))->toThrow(RuntimeException::class, 'organization-wide')
        ->and(LeaveRule::query()->count())->toBe($before)
        ->and(AuditLog::query()->count())->toBe($audits);
});

it('has no posting and charging rule: posting, days without pay and the charge switch are fixed, and marking a type is what charges it', function () {
    $access = lrulActor();
    $types = lrulTypes($access);
    lrulSaved($access, $types['Sick Leave'], '2026-01-01', lrulTypeParams());
    $policy = LeaveRules::book()->policyOn('2026-01-01');
    expect($policy->postingDay())->toBe('MONTH_END')
        ->and($policy->unpaidCounts())->toBe(['UNPAID_LEAVE', 'ABSENCE'])
        ->and($policy->chargesEnabled())->toBeTrue()
        ->and($policy->combine())->toBe('DAY')
        ->and($policy->chargeOrder('late'))->toBe(['VACATION_LEAVE'])
        ->and($policy->chargeOrder('absence'))->toBe(['VACATION_LEAVE'])
        ->and($policy->cashOutMaxPerYear())->toBeNull();

    // Un-marking the last chargeable type is what turns charging off.
    app(SaveLeaveTypeCredits::class)->handle($access, $types['Vacation Leave'], ['earns_credits' => true, 'unit' => 'DAYS', 'charge_late' => false, 'charge_absence' => false, 'charge_order' => '1', 'below_zero_limit' => '0']);
    expect(LeaveRules::book()->policyOn('2026-01-01')->chargesEnabled())->toBeFalse();

    // A version some earlier screen stored under a scope that is no leave type is not read.
    $stray = new LeaveRule;
    $stray->forceFill(['scope' => 'GENERAL', 'effective_from' => '2025-01-01', 'params' => ['charges' => ['enabled' => false]], 'status' => 'APPROVED', 'approved_at' => CarbonImmutable::now('UTC')])->save();
    expect(LeaveRules::book()->startsOn())->toBe('2026-01-01');

    Livewire::test(LeaveCreditSettingsPage::class)->assertDontSee('Posting and charging')->assertDontSee('Cash-out limit');
});

it('changes a rule from the Leave Settings page in one step, filled in with what is in force, and writes nothing for a viewer', function () {
    lrulFreeze('2026-08-10');
    $access = lrulActor();
    $types = lrulTypes($access);

    // The dialog opens on the rule as it stands; Save puts the change in force from today.
    Livewire::test(LeaveCreditSettingsPage::class)
        ->call('openRule', $types['Vacation Leave'])
        ->assertSet('ruleOpen', true)
        ->assertSet('ruleForm.waiting', false)
        ->assertSeeHtml('data-leave-rule-applies')
        ->set('ruleForm.waiting', true)
        ->set('ruleForm.waiting_months', '6')
        ->set('ruleForm.waiting_extends', true)
        ->set('ruleForm.on_acquire', 'GRANT')
        ->set('ruleForm.earns', true)
        ->set('ruleForm.amount', '1.25')
        ->call('saveRule')
        ->assertSet('ruleError', null)
        ->assertSet('ruleOpen', false);
    $rule = LeaveRule::query()->where('scope', 'VACATION_LEAVE')->sole();
    expect($rule->status)->toBe('APPROVED')->and($rule->effective_from->format('Y-m-d'))->toBe('2026-08-10')
        ->and($rule->params['on_acquire'])->toBe('GRANT')->and($rule->params['earning']['amount'])->toBe(1.25);

    // Opened again, the form shows what was saved; a refused change keeps the dialog open and writes nothing.
    Livewire::test(LeaveCreditSettingsPage::class)
        ->assertSee('Acquired after 6 months')
        ->call('openRule', $types['Vacation Leave'])
        ->assertSet('ruleForm.waiting', true)
        ->assertSet('ruleForm.waiting_months', '6')
        ->assertSet('ruleForm.on_acquire', 'GRANT')
        ->assertSet('ruleForm.amount', '1.250')
        ->set('ruleForm.waiting_months', '0')
        ->call('saveRule')
        ->assertSet('ruleOpen', true)
        ->assertSet('ruleError', 'Enter the waiting period as 1 to 600 months, or turn the waiting period off.');
    expect(LeaveRule::query()->count())->toBe(1);

    // Vacation Leave is marked to take lateness; the fixed table always converts it, so there is nothing to warn about.
    Livewire::test(LeaveCreditSettingsPage::class)
        ->assertDontSeeHtml('data-late-waits-for-table')
        ->assertDontSee('Not charged yet');

    // A viewer reads the rules on the page and changes nothing.
    $before = LeaveRule::query()->count();
    lrulActor(['RECORDS'], allData: false);
    Livewire::test(LeaveCreditSettingsPage::class)
        ->assertSee('Acquired after 6 months')
        ->assertDontSee('Leave rule…')
        ->call('openRule', $types['Sick Leave'])
        ->assertSet('ruleOpen', false)
        ->set('ruleForm.earns', true)
        ->set('ruleForm.amount', '2')
        ->call('saveRule');
    expect(LeaveRule::query()->count())->toBe($before);
});

it('lets a rule turn a leave type\'s earning on, with no second switch to set', function () {
    $access = lrulActor();
    $type = new LeaveType;
    $type->forceFill(['code' => 'SPL', 'name' => 'Special Privilege Leave'])->save();
    expect((bool) $type->refresh()->earns_credits)->toBeFalse();

    lrulSaved($access, $type->id, '2026-01-01', lrulTypeParams());
    expect((bool) $type->refresh()->earns_credits)->toBeTrue()
        ->and(LeaveRules::book()->policyOn('2026-01-01')->earning())->toHaveKey('SPECIAL_PRIVILEGE_LEAVE');
});

it('shows an employee why a leave cannot be filed yet, and files a short sick leave against vacation leave, from My Portal', function () {
    $manager = lrulActor();
    $types = lrulTypes($manager);
    $employee = lrulEmployee('2026-01-05');
    lrulSaved($manager, $types['Vacation Leave'], '2026-01-01', lrulTypeParams(['waiting_months' => '6', 'earning' => null]));
    lrulSaved($manager, $types['Sick Leave'], '2026-01-01', lrulTypeParams(['earning' => null, 'fallback' => 'VACATION_LEAVE']));
    foreach (['SICK_LEAVE' => 1.0, 'VACATION_LEAVE' => 5.0] as $token => $credits) {
        $credit = new LeaveCredit;
        $credit->forceFill(['employee_id' => $employee->id, 'year' => 2026, 'request_type' => $token, 'credits' => $credits])->save();
    }
    lrulFreeze('2026-03-01');
    lrulActor(['PORTAL'], allData: false, employeeId: $employee->id);

    // Vacation Leave waits six months: the form says so and keeps Submit off.
    Livewire::test(PortalPage::class)
        ->set('tab', 'requests')
        ->set('type', 'VACATION_LEAVE')->set('start', '2026-03-02')->set('end', '2026-03-02')
        ->assertSee('is not acquired yet')
        ->assertViewHas('submitDisabled', true);

    // Sick Leave has one credit for a three-day request; it falls back on
    // nothing yet, because Vacation Leave is not acquired: the request may
    // still be filed, and the form says two days would be without pay.
    Livewire::test(PortalPage::class)
        ->set('tab', 'requests')
        ->set('type', 'SICK_LEAVE')->set('start', '2026-03-02')->set('end', '2026-03-04')
        ->assertSeeHtml('data-credit-notice')
        ->assertSee('cover 1 day of the 3 days; the other 2 days will be unpaid if approved.')
        ->assertViewHas('submitDisabled', false);

    // Once Vacation Leave is acquired, the same request is allowed and the form says where the rest comes from.
    $acquired = new LeaveAcquisition;
    $acquired->forceFill(['employee_id' => $employee->id, 'request_type' => 'VACATION_LEAVE', 'acquired_on' => '2026-02-01', 'basis' => [], 'granted' => 0])->save();
    $portal = Livewire::test(PortalPage::class)
        ->set('tab', 'requests')
        ->set('type', 'SICK_LEAVE')->set('start', '2026-03-02')->set('end', '2026-03-04');
    $portal->assertSeeHtml('data-credit-fallback');
    $portal->assertDontSeeHtml('data-credit-notice');
    $portal->assertViewHas('submitDisabled', false);
    $portal->call('file');
    $request = LeaveTravelRequest::query()->where('employee_id', $employee->id)->firstOrFail();
    expect($request->request_type)->toBe('SICK_LEAVE');

    // The reviewer sees, before deciding, what approving will draw from Vacation Leave.
    lrulActor();
    Livewire::test(Index::class)->call('show', $request->id)->assertSeeHtml('data-fallback-line')->assertSee('from Vacation Leave');
});

/*
|--------------------------------------------------------------------------
| The rules apply to every employee
|--------------------------------------------------------------------------
*/

it('gives nobody terms before a rule is in force, and everybody the same terms from that day', function () {
    $access = lrulActor();
    $types = lrulTypes($access);
    $first = lrulEmployee();
    $second = lrulEmployee();
    lrulSaved($access, $types['Vacation Leave'], '2026-06-01', lrulTypeParams());

    expect(LeaveRules::terms([$first->id, $second->id], '2026-05-31'))->toBe([])
        ->and(LeaveRules::policyFor($first->id, '2026-05-31')->earning())->toBe([]);

    $after = LeaveRules::terms([$first->id, $second->id], '2026-06-01');
    expect($after[$first->id]->policy->earning()['VACATION_LEAVE']['amount'])->toBe(1.25)
        ->and($after[$second->id]->policy->earning()['VACATION_LEAVE']['amount'])->toBe(1.25)
        ->and($after[$first->id]->acquired)->toBe([]);

    // Before the rules' first day the earning job posts nothing for anyone.
    lrulFreeze('2026-05-20');
    expect(app(PostLeaveEarnings::class)->run('2026-05-20')['posted'])->toBe(0)
        ->and(LeaveEarningPosting::query()->count())->toBe(0);
});

it('posts a month for every employee under the rules, once', function () {
    $access = lrulActor();
    $types = lrulTypes($access);
    $first = lrulEmployee();
    $second = lrulEmployee();
    $version = lrulSaved($access, $types['Vacation Leave'], '2026-02-01', lrulTypeParams());
    lrulFreeze('2026-03-02');

    $result = app(PostLeaveEarnings::class)->run('2026-03-02');
    expect($result['posted'])->toBe(2)->and($result['employees'])->toBe(2)
        ->and(LeaveEarningPosting::query()->where('period', '2026-02')->where('request_type', 'VACATION_LEAVE')->pluck('employee_id')->sort()->values()->all())
        ->toBe(collect([$first->id, $second->id])->sort()->values()->all())
        ->and(LeaveEarningPosting::query()->where('employee_id', $first->id)->value('policy_id'))->toBe($version)
        ->and((float) LeaveCredit::query()->where('employee_id', $second->id)->where('year', 2026)->where('request_type', 'VACATION_LEAVE')->value('credits'))->toBe(1.25)
        // Sick Leave has no rule: it earns nothing.
        ->and(LeaveEarningPosting::query()->where('request_type', 'SICK_LEAVE')->count())->toBe(0);

    expect(app(PostLeaveEarnings::class)->run('2026-03-02')['posted'])->toBe(0)
        ->and(LeaveEarningPosting::query()->count())->toBe(2);
});

/*
|--------------------------------------------------------------------------
| The waiting period
|--------------------------------------------------------------------------
*/

it('acquires a waiting leave type after its months, pushed back by the days without pay, and earns from that day', function () {
    $access = lrulActor();
    $types = lrulTypes($access);
    $employee = lrulEmployee('2026-01-05');
    // Present every working day from the date hired except two absences in January.
    lrulPresent($employee, array_values(array_diff(lrulWeekdays('2026-01-05', '2026-02-27'), ['2026-01-12', '2026-01-13'])));
    lrulSaved($access, $types['Vacation Leave'], '2026-01-01', lrulTypeParams(['waiting_months' => '1', 'waiting_extends' => true, 'on_acquire' => 'GRANT']));

    // One month from 5 January is 5 February; two days without pay make it the 7th.
    lrulFreeze('2026-02-06');
    expect(app(PostLeaveEarnings::class)->run('2026-02-06')['acquired'])->toBe(0)
        ->and(LeaveAcquisition::query()->count())->toBe(0);

    lrulFreeze('2026-02-10');
    $result = app(PostLeaveEarnings::class)->run('2026-02-10');
    $row = LeaveAcquisition::query()->where('employee_id', $employee->id)->where('request_type', 'VACATION_LEAVE')->firstOrFail();
    expect($result['acquired'])->toBe(1)
        ->and($row->acquired_on->format('Y-m-d'))->toBe('2026-02-07')
        ->and($row->basis['extension_days'])->toBe(2)
        ->and($row->granted)->toBe(1.25)
        ->and(LeaveCreditEntry::query()->whereKey($row->entry_id)->value('kind'))->toBe('ACCRUAL')
        ->and(AuditLog::query()->where('action', 'LEAVE_EARNINGS_POSTED')->where('entity_id', $employee->id)->value('details'))->toContain('Vacation Leave acquired on 2026-02-07')->toContain('extended 2 days');

    // A second run records nothing more: the day is fixed.
    expect(app(PostLeaveEarnings::class)->run('2026-02-10')['acquired'])->toBe(0)
        ->and(LeaveAcquisition::query()->count())->toBe(1);

    // January earned nothing (not acquired by its last day); February earns, from the month the day falls in.
    lrulFreeze('2026-03-02');
    app(PostLeaveEarnings::class)->run('2026-03-02');
    expect(LeaveEarningPosting::query()->where('employee_id', $employee->id)->pluck('period')->all())->toBe(['2026-02'])
        ->and((float) LeaveCredit::query()->where('employee_id', $employee->id)->where('year', 2026)->where('request_type', 'VACATION_LEAVE')->value('credits'))->toBe(2.5);

    // Leave Management's Leave Credits tab shows where the employee stands.
    Livewire::test(LeaveCredits::class)->assertSeeHtml('data-leave-standing')->assertSee('Vacation Leave from Feb 07, 2026');
    Livewire::test(Index::class)->assertDontSee('Leave Earning')->assertDontSee('Leave Policies');
});

it('refuses a filing before the leave is acquired, and charges attendance to it only from that day', function () {
    $access = lrulActor();
    $types = lrulTypes($access);
    $employee = lrulEmployee('2026-01-05');
    $newer = lrulEmployee('2026-02-02');
    lrulSaved($access, $types['Vacation Leave'], '2026-01-01', lrulTypeParams(['waiting_months' => '1']));
    lrulFreeze('2026-02-10');
    app(PostLeaveEarnings::class)->run('2026-02-10');
    expect(LeaveAcquisition::query()->where('employee_id', $employee->id)->value('acquired_on')?->format('Y-m-d'))->toBe('2026-02-05')
        ->and(LeaveAcquisition::query()->where('employee_id', $newer->id)->exists())->toBeFalse();

    $before = LeaveTravelRequest::query()->count();
    expect(fn () => app(FileAbsence::class)->forEmployee($access, $employee->id, 'VACATION_LEAVE', 'WHOLE_DAY', '2026-02-03', '2026-02-03', ''))->toThrow(RuntimeException::class, 'is available from')
        ->and(fn () => app(FileAbsence::class)->forEmployee($access, $newer->id, 'VACATION_LEAVE', 'WHOLE_DAY', '2026-02-09', '2026-02-09', ''))->toThrow(RuntimeException::class, 'is not acquired yet')
        ->and(LeaveTravelRequest::query()->count())->toBe($before);

    // Payroll's charge facts: the acquired type carries its day; the unacquired one is not chargeable at all.
    $facts = app(LeaveChargeSource::class)->forPayroll($access, [$employee->id, $newer->id], '2026-02-01', '2026-02-15');
    expect($facts[$employee->id]['balances']['VACATION_LEAVE']['available_from'])->toBe('2026-02-05')
        ->and($facts[$employee->id]['charges']['absence'])->toBe(['VACATION_LEAVE'])
        ->and($facts)->not->toHaveKey($newer->id);
});

/*
|--------------------------------------------------------------------------
| Falling back on another leave type
|--------------------------------------------------------------------------
*/

it('draws what a sick leave lacks from vacation leave when its rule falls back on it', function () {
    $access = lrulActor();
    $types = lrulTypes($access);
    $employee = lrulEmployee();
    lrulSaved($access, $types['Sick Leave'], '2026-01-01', lrulTypeParams(['earning' => null, 'fallback' => 'VACATION_LEAVE']));
    foreach (['SICK_LEAVE' => 1.0, 'VACATION_LEAVE' => 5.0] as $token => $credits) {
        $credit = new LeaveCredit;
        $credit->forceFill(['employee_id' => $employee->id, 'year' => 2026, 'request_type' => $token, 'credits' => $credits])->save();
    }
    lrulFreeze('2026-03-01');

    // Three working days against one sick credit: allowed, because vacation leave covers the rest.
    app(FileAbsence::class)->forEmployee($access, $employee->id, 'SICK_LEAVE', 'WHOLE_DAY', '2026-03-02', '2026-03-04', 'Flu');
    $request = LeaveTravelRequest::query()->where('employee_id', $employee->id)->firstOrFail();
    $outcome = app(ReviewAbsence::class)->handle($access, $request->id, 'APPROVED', true);

    $moves = LeaveCreditEntry::query()->where('employee_id', $employee->id)->where('kind', 'ADJUSTMENT')->pluck('days', 'request_type')->map(static fn (mixed $days): float => (float) $days)->all();
    expect($outcome)->toContain('drawn from Vacation Leave')
        ->and($moves)->toEqual(['VACATION_LEAVE' => -2.0, 'SICK_LEAVE' => 2.0])
        ->and((float) LeaveCredit::query()->where('employee_id', $employee->id)->where('request_type', 'VACATION_LEAVE')->value('credits'))->toBe(3.0)
        ->and((float) LeaveCredit::query()->where('employee_id', $employee->id)->where('request_type', 'SICK_LEAVE')->value('credits'))->toBe(3.0)
        ->and((float) $request->refresh()->credit_days)->toBe(3.0);

    // Vacation leave never falls back on sick leave: it has no such rule, so
    // its 3 credits cover 3 of 5 days and the rest would be without pay.
    expect(app(FileAbsence::class)->forEmployee($access, $employee->id, 'VACATION_LEAVE', 'WHOLE_DAY', '2026-04-06', '2026-04-10', ''))
        ->toBe("The employee's available Vacation Leave credits (3.000) cover 3 days of the 5 days; the other 2 days will be unpaid if approved.");
});

it('pays a short leave as far as its own credits and its fallback reach, and leaves the rest of it without pay', function () {
    $access = lrulActor();
    $types = lrulTypes($access);
    $employee = lrulEmployee();
    lrulSaved($access, $types['Sick Leave'], '2026-01-01', lrulTypeParams(['earning' => null, 'fallback' => 'VACATION_LEAVE']));
    foreach (['SICK_LEAVE' => 1.0, 'VACATION_LEAVE' => 1.0] as $token => $credits) {
        $credit = new LeaveCredit;
        $credit->forceFill(['employee_id' => $employee->id, 'year' => 2026, 'request_type' => $token, 'credits' => $credits])->save();
    }
    lrulFreeze('2026-03-01');

    // Monday to Friday against one sick and one vacation credit.
    app(FileAbsence::class)->forEmployee($access, $employee->id, 'SICK_LEAVE', 'WHOLE_DAY', '2026-03-02', '2026-03-06', 'Flu');
    $request = LeaveTravelRequest::query()->where('employee_id', $employee->id)->firstOrFail();
    $outcome = app(ReviewAbsence::class)->handle($access, $request->id, 'APPROVED', true);

    $request->refresh();
    expect($outcome)->toStartWith('approved: 2 days paid, 3 days unpaid (not enough Sick Leave credits)')
        ->and($request->is_paid)->toBeTrue()
        ->and($request->credit_days)->toBe(2.0)
        ->and($request->credit_dates)->toBe(['2026-03-02', '2026-03-03'])
        ->and($request->unpaid_dates)->toBe(['2026-03-04', '2026-03-05', '2026-03-06'])
        ->and((float) LeaveCredit::query()->where('employee_id', $employee->id)->where('request_type', 'VACATION_LEAVE')->value('credits'))->toBe(0.0)
        ->and(LeaveBalances::balance($employee->id, 2026, 'SICK_LEAVE'))->toBe(0.0);
});

/*
|--------------------------------------------------------------------------
| The migration
|--------------------------------------------------------------------------
*/

it('creates the leave rule tables twice without error and drops them on the way down', function () {
    $migration = require base_path('database/migrations/2026_11_06_0001_create_leave_rules_tables.php');
    $migration->up();
    $migration->up();
    expect(Schema::hasTable('leave_rules'))->toBeTrue()->and(Schema::hasTable('leave_acquisitions'))->toBeTrue()
        ->and(Schema::hasIndex('leave_rules', 'lrule_scope_from_unq'))->toBeTrue()
        ->and(Schema::hasIndex('leave_acquisitions', 'lacq_employee_type_unq'))->toBeTrue();

    $migration->down();
    $migration->down();
    Once::flush();
    try {
        expect(Schema::hasTable('leave_rules'))->toBeFalse()->and(Schema::hasTable('leave_acquisitions'))->toBeFalse()
            // With no tables there are no rules, and nothing reads them.
            ->and(LeaveRules::ready())->toBeFalse()
            ->and(LeaveRules::book()->startsOn())->toBeNull();
    } finally {
        $migration->up();
        Once::flush();
    }
});

/*
|--------------------------------------------------------------------------
| Adding a leave type in one step
|--------------------------------------------------------------------------
*/

it('adds a leave type with its rule and settings from one dialog, and leaves nothing behind when the rule is refused', function () {
    lrulFreeze('2026-08-10');
    lrulActor();

    // Name, rule and settings in one form and one button; the rule applies from today.
    Livewire::test(LeaveCreditSettingsPage::class)
        ->call('openAdd')
        ->assertSet('addOpen', true)
        ->assertDontSeeHtml('aria-label="Rule takes effect from"')
        ->set('newName', 'Special Privilege Leave')
        ->set('newDescription', 'Three days a year')
        ->set('ruleForm.waiting', true)
        ->set('ruleForm.waiting_months', '6')
        ->set('ruleForm.earns', true)
        ->set('ruleForm.amount', '0.25')
        ->set('typeForm.charge_absence', true)
        ->set('typeForm.year_end', 'LAPSE')
        ->call('saveNewType')
        ->assertSet('addError', null)
        ->assertSet('addOpen', false);
    $type = LeaveType::query()->where('name', 'Special Privilege Leave')->firstOrFail();
    $rule = LeaveRule::query()->where('scope', 'SPECIAL_PRIVILEGE_LEAVE')->sole();
    expect($type->description)->toBe('Three days a year')
        ->and((bool) $type->charge_absence)->toBeTrue()->and($type->year_end)->toBe('LAPSE')
        ->and($rule->status)->toBe('APPROVED')->and($rule->effective_from->format('Y-m-d'))->toBe('2026-08-10')
        ->and($rule->params['waiting_months'])->toBe(6)
        ->and((bool) $type->refresh()->earns_credits)->toBeTrue();

    // A rule left as it starts is what a type with no rule does: the type is added and no rule is written.
    Livewire::test(LeaveCreditSettingsPage::class)->call('openAdd')->set('newName', 'Study Leave')->call('saveNewType')->assertSet('addError', null);
    expect(LeaveType::query()->where('name', 'Study Leave')->exists())->toBeTrue()
        ->and(LeaveRule::query()->where('scope', 'STUDY_LEAVE')->exists())->toBeFalse();

    // A refused rule leaves no leave type behind.
    $types = LeaveType::query()->count();
    Livewire::test(LeaveCreditSettingsPage::class)->call('openAdd')->set('newName', 'Calamity Leave')
        ->set('ruleForm.waiting', true)->set('ruleForm.waiting_months', '0')
        ->call('saveNewType')->assertSet('addOpen', true)->assertSee('1 to 600 months');
    expect(LeaveType::query()->count())->toBe($types);

    // Rename and delete from the same page; a type with a rule behind it is refused.
    $study = LeaveType::query()->where('name', 'Study Leave')->firstOrFail();
    Livewire::test(LeaveCreditSettingsPage::class)->call('openRename', $study->id)->set('newName', 'Study Leave (Bar)')->call('saveRename')->assertSet('renameOpen', false);
    expect($study->refresh()->name)->toBe('Study Leave (Bar)');
    Livewire::test(LeaveCreditSettingsPage::class)->call('askDelete', $study->id)->assertSet('deleteOpen', true)->call('confirmDelete');
    Livewire::test(LeaveCreditSettingsPage::class)->call('askDelete', $type->id)->call('confirmDelete');
    expect(LeaveType::query()->whereKey($study->id)->exists())->toBeFalse()
        ->and(LeaveType::query()->whereKey($type->id)->exists())->toBeTrue();

    // A leave manager without Records access sees the types but cannot add, rename or delete them.
    $before = LeaveType::query()->count();
    lrulActor(['LEAVES']);
    Livewire::test(LeaveCreditSettingsPage::class)->assertDontSee('Add leave type')
        ->call('openAdd')->assertSet('addOpen', false)
        ->set('newName', 'Sneaky Leave')->call('saveNewType')
        ->call('askDelete', $type->id)->assertSet('deleteOpen', false);
    expect(LeaveType::query()->count())->toBe($before);
});
