<?php

declare(strict_types=1);

use App\Domain\Enums\ModuleCode;
use App\Domain\Payroll\StatutorySchedule;
use App\Livewire\Records\StatutoryTablesPage;
use App\Models\AuditLog;
use App\Models\PayrollStatutoryTable;
use App\Models\Role;
use App\Models\RoleModuleGrant;
use App\Models\User;
use App\Modules\Payroll\Application\Commands\AddStatutoryTableVersion;
use App\Support\Access;
use Illuminate\Support\Str;
use Livewire\Livewire;

/**
 * The Contribution & Tax Tables page, under Payroll Settings: any PAYROLL
 * holder reads the versions, the figures and the samples; only an all-data
 * holder gets the Add, Correct, Withdraw and Confirm dialogs, and every
 * write goes through the audited commands, which refuse anyone else.
 * Percentages typed as shown keep the stored fraction.
 */
function stpActor(bool $allData = true): User
{
    $role = new Role;
    $role->forceFill(['name' => 'Tables page '.Str::random(6), 'all_data' => $allData, 'is_system' => false])->save();
    $grant = new RoleModuleGrant;
    $grant->forceFill(['role_id' => $role->id, 'module' => ModuleCode::PAYROLL])->save();
    $user = User::factory()->create(['role_id' => $role->id, 'employee_id' => null]);
    test()->actingAs($user);
    app()->forgetInstance(Access::class);

    return $user;
}

it('gates the page on the PAYROLL grant and lists the seeded version with its figures and samples', function () {
    $this->get('/payroll/settings/contribution-tables')->assertRedirect(route('login'));

    stpActor(false);
    $this->get('/payroll/settings/contribution-tables')
        ->assertOk()
        ->assertSee('Contribution &amp; Tax Tables', false)
        ->assertSee('tables are confirmed through')
        ->assertSee('31 Dec 2026')
        ->assertSee('SSS versions')
        ->assertSee('01 Jan 2025')
        ->assertSee('Current')
        ->assertSee('Employee share (%)')
        ->assertSee('Lowest salary credit')
        ->assertSee('₱1,500.00')
        ->assertSee('₱3,030.00')
        ->assertSee('Adding, correcting or confirming a version requires Payroll access to all data.')
        ->assertDontSee('Add new version');

    Livewire::test(StatutoryTablesPage::class)
        ->set('tab', StatutorySchedule::WITHHOLDING_TAX)
        ->assertSee('Withholding Tax versions')
        ->assertSee('Per cut-off (half a month)')
        ->assertSee('₱10,417.00')
        ->assertSee('₱2,604.10')
        ->set('tab', 'GSIS')
        ->assertSet('tab', StatutorySchedule::SSS);
});

it('adds a version from the dialog, converting percentages once, and supersedes the current one', function () {
    // The default start date is today while the coverage holds, so the clock is pinned.
    $this->travelTo(new DateTimeImmutable('2026-10-12T09:00:00+08:00'));
    $actor = stpActor();
    $page = Livewire::test(StatutoryTablesPage::class)
        ->call('openAdd')
        ->assertSet('dialogOpen', true)
        // Coverage still holds, so a change starts today; the test dates it after the coverage instead.
        ->assertSet('formEffectiveFrom', '2026-10-12')
        ->set('formEffectiveFrom', '2027-01-01')
        ->set('formCoveredThrough', '2027-12-31')
        ->assertSet('formCoveredThrough', '2027-12-31')
        ->assertSet('formFigures.ee_rate', '5')
        ->assertSet('formFigures.er_rate', '10')
        ->assertSet('formFigures.msc_max', '35000')
        ->assertSee('Preview at ₱30,000.00 a month: employee')
        ->set('formFigures.msc_max', '40000')
        ->set('formFigures.ec_high', '50')
        ->assertSee('₱1,500.00');
    $page->set('formReference', 'New schedule')->call('save');

    $versions = PayrollStatutoryTable::query()->where('kind', StatutorySchedule::SSS)->orderBy('effective_from')->get();
    expect($versions)->toHaveCount(2)
        ->and($versions[0]->covered_through->format('Y-m-d'))->toBe('2026-12-31')
        ->and($versions[1]->effective_from->format('Y-m-d'))->toBe('2027-01-01')
        ->and($versions[1]->covered_through->format('Y-m-d'))->toBe('2027-12-31')
        ->and($versions[1]->reference)->toBe('New schedule')
        ->and($versions[1]->created_by)->toBe((int) $actor->id)
        // JSON drops a whole number's fraction; the engine reads either as the same float.
        ->and($versions[1]->params)->toEqual([...StatutorySchedule::BUILT_IN[StatutorySchedule::SSS], 'msc_max' => 40000.0, 'ec_high' => 50.0])
        ->and(AuditLog::query()->where('action', 'STATUTORY_TABLE_ADDED')->count())->toBe(1);
    // The new version is upcoming; the one in force keeps its Current pill until then.
    $page->assertSet('dialogOpen', false)->assertSee('Upcoming')->assertSee('Current')->assertSee('New schedule')->assertDontSee('Superseded');
});

it('refuses a version from a PAYROLL holder without all data and leaves no trace', function () {
    stpActor(false);
    $page = Livewire::test(StatutoryTablesPage::class);
    $page->set('tab', StatutorySchedule::PHILHEALTH)
        ->set('formEffectiveFrom', '2027-01-01')->set('formCoveredThrough', '2027-12-31')
        ->set('formFigures', ['ee_rate' => '3', 'er_rate' => '3', 'floor' => '10000', 'cap' => '100000'])
        ->call('save')
        ->assertSet('formError', 'Your role cannot manage organization-wide data.');
    expect(PayrollStatutoryTable::query()->count())->toBe(4)
        ->and(AuditLog::query()->count())->toBe(0);
});

it('confirms the current version through a later date and reports a wrong one', function () {
    $this->travelTo(new DateTimeImmutable('2026-10-12T09:00:00+08:00'));
    stpActor();
    $id = (string) PayrollStatutoryTable::query()->where('kind', StatutorySchedule::PAGIBIG)->value('id');
    Livewire::test(StatutoryTablesPage::class)
        ->set('tab', StatutorySchedule::PAGIBIG)
        ->call('openConfirm', $id)
        ->assertSet('confirmOpen', true)
        // Already confirmed through the end of this year, so the next year's end is proposed.
        ->assertSet('formConfirmThrough', '2027-12-31')
        ->set('formConfirmThrough', '2024-12-31')
        ->call('saveConfirm')
        ->assertSet('formError', 'The confirmed-through date cannot fall before the effective date.')
        ->set('formConfirmThrough', '2026-06-30')
        ->call('saveConfirm')
        ->assertSet('formError', 'The Pag-IBIG table is already confirmed through 31 Dec 2026. Choose a later date.')
        ->set('formConfirmThrough', '2028-06-30')
        ->call('saveConfirm')
        ->assertSet('confirmOpen', false)
        ->assertSee('30 Jun 2028');
    expect(PayrollStatutoryTable::query()->whereKey($id)->sole()->covered_through->format('Y-m-d'))->toBe('2028-06-30')
        ->and(AuditLog::query()->where('action', 'STATUTORY_TABLE_CONFIRMED')->count())->toBe(1);
});

it('corrects and withdraws a version that has not taken effect from its row menu, and never one in force', function () {
    $this->travelTo(new DateTimeImmutable('2026-10-12T09:00:00+08:00'));
    $actor = stpActor();
    $seeded = (string) PayrollStatutoryTable::query()->where('kind', StatutorySchedule::SSS)->value('id');
    app(AddStatutoryTableVersion::class)->handle(Access::forActor($actor), StatutorySchedule::SSS, '2027-01-01', '2027-12-31', 'Typo', StatutorySchedule::BUILT_IN[StatutorySchedule::SSS]);
    $future = (string) PayrollStatutoryTable::query()->where('kind', StatutorySchedule::SSS)->orderByDesc('effective_from')->value('id');

    $page = Livewire::test(StatutoryTablesPage::class)
        // The version in force opens nothing.
        ->call('openEdit', $seeded)
        ->assertSet('dialogOpen', false)
        ->call('openEdit', $future)
        ->assertSet('dialogOpen', true)
        ->assertSet('editingId', $future)
        ->assertSet('formEffectiveFrom', '2027-01-01')
        ->assertSet('formReference', 'Typo')
        ->assertSee('Correct SSS version from 01 Jan 2027')
        ->assertSee('Save correction')
        ->set('formEffectiveFrom', '2027-02-01')
        ->set('formFigures.msc_max', '40000')
        ->set('formReference', 'Fixed')
        ->call('save')
        ->assertSet('dialogOpen', false)
        ->assertSet('editingId', null)
        ->assertSee('01 Feb 2027')
        ->assertSee('Fixed');
    $corrected = PayrollStatutoryTable::query()->whereKey($future)->sole();
    expect($corrected->effective_from->format('Y-m-d'))->toBe('2027-02-01')
        // JSON drops a whole number's fraction; the engine reads either as the same float.
        ->and($corrected->params['msc_max'])->toEqual(40000.0)
        ->and($corrected->reference)->toBe('Fixed')
        ->and(AuditLog::query()->where('action', 'STATUTORY_TABLE_UPDATED')->count())->toBe(1);

    $page->call('openWithdraw', $future)
        ->assertSet('withdrawOpen', true)
        ->assertSee('Withdraw the SSS version from 01 Feb 2027?')
        ->call('confirmWithdraw')
        ->assertSet('withdrawOpen', false)
        ->assertSet('withdrawingId', null);
    expect(PayrollStatutoryTable::query()->whereKey($future)->exists())->toBeFalse()
        ->and(PayrollStatutoryTable::query()->where('kind', StatutorySchedule::SSS)->count())->toBe(1)
        ->and(AuditLog::query()->where('action', 'STATUTORY_TABLE_WITHDRAWN')->count())->toBe(1);

    // The version in force cannot be withdrawn from the second step either, and a reader without all data moves nothing.
    $page->call('openWithdraw', $seeded)->call('confirmWithdraw');
    stpActor(false);
    app(AddStatutoryTableVersion::class)->handle(Access::forActor($actor), StatutorySchedule::SSS, '2027-03-01', '2027-12-31', '', StatutorySchedule::BUILT_IN[StatutorySchedule::SSS]);
    $again = (string) PayrollStatutoryTable::query()->where('kind', StatutorySchedule::SSS)->orderByDesc('effective_from')->value('id');
    Livewire::test(StatutoryTablesPage::class)->call('openWithdraw', $again)->call('confirmWithdraw');
    expect(PayrollStatutoryTable::query()->where('kind', StatutorySchedule::SSS)->count())->toBe(2)
        ->and(AuditLog::query()->where('action', 'STATUTORY_TABLE_WITHDRAWN')->count())->toBe(1);
});
