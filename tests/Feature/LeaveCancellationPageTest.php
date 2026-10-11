<?php

declare(strict_types=1);

use App\Livewire\Leaves\Index;
use App\Livewire\Payroll\RunWorkspace;
use App\Livewire\Portal\PortalPage;
use App\Models\EmployeeProfile;
use App\Models\LeaveTravelRequest;
use App\Models\Role;
use App\Models\RoleModuleGrant;
use App\Models\User;
use App\Modules\Timekeeping\Application\Commands\CancelAbsence;
use App\Support\Access;
use Carbon\CarbonImmutable;
use Livewire\Livewire;
use Tests\Support\PayrollEvidenceFixture;

/** Cancellation controls preserve used dates, management self-access and visible evidence across screens. */

/** @return array{user: User, request: LeaveTravelRequest} */
function cancellationPageFixture(bool $management = true): array
{
    $employee = EmployeeProfile::create(['employee_code' => 'CANCEL-PAGE', 'first_name' => 'Leave', 'last_name' => 'Reviewer',
        'date_hired' => '2024-01-01', 'employment_status' => 'Regular', 'is_active' => true]);
    $role = Role::create(['name' => 'Leave page role', 'all_data' => $management, 'is_system' => false]);
    foreach (['LEAVES', 'PORTAL'] as $module) {
        RoleModuleGrant::create(['role_id' => $role->id, 'module' => $module]);
    }
    $user = User::factory()->create(['employee_id' => $employee->id, 'role_id' => $role->id]);
    test()->actingAs($user);
    app()->forgetInstance(Access::class);
    $request = new LeaveTravelRequest;
    $request->forceFill(['code' => 'LR-CANCEL-PAGE', 'employee_id' => $employee->id, 'request_type' => 'SICK',
        'day_type' => 'WHOLE_DAY', 'start_date' => '2026-10-09', 'end_date' => '2026-10-14',
        'status' => 'APPROVED', 'is_paid' => true, 'reviewed_by' => $user->id,
        'reviewed_at' => CarbonImmutable::parse('2026-10-01T08:00:00+08:00')->utc()])->save();

    return compact('user', 'request');
}

beforeEach(function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-10T14:00:00+08:00'));
});

afterEach(function () {
    CarbonImmutable::setTestNow();
});

it('lets management cancel selected future dates of its own approved leave and retain the history', function () {
    ['request' => $request] = cancellationPageFixture();
    $page = Livewire::test(Index::class)->call('show', $request->id)
        ->assertSee('Cancel future dates')
        ->assertDontSeeHtml('value="2026-10-09"')
        ->assertDontSeeHtml('value="2026-10-10"')
        ->set('cancelDates', ['2026-10-12', '2026-10-14'])
        ->set('cancelReason', 'Plans changed; the other days remain approved.')
        ->call('cancelSelectedDates')
        ->assertSet('cancelError', '')
        ->assertSee('Partially cancelled')
        ->assertSee('Cancelled dates')
        ->assertSee('Plans changed; the other days remain approved.')
        ->assertDontSeeHtml('value="2026-10-12"');

    expect($request->fresh()->status)->toBe('APPROVED')
        ->and($request->fresh()->cancelledDates())->toBe(['2026-10-12', '2026-10-14']);
    $page->set('cancelDates', ['2026-10-09'])->call('cancelSelectedDates');
    expect($request->fresh()->cancellations)->toHaveCount(2);

    Livewire::test(PortalPage::class)->set('tab', 'requests')
        ->assertSee('2 cancelled dates')->assertSee('Plans changed; the other days remain approved.')
        ->assertDontSee('Cancel selected dates');
});

it('refuses cancellation through a visible own request without management access', function () {
    ['request' => $request] = cancellationPageFixture(false);
    Livewire::test(Index::class)->call('show', $request->id)
        ->assertDontSee('Cancel future dates')
        ->set('cancelDates', ['2026-10-12'])->set('cancelReason', 'Not authorized')
        ->call('cancelSelectedDates');
    expect($request->fresh()->cancellations)->toHaveCount(0);
});

it('shows a fully cancelled request without calling its original approval rejected', function () {
    ['request' => $request] = cancellationPageFixture();
    $request->update(['start_date' => '2026-10-12', 'end_date' => '2026-10-12']);
    Livewire::test(Index::class)->call('show', $request->id)
        ->set('cancelDates', ['2026-10-12'])->set('cancelReason', 'No longer needed')
        ->call('cancelSelectedDates')->assertSee('CANCELLED')->assertSee('Approved with pay.')
        ->assertDontSee('Rejected.')->assertDontSee('Cancel future dates');
});

it('warns payroll about a manual Leave amount after cancellation without changing the override', function () {
    $fixture = PayrollEvidenceFixture::make();
    RoleModuleGrant::create(['role_id' => $fixture['role']->id, 'module' => 'LEAVES']);
    $this->actingAs($fixture['user']->fresh());
    app()->forgetInstance(Access::class);
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-31T10:00:00+08:00'));
    $request = LeaveTravelRequest::create(['code' => 'LR-PAY-CANCEL', 'employee_id' => $fixture['employee']->id,
        'request_type' => 'SICK', 'day_type' => 'WHOLE_DAY', 'start_date' => '2026-09-01',
        'end_date' => '2026-09-02', 'status' => 'APPROVED', 'is_paid' => true]);
    $fixture['member']->update(['earning_leave_pay' => 500]);
    app(CancelAbsence::class)->handle(app(Access::class), $request->id, ['2026-09-02'], 'Returned to work');

    Livewire::test(RunWorkspace::class, ['id' => $fixture['run']->id])
        ->assertSeeHtml('data-leave-cancellation-warning')
        ->assertSee('The manually entered Leave amount still applies.')
        ->call('updateEarning', $fixture['employee']->id, 'leave_pay', '')
        ->assertSee('The calculation excludes those leave dates.')
        ->assertDontSee('The manually entered Leave amount still applies.');
    expect($fixture['member']->fresh()->earning_leave_pay)->toBe(500.0);
});
