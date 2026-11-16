<?php

declare(strict_types=1);

use App\Livewire\Records\LeaveCreditSettingsPage;
use App\Models\AuditLog;
use App\Models\EmployeeProfile;
use App\Models\LeaveConversionTable;
use App\Models\LeaveCredit;
use App\Models\LeaveCreditEntry;
use App\Models\LeaveType;
use App\Models\Role;
use App\Models\RoleModuleGrant;
use App\Models\User;
use App\Modules\Timekeeping\Application\Commands\RecordLeaveCredit;
use App\Modules\Timekeeping\Application\Commands\SaveLeaveTypeCredits;
use App\Modules\Timekeeping\Application\ConversionTables;
use App\Modules\Timekeeping\Application\Queries\LeaveTypeCreditRecords;
use App\Modules\Timekeeping\Domain\Credits\MinuteConversion;
use App\Support\Access;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Livewire\Livewire;

/**
 * Leave Settings (Records › Leave Settings): each leave type's credit
 * rules on the Leave types tab and the minutes-to-days conversion table on
 * a tab of its own (the leave rules are in LeaveRulesTest). Whoever manages Leave Settings (all-data RECORDS) or leave
 * (all-data LEAVES) changes them; any holder of either module reads them.
 * The minutes-to-days conversion table is FIXED — the standard table at an
 * 8-hour day, three decimals, a half rounding down — always in use, shown
 * read-only, and never taken from a stored row. Credits hold four decimals.
 */

/** @param list<string> $modules */
function lcsActor(array $modules = ['RECORDS', 'LEAVES'], bool $allData = true): Access
{
    $role = new Role;
    $role->forceFill(['name' => 'Lcs '.Str::random(6), 'all_data' => $allData, 'is_system' => false])->save();
    foreach ($modules as $module) {
        $grant = new RoleModuleGrant;
        $grant->forceFill(['role_id' => $role->getKey(), 'module' => $module])->save();
    }
    $user = User::factory()->create(['role_id' => $role->getKey()]);
    test()->actingAs($user);
    app()->forgetInstance(Access::class);

    return app(Access::class);
}

function lcsType(string $name): LeaveType
{
    $type = new LeaveType;
    $type->forceFill(['code' => (string) random_int(100, 999), 'name' => $name])->save();

    return $type;
}

/*
|--------------------------------------------------------------------------
| The page
|--------------------------------------------------------------------------
*/

it('opens for RECORDS or LEAVES holders and turns everyone else away', function () {
    $this->get('/records/leave-credits')->assertRedirect(route('login'));

    lcsActor(['PORTAL'], allData: false);
    $this->get('/records/leave-credits')->assertRedirect();

    lcsActor(['LEAVES'], allData: false);
    $this->get('/records/leave-credits')->assertOk()->assertSee('Leave Settings')->assertSee('Your role can view these settings.');

    lcsActor(['RECORDS']);
    $this->get('/records/leave-credits')->assertOk()->assertSee('Conversion Table')->assertDontSee('Posting and charging')->assertSee('Leave Types')->assertDontSee('Policy presets')
        // A Records manager adds leave types here, with no CSV to export or import.
        ->assertSee('Add Leave Type')->assertDontSee('Export CSV')->assertDontSee('Import CSV');
});

it('keeps the conversion table on a tab of its own, opened from the strip or the address', function () {
    lcsActor();
    lcsType('Vacation Leave');

    // The page opens on the leave types; the conversion table is not drawn until its tab is chosen.
    Livewire::test(LeaveCreditSettingsPage::class)
        ->assertSet('tab', 'types')
        ->assertSeeHtml('data-leave-type="VACATION_LEAVE"')
        ->assertDontSeeHtml('data-conversion-tables')
        ->set('tab', 'table')
        ->assertSeeHtml('data-conversion-tables')
        ->assertSee('Conversion of Working Hours/Minutes into Fraction of a Day')
        ->assertDontSeeHtml('data-leave-type="VACATION_LEAVE"')
        // An unknown tab collapses back to the leave types.
        ->set('tab', 'policies')
        ->assertSet('tab', 'types')
        ->assertSeeHtml('data-leave-type="VACATION_LEAVE"');

    // The tab rides in the address, under the same guard.
    $this->get('/records/leave-credits?tab=table')->assertOk()
        ->assertSeeHtml('data-conversion-tables')
        ->assertDontSeeHtml('data-leave-type="VACATION_LEAVE"');
    Livewire::withQueryParams(['tab' => 'nonsense']);
    Livewire::test(LeaveCreditSettingsPage::class)
        ->assertSet('tab', 'types')
        ->assertDontSeeHtml('data-conversion-tables');
});

it('sets a leave type\'s credit rules from the dialog, audits it, and refuses a holder without all data', function () {
    lcsActor();
    $vacation = lcsType('Vacation Leave');

    Livewire::test(LeaveCreditSettingsPage::class)
        ->call('openType', $vacation->id)
        ->assertSet('typeOpen', true)
        ->set('typeForm.earns_credits', true)
        ->set('typeForm.charge_late', true)
        ->set('typeForm.charge_absence', true)
        ->set('typeForm.charge_order', '1')
        ->set('typeForm.below_zero_limit', '2.5')
        ->call('saveType')
        ->assertSet('typeOpen', false)
        ->assertSeeHtml('data-leave-type="VACATION_LEAVE"');

    $vacation->refresh();
    expect($vacation->earns_credits)->toBeTrue()
        ->and($vacation->charge_late)->toBeTrue()
        ->and($vacation->charge_absence)->toBeTrue()
        ->and($vacation->charge_order)->toBe(1)
        ->and($vacation->below_zero_limit)->toBe(2.5)
        ->and(AuditLog::query()->where('action', 'LEAVE_TYPE_CREDITS_SET')->value('details'))->toContain('Vacation Leave · earns credits · days · charged for lateness and absences, order 1 · below zero to 2.5')
        ->and(LeaveTypeCreditRecords::chargeable('late'))->toBe(['VACATION_LEAVE']);

    lcsActor(['LEAVES'], allData: false);
    expect(fn () => app(SaveLeaveTypeCredits::class)->handle(app(Access::class), $vacation->id, ['earns_credits' => false, 'unit' => 'DAYS', 'charge_late' => false, 'charge_absence' => false, 'charge_order' => '0', 'below_zero_limit' => '0']))
        ->toThrow(RuntimeException::class, 'organization-wide');
    expect($vacation->refresh()->earns_credits)->toBeTrue();
});

it('refuses a charge order another charged leave type holds, from the command and from both dialogs, and warns before the save', function () {
    $access = lcsActor();
    $vacation = lcsType('Vacation Leave');
    $sick = lcsType('Sick Leave');
    $settings = static fn (bool $charged, string $order): array => ['earns_credits' => true, 'unit' => 'DAYS', 'charge_late' => false, 'charge_absence' => $charged, 'charge_order' => $order, 'below_zero_limit' => '0'];
    app(SaveLeaveTypeCredits::class)->handle($access, $vacation->id, $settings(true, '1'));

    // The command refuses the second charged type on order 1, and writes nothing.
    expect(fn () => app(SaveLeaveTypeCredits::class)->handle($access, $sick->id, $settings(true, '1')))
        ->toThrow(RuntimeException::class, 'Deduction priority 1 is already used by Vacation Leave. Each leave type with credit deductions needs its own priority.');
    expect($sick->refresh()->charge_absence)->toBeFalse()
        ->and(AuditLog::query()->where('action', 'LEAVE_TYPE_CREDITS_SET')->count())->toBe(1);

    // A type charged for nothing is in no order; a type keeps its own number on a re-save.
    expect(app(SaveLeaveTypeCredits::class)->handle($access, $sick->id, $settings(false, '1')))->toBe('Credit rules saved for Sick Leave.')
        ->and(app(SaveLeaveTypeCredits::class)->handle($access, $vacation->id, [...$settings(true, '1'), 'below_zero_limit' => '1']))->toBe('Credit rules saved for Vacation Leave.');

    // The settings dialog warns as the order is typed, refuses the save, and takes a free number.
    $page = Livewire::test(LeaveCreditSettingsPage::class)
        ->call('openType', $sick->id)
        ->assertDontSeeHtml('data-charge-order-warning')
        ->set('typeForm.charge_absence', true)
        ->assertSeeHtml('data-charge-order-warning')
        ->assertSee('Deduction priority 1 is already used by Vacation Leave.')
        ->call('saveType')
        ->assertSet('typeOpen', true);
    expect($sick->refresh()->charge_absence)->toBeFalse();
    $page->set('typeForm.charge_order', '2')
        ->assertDontSeeHtml('data-charge-order-warning')
        ->call('saveType')
        ->assertSet('typeOpen', false);
    expect($sick->refresh()->charge_absence)->toBeTrue()
        ->and($sick->charge_order)->toBe(2);

    // Adding a type: the same warning, and the refusal leaves no half-made type behind.
    $page->call('openAdd')
        ->set('newName', 'Forced Leave')
        ->set('typeForm.charge_absence', true)
        ->set('typeForm.charge_order', '2')
        ->assertSee('Deduction priority 2 is already used by Sick Leave.')
        ->call('saveNewType')
        ->assertSet('addOpen', true)
        ->assertSet('addError', 'Deduction priority 2 is already used by Sick Leave. Each leave type with credit deductions needs its own priority.');
    expect(LeaveType::query()->where('name', 'Forced Leave')->exists())->toBeFalse();
    $page->set('typeForm.charge_order', '3')
        ->call('saveNewType')
        ->assertSet('addOpen', false);
    expect(LeaveType::query()->where('name', 'Forced Leave')->value('charge_order'))->toBe(3);
});

it('asks Add leave type in the order a leave is set up: filing first, then the rule, then the credit settings it decides', function () {
    lcsActor();

    // The Add dialog's own markup: the rule dialog earlier on the page draws the same rule fields.
    $add = strstr(Livewire::test(LeaveCreditSettingsPage::class)->call('openAdd')->html(), 'data-add-leave-type-dialog') ?: '';
    $markers = ['data-settings-filing', 'data-rule-waiting', 'data-add-credit-settings', 'data-settings-cash-out'];
    $at = array_map(static fn (string $marker): int|false => strpos($add, $marker), $markers);
    expect($at)->not->toContain(false);
    $sorted = $at;
    sort($sorted);
    expect($at)->toBe($sorted);

    // A leave without pay holds no credits: the credit settings are not asked.
    Livewire::test(LeaveCreditSettingsPage::class)->call('openAdd')
        ->set('typeForm.filing', 'UNPAID')
        ->assertSeeHtml('data-settings-filing')
        ->assertDontSeeHtml('data-add-credit-settings')
        ->assertDontSeeHtml('data-settings-cash-out');
});

it('lays the Filing and credit settings out in two balanced columns: filing over balance, deductions over cash conversion', function () {
    lcsActor();
    $type = lcsType('Vacation Leave');

    $html = Livewire::test(LeaveCreditSettingsPage::class)->call('openType', $type->id)->html();
    $dialog = strstr($html, 'data-type-rules-dialog') ?: '';
    $columns = strstr($dialog, 'data-settings-columns') ?: '';
    $markers = ['data-settings-filing', 'data-settings-balance', 'data-settings-charging', 'data-settings-cash-out'];
    $at = array_map(static fn (string $marker): int|false => strpos($columns, $marker), $markers);
    expect($at)->not->toContain(false);
    $sorted = $at;
    sort($sorted);
    expect($at)->toBe($sorted);
    // The right column opens with the deductions: they come after the left column's two cards close.
    expect(substr_count(substr($columns, 0, (int) $at[2]), 'class="space-y-4"'))->toBe(2);
});

it('flags the charged leave types that already share a charge order in the table', function () {
    lcsActor();
    // As an installation may hold them from before the rule: both charged and both left at 0.
    foreach (['Vacation Leave', 'Sick Leave', 'Birthday Leave'] as $name) {
        lcsType($name)->forceFill(['charge_absence' => $name !== 'Birthday Leave', 'charge_order' => 0])->save();
    }

    $html = Livewire::test(LeaveCreditSettingsPage::class)
        ->assertSee('Same priority as Sick Leave')
        ->assertSee('Same priority as Vacation Leave')
        ->html();
    // Birthday Leave is charged for nothing, so its 0 is flagged nowhere.
    expect(substr_count($html, 'data-charge-order-clash'))->toBe(2);
});

/*
|--------------------------------------------------------------------------
| The conversion table
|--------------------------------------------------------------------------
*/

it('uses the fixed standard table whatever is stored, and shows it to managers and viewers with nothing to edit', function () {
    // A table an earlier build saved, with a row changed by hand: history only, never read.
    LeaveConversionTable::query()->delete();
    $stored = new LeaveConversionTable;
    $stored->forceFill([
        'effective_from' => '2026-01-01', 'hours_per_day' => 8, 'decimals' => 3, 'rounding' => 'HALF_UP',
        'rows' => ['minutes' => [...MinuteConversion::rows(8, 3, 'HALF_UP')['minutes'], 30 => '0.070'], 'hours' => MinuteConversion::rows(8, 3, 'HALF_UP')['hours']],
        'reference' => 'Our own', 'status' => 'APPROVED',
    ])->save();

    expect(ConversionTables::entry())->toBe([
        'version_id' => 'CSC_STANDARD', 'hours_per_day' => 8.0, 'decimals' => 3, 'rounding' => 'HALF_DOWN',
        'rows' => MinuteConversion::rows(8, 3, 'HALF_DOWN'),
    ])
        ->and(ConversionTables::entry()['rows']['minutes'][30])->toBe('0.062')
        ->and(ConversionTables::entry()['rows']['hours'][8])->toBe('1.000');

    // A manager sees the heading and the rows alone: no settings line, edit button, dialog or warning.
    lcsActor();
    Livewire::test(LeaveCreditSettingsPage::class)
        ->set('tab', 'table')
        ->assertSee('Conversion of Working Hours/Minutes into Fraction of a Day')
        ->assertDontSee('Minutes to days')
        ->assertSeeHtml('data-conversion-rows')
        ->assertSeeHtml('wire:key="shown-minute-30"')
        ->assertSee('0.062')
        ->assertDontSee('hours a day')
        ->assertDontSee('Civil Service Commission')
        ->assertDontSee('0.070')
        ->assertDontSee('Edit table')
        ->assertDontSee('Set up table')
        ->assertDontSeeHtml('data-conversion-dialog')
        ->assertDontSeeHtml('data-late-waits-for-table')
        ->assertDontSee('How late and undertime minutes become fractions of a day');

    // A viewer without all data sees the same table.
    lcsActor(['RECORDS'], allData: false);
    Livewire::test(LeaveCreditSettingsPage::class)
        ->set('tab', 'table')
        ->assertSeeHtml('wire:key="shown-minute-30"')
        ->assertDontSee('0.070');
    expect($stored->refresh()->rounding)->toBe('HALF_UP')->and(LeaveConversionTable::query()->count())->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Precision
|--------------------------------------------------------------------------
*/

it('keeps credits to four decimals in the ledger and the allocation', function () {
    $actor = lcsActor();
    lcsType('Vacation Leave');
    $employee = new EmployeeProfile;
    $employee->forceFill(['employee_code' => 'LCS-1', 'first_name' => 'Lastina', 'last_name' => 'Baguya', 'employment_status' => 'Regular', 'date_hired' => '2024-01-01', 'is_active' => true])->save();

    app(RecordLeaveCredit::class)->handle($actor, $employee->id, 2026, 'VACATION_LEAVE', 'OPENING', '7.672', 'As of 1 January', (string) Str::ulid());
    app(RecordLeaveCredit::class)->handle($actor, $employee->id, 2026, 'VACATION_LEAVE', 'ACCRUAL', '1.167', 'August', (string) Str::ulid());

    expect(LeaveCredit::query()->sole()->credits)->toBe(8.839)
        ->and(LeaveCreditEntry::query()->where('kind', 'ACCRUAL')->sole()->days)->toBe(1.167);
    expect(fn () => app(RecordLeaveCredit::class)->handle($actor, $employee->id, 2026, 'VACATION_LEAVE', 'ACCRUAL', '1.16675', 'Too fine', (string) Str::ulid()))
        ->toThrow(RuntimeException::class, 'up to four decimals');
});

it('migrates the widened credits, the conversion tables and the policy tables twice without complaint', function () {
    $widen = require base_path('database/migrations/2026_10_31_0003_widen_leave_credits_tables.php');
    $tables = require base_path('database/migrations/2026_10_31_0004_create_leave_conversion_tables_table.php');
    $policies = require base_path('database/migrations/2026_10_31_0005_create_leave_policy_tables.php');

    $policies->down();
    $tables->down();
    $widen->up();
    $widen->up();
    $tables->up();
    $tables->up();
    $policies->up();
    $policies->up();

    expect(Schema::hasColumn('leave_types', 'below_zero_limit'))->toBeTrue()
        ->and(Schema::hasTable('leave_conversion_tables'))->toBeTrue()
        ->and(Schema::hasIndex('leave_conversion_tables', 'lct_from_unq'))->toBeTrue()
        ->and(Schema::hasTable('employee_leave_policies'))->toBeTrue()
        ->and(Schema::hasIndex('leave_earning_postings', 'lep_employee_period_type_unq'))->toBeTrue();
});
