<?php

declare(strict_types=1);

use App\Livewire\Leaves\Index;
use App\Livewire\Leaves\LeavePolicies;
use App\Models\AuditLog;
use App\Models\EmployeeLeavePolicy;
use App\Models\EmployeeProfile;
use App\Models\LeaveCredit;
use App\Models\LeaveCreditEntry;
use App\Models\LeaveEarningPosting;
use App\Models\LeaveType;
use App\Models\Role;
use App\Models\RoleModuleGrant;
use App\Models\User;
use App\Modules\Timekeeping\Application\Commands\ApproveLeavePolicy;
use App\Modules\Timekeeping\Application\Commands\DiscardLeavePolicy;
use App\Modules\Timekeeping\Application\Commands\SaveLeavePolicy;
use App\Modules\Timekeeping\Application\Commands\SaveLeaveTypeCredits;
use App\Modules\Timekeeping\Application\LeavePolicyStore;
use App\Modules\Timekeeping\Application\PostLeaveEarnings;
use App\Modules\Timekeeping\Application\Queries\LeavePolicyRecords;
use App\Support\Access;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Livewire\Livewire;

/**
 * Leave policies and the month-end earning. An employee's policy is a dated
 * version prepared as a draft by one all-data LEAVES holder and approved by
 * ANOTHER, never by the employee, and never changed once approved; the
 * approved version in force on a date decides. The earning job posts every
 * month whose posting day has passed exactly once per employee and leave
 * type — an ACCRUAL ledger entry and a posting row — and closes a year by
 * the year-end rule when it reaches January; a second run writes nothing.
 */

/** @param list<string> $modules */
function lpolActor(array $modules = ['LEAVES'], bool $allData = true, ?string $employeeId = null): User
{
    $role = new Role;
    $role->forceFill(['name' => 'Lpol '.Str::random(6), 'all_data' => $allData, 'is_system' => false])->save();
    foreach ($modules as $module) {
        $grant = new RoleModuleGrant;
        $grant->forceFill(['role_id' => $role->getKey(), 'module' => $module])->save();
    }
    $user = User::factory()->create(['role_id' => $role->getKey(), 'employee_id' => $employeeId]);
    test()->actingAs($user);
    app()->forgetInstance(Access::class);

    return $user;
}

function lpolEmployee(string $hired = '2024-01-01', ?string $resigned = null): EmployeeProfile
{
    $employee = new EmployeeProfile;
    $employee->forceFill([
        'employee_code' => 'LPOL-'.Str::random(8), 'first_name' => 'Pat', 'last_name' => 'Policy '.Str::random(4),
        'employment_status' => 'Regular', 'date_hired' => $hired, 'date_resigned' => $resigned, 'is_active' => $resigned === null,
    ])->save();

    return $employee;
}

/** Vacation Leave earns and is charged; Sick Leave earns, charged for absences only. */
function lpolTypes(Access $access): void
{
    foreach ([['VL', 'Vacation Leave', true, '1'], ['SL', 'Sick Leave', false, '2']] as [$code, $name, $late, $order]) {
        $type = new LeaveType;
        $type->forceFill(['code' => $code, 'name' => $name])->save();
        app(SaveLeaveTypeCredits::class)->handle($access, $type->id, ['earns_credits' => true, 'unit' => 'DAYS', 'charge_late' => $late, 'charge_absence' => true, 'charge_order' => $order, 'below_zero_limit' => '0']);
    }
}

/**
 * @param  array<string, mixed>  $vacation
 * @param  array<string, mixed>  $sick
 * @return array<string, mixed>
 */
function lpolParams(array $vacation = [], array $sick = []): array
{
    return [
        'posting_day' => 'MONTH_END', 'unpaid_counts' => ['UNPAID_LEAVE', 'ABSENCE'],
        'earning' => [
            'VACATION_LEAVE' => array_merge(['amount' => '1.25', 'method' => 'FLAT', 'table' => [], 'partial' => 'PRO_RATA', 'cap' => null], $vacation),
            'SICK_LEAVE' => array_merge(['amount' => '1.25', 'method' => 'PRO_RATA', 'table' => [], 'partial' => 'PRO_RATA', 'cap' => null], $sick),
        ],
        'charges' => ['enabled' => true, 'combine' => 'DAY', 'late' => ['VACATION_LEAVE'], 'absence' => ['VACATION_LEAVE', 'SICK_LEAVE']],
    ];
}

/**
 * A draft prepared by one manager and approved by a second; the second is left signed in.
 *
 * @param  array<string, mixed>  $params
 */
function lpolApproved(EmployeeProfile $employee, string $from, array $params): EmployeeLeavePolicy
{
    lpolActor();
    $id = app(SaveLeavePolicy::class)->handle(app(Access::class), $employee->id, $from, $params, 'Test policy');
    lpolActor();
    app(ApproveLeavePolicy::class)->handle(app(Access::class), $id);

    return EmployeeLeavePolicy::query()->findOrFail($id);
}

function lpolFreeze(string $manilaDay): void
{
    test()->travelTo(CarbonImmutable::parse($manilaDay.' 09:00:00', 'Asia/Manila')->utc());
}

/*
|--------------------------------------------------------------------------
| Versions
|--------------------------------------------------------------------------
*/

it('prepares a draft, lets the preparer approve it, and resolves the version in force by date', function () {
    $employee = lpolEmployee();
    $preparer = lpolActor();
    lpolTypes(app(Access::class));
    $id = app(SaveLeavePolicy::class)->handle(app(Access::class), $employee->id, '2026-09-01', lpolParams(), 'Regularized');
    $draft = EmployeeLeavePolicy::query()->findOrFail($id);
    expect($draft->status)->toBe('DRAFT')->and($draft->prepared_by)->toBe((int) $preparer->id)
        ->and(LeavePolicyStore::inForce($employee->id, '2026-09-15'))->toBeNull()
        ->and(AuditLog::query()->where('action', 'LEAVE_POLICY_SAVED')->value('details'))->toContain('leave policy draft from 2026-09-01')->toContain('(Regularized)');

    // The preparer approves their own draft: one administrator runs the whole flow.
    app(ApproveLeavePolicy::class)->handle(app(Access::class), $id);
    $approved = $draft->refresh();
    expect($approved->status)->toBe('APPROVED')->and($approved->approved_by_name)->not->toBeNull()
        ->and(AuditLog::query()->where('action', 'LEAVE_POLICY_APPROVED')->count())->toBe(1)
        ->and(LeavePolicyStore::inForce($employee->id, '2026-08-31'))->toBeNull()
        ->and(LeavePolicyStore::inForce($employee->id, '2026-09-01')?->id)->toBe($id);

    // Approved versions never change; a later version supersedes from its date.
    expect(fn () => app(SaveLeavePolicy::class)->handle(app(Access::class), $employee->id, '2026-09-01', lpolParams(), 'Again'))->toThrow(RuntimeException::class, 'never changes')
        ->and(fn () => app(DiscardLeavePolicy::class)->handle(app(Access::class), $id))->toThrow(RuntimeException::class, 'never deleted');
    $later = app(SaveLeavePolicy::class)->handle(app(Access::class), $employee->id, '2026-12-01', lpolParams(['amount' => '1.5']), 'Promoted');
    lpolActor();
    app(ApproveLeavePolicy::class)->handle(app(Access::class), $later);
    expect(LeavePolicyStore::inForce($employee->id, '2026-11-30')?->id)->toBe($id)
        ->and(LeavePolicyStore::inForce($employee->id, '2026-12-01')?->id)->toBe($later)
        ->and(LeavePolicyStore::policy(LeavePolicyStore::inForce($employee->id, '2026-12-01'))->earning()['VACATION_LEAVE']['amount'])->toBe(1.5);
});

it('refuses a holder without all data and a policy over types that cannot earn, and lets an employee manage their own policy', function () {
    $employee = lpolEmployee();
    lpolActor();
    lpolTypes(app(Access::class));

    lpolActor(['LEAVES'], allData: false);
    expect(fn () => app(SaveLeavePolicy::class)->handle(app(Access::class), $employee->id, '2026-09-01', lpolParams(), 'x'))->toThrow(RuntimeException::class, 'organization-wide');

    // An all-data holder linked to the employee prepares and approves their own policy.
    lpolActor(['LEAVES'], allData: true, employeeId: $employee->id);
    $id = app(SaveLeavePolicy::class)->handle(app(Access::class), $employee->id, '2026-09-01', lpolParams(), 'x');
    app(ApproveLeavePolicy::class)->handle(app(Access::class), $id);
    expect(EmployeeLeavePolicy::query()->findOrFail($id)->status)->toBe('APPROVED');

    lpolActor();
    $bad = lpolParams();
    $bad['earning']['SPECIAL'] = $bad['earning']['SICK_LEAVE'];
    expect(fn () => app(SaveLeavePolicy::class)->handle(app(Access::class), $employee->id, '2026-10-01', $bad, 'x'))->toThrow(RuntimeException::class, 'cannot earn credits')
        ->and(fn () => app(SaveLeavePolicy::class)->handle(app(Access::class), $employee->id, 'soon', lpolParams(), 'x'))->toThrow(RuntimeException::class, 'Choose the date')
        ->and(fn () => app(SaveLeavePolicy::class)->handle(app(Access::class), $employee->id, '2026-10-01', lpolParams(), ''))->toThrow(RuntimeException::class, 'Say why');
    expect(EmployeeLeavePolicy::query()->count())->toBe(1)
        ->and(fn () => app(DiscardLeavePolicy::class)->handle(app(Access::class), $id))->toThrow(RuntimeException::class, 'never deleted');
});

/*
|--------------------------------------------------------------------------
| The earning job
|--------------------------------------------------------------------------
*/

it('posts each ended month once per leave type, from the policy\'s first month, and a second run writes nothing', function () {
    lpolFreeze('2026-11-05');
    $employee = lpolEmployee();
    lpolActor();
    lpolTypes(app(Access::class));
    $policy = lpolApproved($employee, '2026-09-01', lpolParams());

    // The schedule's run: no actor, "System (scheduled)" on the audit row.
    Artisan::call('leave:post-earnings');
    expect(Artisan::output())->toContain('Leave earning posted for 2 month-employees (1 employees).');

    $postings = LeaveEarningPosting::query()->where('employee_id', $employee->id)->orderBy('period')->orderBy('request_type')->get();
    expect($postings->pluck('period')->all())->toBe(['2026-09', '2026-09', '2026-10', '2026-10'])
        ->and($postings->pluck('policy_id')->unique()->all())->toBe([$policy->id]);
    // September 2026 has 22 weekdays: Vacation Leave is flat, Sick Leave pro-rata to the days present — none, with no punches.
    $vl = $postings->first(fn ($p) => $p->period === '2026-09' && $p->request_type === 'VACATION_LEAVE');
    $sl = $postings->first(fn ($p) => $p->period === '2026-09' && $p->request_type === 'SICK_LEAVE');
    expect($vl->days)->toBe(1.25)->and($vl->entry_id)->not->toBeNull()
        ->and($vl->inputs['scheduled'])->toBe(22)->and($vl->inputs['employed'])->toBe(22)->and($vl->inputs['basis'])->toBe('1.25 flat')
        ->and($sl->days)->toBe(0.0)->and($sl->entry_id)->toBeNull()->and($sl->inputs['present'])->toBe(0);

    expect(LeaveCredit::query()->where('employee_id', $employee->id)->where('year', 2026)->where('request_type', 'VACATION_LEAVE')->value('credits'))->toBe(2.5)
        ->and(LeaveCreditEntry::query()->where('employee_id', $employee->id)->where('kind', 'ACCRUAL')->count())->toBe(2)
        ->and(LeaveCreditEntry::query()->whereKey($vl->entry_id)->value('note'))->toBe('Earned for 2026-09: 1.25 flat')
        ->and(AuditLog::query()->where('action', 'LEAVE_EARNINGS_POSTED')->where('actor_name', 'System (scheduled)')->count())->toBe(2);

    // Idempotent: the same run again, and the leave manager's "Post now", both post nothing more.
    Artisan::call('leave:post-earnings');
    expect(Artisan::output())->toContain('posted for 0 month-employees');
    $result = app(PostLeaveEarnings::class)->run('2026-11-05', app(Access::class));
    expect($result['posted'])->toBe(0)
        ->and(LeaveEarningPosting::query()->count())->toBe(4)
        ->and(LeaveCreditEntry::query()->where('kind', 'ACCRUAL')->count())->toBe(2);

    // November is not due until its posting day has passed.
    expect(app(PostLeaveEarnings::class)->run('2026-11-30')['posted'])->toBe(0)
        ->and(app(PostLeaveEarnings::class)->run('2026-12-01')['posted'])->toBe(1);
});

it('closes the old year once December is posted, carrying every balance whole, and stops at the month an employee left', function () {
    lpolFreeze('2026-02-10');
    $employee = lpolEmployee('2025-11-17', '2026-01-20');
    lpolActor();
    lpolTypes(app(Access::class));
    // Sick Leave: 1.25 flat; both types carry their whole balance — credits never lapse.
    lpolApproved($employee, '2025-11-01', lpolParams([], ['method' => 'FLAT', 'partial' => 'FULL']));
    // An existing allocation for 2025 so the year-end has something to carry.
    $credit = new LeaveCredit;
    $credit->forceFill(['employee_id' => $employee->id, 'year' => 2025, 'request_type' => 'SICK_LEAVE', 'credits' => 10.0])->save();

    $result = app(PostLeaveEarnings::class)->run('2026-02-10', app(Access::class));
    // November 2025 (part month, hired on the 17th), December 2025, January 2026 (left on the 20th); February is not due.
    expect($result['posted'])->toBe(3)->and($result['employees'])->toBe(1);
    $byPeriod = LeaveEarningPosting::query()->where('employee_id', $employee->id)->get()->groupBy('period');
    expect($byPeriod->keys()->sort()->values()->all())->toBe(['2025-11', '2025-12', '2025-YE', '2026-01']);

    // November: Vacation Leave pro-rata to the days employed (10 of 20 weekdays), Sick Leave FULL.
    $nov = $byPeriod['2025-11']->keyBy('request_type');
    expect($nov['VACATION_LEAVE']->days)->toBe(0.625)->and($nov['SICK_LEAVE']->days)->toBe(1.25)->and($nov['VACATION_LEAVE']->inputs['employed'])->toBe(10);

    // Year end 2025: Sick Leave closes at 10 + 1.25 + 1.25 = 12.5 and carries all of it; Vacation Leave carries its 0.625 + 1.25. Nothing lapses.
    $ye = $byPeriod['2025-YE']->keyBy('request_type');
    expect($ye['SICK_LEAVE']->days)->toBe(12.5)->and($ye['SICK_LEAVE']->inputs)->toEqual(['closing' => 12.5, 'carry' => 12.5, 'lapsed' => 0.0])
        ->and($ye['VACATION_LEAVE']->days)->toBe(1.875)
        ->and(LeaveCreditEntry::query()->where('employee_id', $employee->id)->where('kind', 'LAPSE')->count())->toBe(0)
        ->and(LeaveCreditEntry::query()->where('employee_id', $employee->id)->where('kind', 'CARRY_FORWARD')->where('year', 2026)->sum('days'))->toEqual(14.375)
        ->and(LeaveCredit::query()->where('employee_id', $employee->id)->where('year', 2025)->where('request_type', 'SICK_LEAVE')->value('credits'))->toBe(12.5)
        // 2026 Sick Leave: the 12.5 carried plus January's 1.25.
        ->and(LeaveCredit::query()->where('employee_id', $employee->id)->where('year', 2026)->where('request_type', 'SICK_LEAVE')->value('credits'))->toBe(13.75);

    // Nothing after the month the employee left, and a second run adds nothing.
    expect(app(PostLeaveEarnings::class)->run('2026-04-10', app(Access::class))['posted'])->toBe(0)
        ->and(LeaveEarningPosting::query()->count())->toBe(8)
        ->and(AuditLog::query()->where('action', 'LEAVE_EARNINGS_POSTED')->whereNotNull('actor_id')->count())->toBe(4);
});

it('closes the year on the first run of January, carries a debt below zero, and closes earlier years nobody posted, for a charge-only policy too', function () {
    lpolFreeze('2026-01-01');
    $employee = lpolEmployee('2023-06-01');
    lpolActor();
    lpolTypes(app(Access::class));
    // A policy that only charges: nothing earns, so no month is ever posted — the year still closes.
    $params = lpolParams();
    $params['earning'] = [];
    lpolApproved($employee, '2025-11-01', $params);
    // Hand-entered before the policy: Vacation Leave 3 in 2024, never closed; Sick Leave charged below zero in 2025.
    foreach ([[2024, 'VACATION_LEAVE', 3.0], [2025, 'SICK_LEAVE', -1.5]] as [$year, $token, $credits]) {
        $credit = new LeaveCredit;
        $credit->forceFill(['employee_id' => $employee->id, 'year' => $year, 'request_type' => $token, 'credits' => $credits])->save();
    }

    // New Year's Day: 2024 closes first (its 3 chain into 2025), then 2025 closes with both types.
    app(PostLeaveEarnings::class)->run('2026-01-01', app(Access::class));
    $postings = LeaveEarningPosting::query()->where('employee_id', $employee->id)->orderBy('period')->orderBy('request_type')->get();
    expect($postings->map(fn ($p) => $p->period.' '.$p->request_type.' '.$p->days)->all())
        ->toBe(['2024-YE VACATION_LEAVE 3', '2025-YE SICK_LEAVE -1.5', '2025-YE VACATION_LEAVE 3'])
        ->and(LeaveCredit::query()->where('employee_id', $employee->id)->where('year', 2025)->where('request_type', 'VACATION_LEAVE')->value('credits'))->toBe(3.0)
        ->and(LeaveCredit::query()->where('employee_id', $employee->id)->where('year', 2026)->where('request_type', 'VACATION_LEAVE')->value('credits'))->toBe(3.0)
        // The debt carries as a debt: a new year forgives nothing.
        ->and(LeaveCredit::query()->where('employee_id', $employee->id)->where('year', 2026)->where('request_type', 'SICK_LEAVE')->value('credits'))->toBe(-1.5)
        ->and(LeaveCreditEntry::query()->where('employee_id', $employee->id)->where('kind', 'CARRY_FORWARD')->where('year', 2026)->where('request_type', 'SICK_LEAVE')->value('days'))->toBe(-1.5)
        ->and(LeaveCreditEntry::query()->where('kind', 'LAPSE')->count())->toBe(0)
        ->and(AuditLog::query()->where('action', 'LEAVE_EARNINGS_POSTED')->where('details', 'like', 'Leave year 2025 closed:%')->value('details'))->toContain('SICK_LEAVE carried -1.5');

    // A second run adds nothing for these years.
    app(PostLeaveEarnings::class)->run('2026-01-02', app(Access::class));
    expect(LeaveEarningPosting::query()->where('employee_id', $employee->id)->count())->toBe(3);

    // The CHECK that admits a negative carry is re-added by its own migration, retry-safe (a no-op on sqlite).
    $migration = require base_path('database/migrations/2026_10_31_0007_allow_negative_carry_forward_on_leave_credit_entries_table.php');
    $migration->up();
    $migration->up();
    expect(LeaveCreditEntry::query()->where('kind', 'CARRY_FORWARD')->where('days', '<', 0)->count())->toBe(1);
});

/*
|--------------------------------------------------------------------------
| The tab
|--------------------------------------------------------------------------
*/

it('shows the policy in force and the drafts on the Leave Policies tab, and prepares, approves and discards from it', function () {
    lpolFreeze('2026-11-05');
    $employee = lpolEmployee();
    $preparer = lpolActor();
    lpolTypes(app(Access::class));
    lpolApproved($employee, '2026-09-01', lpolParams());
    test()->actingAs($preparer);
    app()->forgetInstance(Access::class);

    Livewire::withQueryParams(['tab' => 'policies']);
    Livewire::test(Index::class)->assertSet('tab', 'policies')->assertSee('data-leave-policies', false);

    $tab = Livewire::test(LeavePolicies::class)
        ->assertSee('Sick Leave 1.25 a month (pro-rata) · Vacation Leave 1.25 a month')
        ->assertSee('since 01 Sep 2026')
        ->call('openPolicy', $employee->id)
        ->assertSet('policyOpen', true)
        ->assertSet('policyFrom', '2026-11-05')
        ->set('policyFrom', '2027-01-01')
        ->set('policyReason', 'New year rates')
        ->set('policyForm.earning.VACATION_LEAVE.enabled', true)
        ->set('policyForm.earning.VACATION_LEAVE.amount', '1.5')
        ->set('policyForm.earning.SICK_LEAVE.enabled', false)
        ->call('savePolicy')
        ->assertSet('policyOpen', false)
        ->assertSet('versionsOpen', true)
        ->assertSee('1 awaiting approval')
        ->assertSee('New year rates');
    $draft = EmployeeLeavePolicy::query()->where('status', 'DRAFT')->sole();
    expect($draft->effective_from->format('Y-m-d'))->toBe('2027-01-01')
        ->and($draft->params['earning'])->toHaveKeys(['VACATION_LEAVE'])->not->toHaveKey('SICK_LEAVE');

    // The preparer approves their own draft from the tab.
    $tab->call('approveVersion', $draft->id);
    expect($draft->refresh()->status)->toBe('APPROVED');

    // A new draft from the tab, then discarded from it; a holder without all data sees the tab read-only and writes nothing.
    Livewire::test(LeavePolicies::class)->call('openPolicy', $employee->id)->set('policyReason', 'Oops')->set('policyForm.earning.SICK_LEAVE.enabled', true)->set('policyForm.earning.SICK_LEAVE.amount', '1')->call('savePolicy')->assertSet('policyOpen', false);
    $second = EmployeeLeavePolicy::query()->where('status', 'DRAFT')->sole();
    lpolActor(['LEAVES'], allData: false, employeeId: $employee->id);
    Livewire::test(LeavePolicies::class)->assertDontSee('Post earnings now')->call('discardVersion', $second->id)->call('openPolicy', $employee->id)->assertSet('policyOpen', false);
    expect(EmployeeLeavePolicy::query()->whereKey($second->id)->exists())->toBeTrue()
        ->and(app(LeavePolicyRecords::class)->versions(app(Access::class), $employee->id))->toHaveCount(3);
    lpolActor();
    Livewire::test(LeavePolicies::class)->call('discardVersion', $second->id);
    expect(EmployeeLeavePolicy::query()->whereKey($second->id)->exists())->toBeFalse();
});
