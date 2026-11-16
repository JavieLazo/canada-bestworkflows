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
use App\Modules\Payroll\Application\Commands\ConfirmAllStatutoryTables;
use App\Support\Access;
use Illuminate\Support\Str;
use Livewire\Livewire;

/**
 * The Contribution & Tax Tables page, under Payroll Settings: any PAYROLL
 * holder reads the figures and the samples; only an all-data
 * holder gets the Edit, Confirm and Confirm all dialogs, and every write
 * goes through the audited commands, which refuse anyone else.
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

it('gates the page on the PAYROLL grant and shows each table\'s one set of figures with its samples', function () {
    $this->travelTo(new DateTimeImmutable('2026-10-12T09:00:00+08:00'));
    $this->get('/records/payroll-settings/contribution-tables')->assertRedirect(route('login'));

    stpActor(false);
    $this->get('/records/payroll-settings/contribution-tables')
        ->assertOk()
        ->assertSee('Contribution &amp; Tax Tables', false)
        ->assertDontSee('tables are confirmed through')
        ->assertSee('Confirmed through')
        ->assertSee('31 Dec 2026')
        ->assertSee('SSS Figures')
        // No list of dated versions, and no version state.
        ->assertDontSee('SSS versions')->assertDontSee('Effective From')->assertDontSee('Superseded')
        ->assertSee('Employee Share (%)')
        ->assertSee('Lowest Salary Credit')
        ->assertSee('₱1,500.00')
        ->assertSee('₱3,030.00')
        ->assertSee('Changing or confirming the figures requires Payroll access to all data.')
        ->assertDontSee('Edit Figures');

    $page = Livewire::test(StatutoryTablesPage::class)
        ->set('tab', StatutorySchedule::WITHHOLDING_TAX)
        ->assertSee('withholding tax figures')
        ->assertSeeHtml('>Semi-monthly</h3>')
        ->assertSee('₱10,417.00')
        ->assertSee('₱2,604.10')
        // What a confirmed date means is said beside it, not only inside the Confirm dialog.
        ->assertSee('Later payrolls show a reminder.')
        ->set('tab', 'GSIS')
        ->assertSet('tab', StatutorySchedule::SSS);

    // The table tabs are the head of the one card that holds the figures and their check.
    $html = $page->html();
    expect(substr_count($html, 'data-statutory-tables'))->toBe(1)
        ->and(strpos($html, 'data-statutory-tables'))->toBeLessThan((int) strpos($html, 'aria-label="Tables"'))
        ->and(strpos($html, 'aria-label="Tables"'))->toBeLessThan((int) strpos($html, 'SSS Figures'));
});

it('replaces a table\'s figures from the dialog, converting percentages once, with no date to choose', function () {
    $this->travelTo(new DateTimeImmutable('2026-10-12T09:00:00+08:00'));
    $actor = stpActor();
    $seeded = PayrollStatutoryTable::query()->where('kind', StatutorySchedule::SSS)->sole();

    $page = Livewire::test(StatutoryTablesPage::class)
        ->call('openAdd')
        ->assertSet('dialogOpen', true)
        ->assertSee('Edit SSS Figures')
        ->assertDontSee('Effective From')
        ->assertSet('formFigures.ee_rate', '5')
        ->assertSet('formFigures.er_rate', '10')
        ->assertSet('formFigures.msc_max', '35000')
        ->assertSee('Preview at ₱30,000.00 a month: employee')
        ->set('formFigures.msc_max', '40000')
        ->set('formFigures.ec_high', '50')
        ->assertSee('₱1,500.00');
    $page->set('formReference', 'New schedule')->call('save')->assertSet('dialogOpen', false);

    // The same row carries the new figures from the date it always covered: nothing is added beside it.
    $row = PayrollStatutoryTable::query()->where('kind', StatutorySchedule::SSS)->sole();
    expect($row->id)->toBe($seeded->id)
        ->and($row->effective_from->format('Y-m-d'))->toBe('2025-01-01')
        ->and($row->covered_through->format('Y-m-d'))->toBe('2026-12-31')
        ->and($row->reference)->toBe('New schedule')
        ->and($row->created_by)->toBe((int) $actor->id)
        // JSON drops a whole number's fraction; the engine reads either as the same float.
        ->and($row->params)->toEqual([...StatutorySchedule::BUILT_IN[StatutorySchedule::SSS], 'msc_max' => 40000.0, 'ec_high' => 50.0])
        ->and(AuditLog::query()->where('action', 'STATUTORY_TABLE_UPDATED')->where('entity_id', $row->id)->where('details', 'SSS table figures replaced (New schedule)')->count())->toBe(1)
        ->and(AuditLog::query()->where('action', 'STATUTORY_TABLE_ADDED')->count())->toBe(0);
    $page->assertSee('₱40,000.00')->assertDontSee('Upcoming')->assertDontSee('Superseded');
});
it('refuses a version from a PAYROLL holder without all data and leaves no trace', function () {
    stpActor(false);
    $page = Livewire::test(StatutoryTablesPage::class);
    $page->set('tab', StatutorySchedule::PHILHEALTH)
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

it('confirms every table through one date from the page, proposing the next year\'s end', function () {
    $this->travelTo(new DateTimeImmutable('2026-10-12T09:00:00+08:00'));
    stpActor();

    Livewire::test(StatutoryTablesPage::class)
        ->assertSee('Confirm All Tables')
        ->call('openConfirmAll')
        ->assertSet('confirmAllOpen', true)
        ->assertSet('formConfirmThrough', '2027-12-31')
        ->call('saveConfirmAll')
        ->assertSet('confirmAllOpen', false)
        ->assertSet('formError', null);

    expect(PayrollStatutoryTable::query()->get()->map(static fn (PayrollStatutoryTable $row): string => $row->covered_through->format('Y-m-d'))->unique()->all())->toBe(['2027-12-31'])
        ->and(AuditLog::query()->where('action', 'STATUTORY_TABLE_CONFIRMED')->count())->toBe(4);
});

it('confirms all tables through the command alone, leaving one already confirmed further as it is', function () {
    $this->travelTo(new DateTimeImmutable('2026-10-12T09:00:00+08:00'));
    $actor = Access::forActor(stpActor());
    PayrollStatutoryTable::query()->where('kind', StatutorySchedule::SSS)->update(['covered_through' => '2028-12-31']);

    expect(app(ConfirmAllStatutoryTables::class)->handle($actor, '2027-12-31'))
        ->toBe('PhilHealth, Pag-IBIG, Withholding Tax tables confirmed through 31 Dec 2027.')
        ->and(PayrollStatutoryTable::query()->where('kind', StatutorySchedule::SSS)->sole()->covered_through->format('Y-m-d'))->toBe('2028-12-31')
        ->and(AuditLog::query()->where('action', 'STATUTORY_TABLE_CONFIRMED')->count())->toBe(3);

    // Nothing left to move: a refusal, and no further audit row.
    expect(fn () => app(ConfirmAllStatutoryTables::class)->handle($actor, '2027-06-30'))
        ->toThrow(RuntimeException::class, 'Every table is already confirmed through that date. Choose a later one.')
        ->and(AuditLog::query()->where('action', 'STATUTORY_TABLE_CONFIRMED')->count())->toBe(3);
});

it('refuses confirm all without all-data PAYROLL or a date, and changes nothing', function () {
    $this->travelTo(new DateTimeImmutable('2026-10-12T09:00:00+08:00'));
    $reader = Access::forActor(stpActor(false));

    expect(fn () => app(ConfirmAllStatutoryTables::class)->handle($reader, '2027-12-31'))
        ->toThrow(RuntimeException::class, 'Your role cannot manage organization-wide data.');
    Livewire::test(StatutoryTablesPage::class)
        ->assertDontSee('Confirm All Tables')
        ->set('formConfirmThrough', '2027-12-31')
        ->call('saveConfirmAll')
        ->assertSet('formError', 'Your role cannot manage organization-wide data.');

    $manager = Access::forActor(stpActor());
    expect(fn () => app(ConfirmAllStatutoryTables::class)->handle($manager, 'soon'))
        ->toThrow(RuntimeException::class, 'Enter the date the figures are confirmed through.')
        ->and(PayrollStatutoryTable::query()->where('covered_through', '!=', '2026-12-31')->count())->toBe(0)
        ->and(AuditLog::query()->where('action', 'STATUTORY_TABLE_CONFIRMED')->count())->toBe(0);
});

it('confirms none when one table is refused, so the check is all or nothing', function () {
    $this->travelTo(new DateTimeImmutable('2026-10-12T09:00:00+08:00'));
    $actor = Access::forActor(stpActor());
    // The last table starts after the date, which its own confirmation refuses.
    PayrollStatutoryTable::query()->where('kind', StatutorySchedule::WITHHOLDING_TAX)->update(['effective_from' => '2028-01-01', 'covered_through' => '2026-12-31']);

    expect(fn () => app(ConfirmAllStatutoryTables::class)->handle($actor, '2027-12-31'))
        ->toThrow(RuntimeException::class, 'The confirmed-through date cannot fall before the effective date.')
        ->and(PayrollStatutoryTable::query()->where('kind', StatutorySchedule::SSS)->sole()->covered_through->format('Y-m-d'))->toBe('2026-12-31')
        ->and(AuditLog::query()->where('action', 'STATUTORY_TABLE_CONFIRMED')->count())->toBe(0);
});

it('leaves one row per table once the figures are edited, whatever dated versions were stored before', function () {
    $this->travelTo(new DateTimeImmutable('2026-10-12T09:00:00+08:00'));
    $actor = stpActor();
    $seeded = (string) PayrollStatutoryTable::query()->where('kind', StatutorySchedule::SSS)->value('id');
    // A later-dated version, as an installation that used dated versions has.
    app(AddStatutoryTableVersion::class)->handle(Access::forActor($actor), StatutorySchedule::SSS, '2027-01-01', '2027-12-31', 'Next year', [...StatutorySchedule::BUILT_IN[StatutorySchedule::SSS], 'msc_max' => 99000.0]);
    expect(PayrollStatutoryTable::query()->where('kind', StatutorySchedule::SSS)->count())->toBe(2);

    // The page shows and edits the figures in use, not the later ones.
    Livewire::test(StatutoryTablesPage::class)
        ->assertSee('₱35,000.00')->assertDontSee('₱99,000.00')
        ->call('openAdd')
        ->assertSet('formFigures.msc_max', '35000')
        ->set('formFigures.msc_max', '36000')
        ->call('save')
        ->assertSet('dialogOpen', false);

    $row = PayrollStatutoryTable::query()->where('kind', StatutorySchedule::SSS)->sole();
    expect($row->id)->toBe($seeded)
        ->and($row->effective_from->format('Y-m-d'))->toBe('2025-01-01')
        ->and($row->params['msc_max'])->toEqual(36000.0)
        ->and(AuditLog::query()->where('action', 'STATUTORY_TABLE_UPDATED')->where('details', 'SSS table figures replaced, 1 earlier or later version removed')->count())->toBe(1)
        // The other tables are untouched.
        ->and(PayrollStatutoryTable::query()->count())->toBe(4);
});
