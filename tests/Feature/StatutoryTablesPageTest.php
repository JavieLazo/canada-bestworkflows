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
use App\Support\Access;
use Illuminate\Support\Str;
use Livewire\Livewire;

/**
 * The Contribution & Tax Tables page: any RECORDS holder reads the versions,
 * the figures and the samples; only an all-data holder gets the Add and
 * Extend dialogs, and every write goes through the audited commands, which
 * refuse anyone else. Percentages typed as shown keep the stored fraction.
 */
function stpActor(bool $allData = true): User
{
    $role = new Role;
    $role->forceFill(['name' => 'Tables page '.Str::random(6), 'all_data' => $allData, 'is_system' => false])->save();
    $grant = new RoleModuleGrant;
    $grant->forceFill(['role_id' => $role->id, 'module' => ModuleCode::RECORDS])->save();
    $user = User::factory()->create(['role_id' => $role->id, 'employee_id' => null]);
    test()->actingAs($user);
    app()->forgetInstance(Access::class);

    return $user;
}

it('gates the page on the RECORDS grant and lists the seeded version with its figures and samples', function () {
    $this->get('/records/contribution-tables')->assertRedirect(route('login'));

    stpActor(false);
    $this->get('/records/contribution-tables')
        ->assertOk()
        ->assertSee('Contribution &amp; Tax Tables', false)
        ->assertSee('Payroll can run for periods through')
        ->assertSee('31 Dec 2026')
        ->assertSee('SSS versions')
        ->assertSee('01 Jan 2025')
        ->assertSee('Current')
        ->assertSee('Employee share (%)')
        ->assertSee('Lowest salary credit')
        ->assertSee('₱1,500.00')
        ->assertSee('₱3,030.00')
        ->assertSee('Adding or extending a version requires Records Management access to all data.')
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

it('refuses a version from a RECORDS holder without all data and leaves no trace', function () {
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

it('extends the current version and reports a wrong date', function () {
    stpActor();
    $id = (string) PayrollStatutoryTable::query()->where('kind', StatutorySchedule::PAGIBIG)->value('id');
    Livewire::test(StatutoryTablesPage::class)
        ->set('tab', StatutorySchedule::PAGIBIG)
        ->call('openExtend', $id)
        ->assertSet('extendOpen', true)
        ->assertSet('formExtendThrough', '2027-12-31')
        ->set('formExtendThrough', '2024-12-31')
        ->call('saveExtend')
        ->assertSet('formError', 'The covered-through date cannot fall before the effective date.')
        ->set('formExtendThrough', '2028-06-30')
        ->call('saveExtend')
        ->assertSet('extendOpen', false)
        ->assertSee('30 Jun 2028');
    expect(PayrollStatutoryTable::query()->whereKey($id)->sole()->covered_through->format('Y-m-d'))->toBe('2028-06-30')
        ->and(AuditLog::query()->where('action', 'STATUTORY_TABLE_EXTENDED')->count())->toBe(1);
});
