<?php

declare(strict_types=1);

use App\Livewire\Records\LeaveCreditSettingsPage;
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
use App\Modules\Timekeeping\Application\Commands\SaveLeavePolicy;
use App\Modules\Timekeeping\Application\Commands\SaveLeaveTypeCredits;
use App\Modules\Timekeeping\Application\PostLeaveEarnings;
use App\Modules\Timekeeping\Domain\Credits\LeaveEarningRule;
use App\Modules\Timekeeping\Domain\Credits\LeaveTypeCredits;
use App\Support\Access;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Livewire\Livewire;

/**
 * The year-end rule PER LEAVE TYPE (the PPMC plan's Phase 18, D6): a type
 * that CARRIES keeps its whole balance into the new year, a debt included;
 * a type that LAPSES — a yearly grant — drops whatever is above zero at
 * the year end as a LAPSE entry in the old year and still carries a debt.
 * Set on the Leave Credits settings; read by the earning job's year close.
 */

/** @param list<string> $modules */
function lyelActor(array $modules = ['LEAVES', 'RECORDS'], bool $allData = true, ?string $employeeId = null): User
{
    $role = new Role;
    $role->forceFill(['name' => 'Lapse '.Str::random(6), 'all_data' => $allData, 'is_system' => false])->save();
    foreach ($modules as $module) {
        $grant = new RoleModuleGrant;
        $grant->forceFill(['role_id' => $role->getKey(), 'module' => $module])->save();
    }
    $user = User::factory()->create(['role_id' => $role->getKey(), 'employee_id' => $employeeId]);
    test()->actingAs($user);
    app()->forgetInstance(Access::class);

    return $user;
}

function lyelEmployee(): EmployeeProfile
{
    $employee = new EmployeeProfile;
    $employee->forceFill(['employee_code' => 'LYEL-'.Str::random(6), 'first_name' => 'Yara', 'last_name' => 'End '.Str::random(4),
        'employment_status' => 'Regular', 'date_hired' => '2023-06-01', 'is_active' => true])->save();

    return $employee;
}

/** Vacation Leave carries; Special Privilege Leave lapses. */
function lyelTypes(Access $access): void
{
    foreach ([['VL', 'Vacation Leave', LeaveTypeCredits::YEAR_END_CARRY], ['SPL', 'Special Privilege Leave', LeaveTypeCredits::YEAR_END_LAPSE]] as [$code, $name, $yearEnd]) {
        $type = new LeaveType;
        $type->forceFill(['code' => $code, 'name' => $name])->save();
        app(SaveLeaveTypeCredits::class)->handle($access, $type->id, ['earns_credits' => true, 'unit' => 'DAYS', 'charge_late' => false, 'charge_absence' => true, 'charge_order' => '1', 'below_zero_limit' => '0', 'year_end' => $yearEnd]);
    }
}

it('reads the year-end rule leniently, refuses an unknown one, and words a lapsing type', function () {
    expect(LeaveTypeCredits::fromInput(['earns_credits' => true, 'unit' => 'DAYS', 'charge_late' => false, 'charge_absence' => false, 'charge_order' => '0', 'below_zero_limit' => '0'])->yearEnd)->toBe('CARRY')
        ->and(LeaveTypeCredits::error(['earns_credits' => true, 'unit' => 'DAYS', 'charge_late' => false, 'charge_absence' => false, 'charge_order' => '0', 'below_zero_limit' => '0', 'year_end' => 'FORFEIT']))->toContain('carries over or lapses')
        ->and(LeaveTypeCredits::fromInput(['earns_credits' => true, 'unit' => 'DAYS', 'charge_late' => false, 'charge_absence' => false, 'charge_order' => '0', 'below_zero_limit' => '0', 'year_end' => 'LAPSE'])->describe())->toContain('lapses at year end')
        ->and(LeaveEarningRule::yearEnd(3.5))->toBe(3.5)
        ->and(LeaveEarningRule::yearEnd(3.5, lapses: true))->toBe(0.0)
        ->and(LeaveEarningRule::yearEnd(-1.5, lapses: true))->toBe(-1.5)
        ->and(LeaveEarningRule::lapsed(3.5, true))->toBe(3.5)
        ->and(LeaveEarningRule::lapsed(3.5, false))->toBe(0.0)
        ->and(LeaveEarningRule::lapsed(-1.5, true))->toBe(0.0);
});

it('sets the rule from the Leave Credits settings and shows it in the list', function () {
    lyelActor();
    $type = new LeaveType;
    $type->forceFill(['code' => 'SP', 'name' => 'Solo Parent Leave'])->save();
    Livewire::test(LeaveCreditSettingsPage::class)
        ->call('openType', $type->id)->assertSet('typeForm.year_end', 'CARRY')
        ->set('typeForm.earns_credits', true)->set('typeForm.year_end', 'LAPSE')
        ->call('saveType')
        ->assertSee('Lapses');
    expect($type->refresh()->year_end)->toBe('LAPSE')
        ->and(AuditLog::query()->where('action', 'LEAVE_TYPE_CREDITS_SET')->value('details'))->toContain('lapses at year end');
});

it('drops a lapsing type\'s balance above zero at the year end as a LAPSE entry, carries a debt, and carries the other types whole', function () {
    test()->travelTo(CarbonImmutable::parse('2026-01-01 09:00:00', 'Asia/Manila')->utc());
    $employee = lyelEmployee();
    lyelActor();
    lyelTypes(app(Access::class));
    // A charge-only policy: nothing earns, the year still closes.
    $params = ['posting_day' => 'MONTH_END', 'unpaid_counts' => ['UNPAID_LEAVE', 'ABSENCE'], 'earning' => [],
        'charges' => ['enabled' => true, 'combine' => 'DAY', 'late' => [], 'absence' => ['VACATION_LEAVE', 'SPECIAL_PRIVILEGE_LEAVE']]];
    $id = app(SaveLeavePolicy::class)->handle(app(Access::class), $employee->id, '2025-11-01', $params, 'Test policy');
    lyelActor();
    app(ApproveLeavePolicy::class)->handle(app(Access::class), $id);
    expect(EmployeeLeavePolicy::query()->whereKey($id)->value('status'))->toBe('APPROVED');
    foreach ([['VACATION_LEAVE', 3.0], ['SPECIAL_PRIVILEGE_LEAVE', 2.5]] as [$token, $credits]) {
        $credit = new LeaveCredit;
        $credit->forceFill(['employee_id' => $employee->id, 'year' => 2025, 'request_type' => $token, 'credits' => $credits])->save();
    }

    app(PostLeaveEarnings::class)->run('2026-01-01', app(Access::class));
    $postings = LeaveEarningPosting::query()->where('employee_id', $employee->id)->where('period', '2025-YE')->get()->keyBy('request_type');
    expect($postings->keys()->sort()->values()->all())->toBe(['SPECIAL_PRIVILEGE_LEAVE', 'VACATION_LEAVE'])
        ->and($postings['VACATION_LEAVE']->days)->toEqual(3.0)
        ->and($postings['SPECIAL_PRIVILEGE_LEAVE']->days)->toEqual(0.0)
        ->and($postings['SPECIAL_PRIVILEGE_LEAVE']->inputs['lapsed'])->toEqual(2.5)
        // The lapse is an entry in the OLD year; the new year opens with nothing of it.
        ->and(LeaveCreditEntry::query()->where('employee_id', $employee->id)->where('kind', 'LAPSE')->where('year', 2025)->where('request_type', 'SPECIAL_PRIVILEGE_LEAVE')->value('days'))->toEqual(-2.5)
        ->and(LeaveCredit::query()->where('employee_id', $employee->id)->where('year', 2025)->where('request_type', 'SPECIAL_PRIVILEGE_LEAVE')->value('credits'))->toEqual(0.0)
        ->and(LeaveCredit::query()->where('employee_id', $employee->id)->where('year', 2026)->where('request_type', 'SPECIAL_PRIVILEGE_LEAVE')->exists())->toBeFalse()
        ->and(LeaveCredit::query()->where('employee_id', $employee->id)->where('year', 2026)->where('request_type', 'VACATION_LEAVE')->value('credits'))->toEqual(3.0)
        ->and(AuditLog::query()->where('action', 'LEAVE_EARNINGS_POSTED')->where('details', 'like', 'Leave year 2025 closed:%')->value('details'))->toContain('SPECIAL_PRIVILEGE_LEAVE lapsed 2.5')->toContain('VACATION_LEAVE carried 3');

    // A second run adds nothing; a debt on a lapsing type would have carried.
    app(PostLeaveEarnings::class)->run('2026-01-02', app(Access::class));
    expect(LeaveEarningPosting::query()->where('employee_id', $employee->id)->count())->toBe(2);
});
