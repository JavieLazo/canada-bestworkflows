<?php

declare(strict_types=1);

use App\Livewire\Records\LeaveCreditSettingsPage;
use App\Models\AuditLog;
use App\Models\EmployeeProfile;
use App\Models\LeaveConversionTable;
use App\Models\LeaveCredit;
use App\Models\LeaveCreditEntry;
use App\Models\LeavePolicyPreset;
use App\Models\LeaveType;
use App\Models\Role;
use App\Models\RoleModuleGrant;
use App\Models\User;
use App\Modules\Timekeeping\Application\Commands\ApproveConversionTable;
use App\Modules\Timekeeping\Application\Commands\RecordLeaveCredit;
use App\Modules\Timekeeping\Application\Commands\SaveConversionTable;
use App\Modules\Timekeeping\Application\Commands\SaveLeavePreset;
use App\Modules\Timekeeping\Application\Commands\SaveLeaveTypeCredits;
use App\Modules\Timekeeping\Application\ConversionTables;
use App\Modules\Timekeeping\Application\Queries\LeaveTypeCreditRecords;
use App\Modules\Timekeeping\Domain\Credits\MinuteConversion;
use App\Support\Access;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Livewire\Livewire;

/**
 * Leave Credits settings (Records › Leave Credits): each leave type's
 * credit rules, the minutes-to-days conversion tables and the policy
 * presets. Whoever manages Leave Settings (all-data RECORDS) or leave
 * (all-data LEAVES) changes them; any holder of either module reads them.
 * A conversion table is drafted by one person and approved by ANOTHER,
 * never changes once approved, and the approved version in force on a date
 * is the one that converts. Credits hold four decimals.
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

/** @return array<string, mixed> */
function lcsTableInput(string $from = '2026-11-01', string $rounding = 'HALF_DOWN'): array
{
    return ['effective_from' => $from, 'hours_per_day' => '8', 'decimals' => '3', 'rounding' => $rounding, 'reference' => 'Table II', 'rows' => MinuteConversion::rows(8, 3, $rounding)];
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
    $this->get('/records/leave-credits')->assertOk()->assertSee('Leave Credits')->assertSee('Your role can view these settings.');

    lcsActor(['RECORDS']);
    $this->get('/records/leave-credits')->assertOk()->assertSee('Minutes to days')->assertSee('Policy presets');
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

/*
|--------------------------------------------------------------------------
| Conversion tables
|--------------------------------------------------------------------------
*/

it('drafts a conversion table from the formula, lets the preparer approve it, and resolves the version in force by date', function () {
    $this->travelTo(new DateTimeImmutable('2026-11-10T10:00:00+08:00'));
    // The seeded preset (2026_11_02_0018) is cleared: this test drafts its own first version.
    LeaveConversionTable::query()->delete();
    $preparer = lcsActor();

    $page = Livewire::test(LeaveCreditSettingsPage::class)
        ->call('openTable')
        ->assertSet('tableOpen', true)
        ->assertSet('tableMinutes.30', '0.062')
        ->assertSet('tableHours.8', '1.000')
        ->set('tableForm.effective_from', '2026-11-01')
        ->set('tableForm.reference', 'Table II')
        ->set('tableMinutes.30', '0.063')
        ->call('saveTable')
        ->assertSet('tableOpen', false)
        ->assertSeeHtml('data-conversion-differences')
        ->assertSee('30 min: table 0.063, formula 0.062');
    $draft = LeaveConversionTable::query()->sole();
    expect($draft->status)->toBe('DRAFT')
        ->and($draft->created_by)->toBe((int) $preparer->userId)
        ->and($draft->rows['minutes']['30'] ?? $draft->rows['minutes'][30])->toBe('0.063')
        ->and(ConversionTables::inForce('2026-11-10'))->toBeNull();

    // The preparer approves their own draft from the page: one administrator runs the whole flow.
    $approver = $preparer;
    $page->call('approveTable', $draft->id);
    $draft->refresh();
    expect($draft->status)->toBe('APPROVED')
        ->and($draft->approved_by)->toBe((int) $approver->userId)
        ->and($draft->approved_at)->not->toBeNull()
        ->and(AuditLog::query()->where('action', 'LEAVE_CONVERSION_TABLE_APPROVED')->exists())->toBeTrue()
        ->and(ConversionTables::inForce('2026-10-31'))->toBeNull()
        ->and(ConversionTables::inForce('2026-11-10')?->id)->toBe($draft->id)
        ->and(ConversionTables::entry('2026-11-10')['rows']['minutes'][30])->toBe('0.063');

    // An approved version never changes, and a second version on the same date is refused.
    expect(fn () => app(SaveConversionTable::class)->handle($approver, $draft->id, lcsTableInput()))->toThrow(RuntimeException::class, 'never changes');
    expect(fn () => app(SaveConversionTable::class)->handle($approver, null, lcsTableInput()))->toThrow(RuntimeException::class, 'already takes effect');

    // A later version wins from its own date; a draft can be discarded.
    $later = app(SaveConversionTable::class)->handle($approver, null, lcsTableInput('2026-12-01', 'HALF_UP'));
    expect(ConversionTables::inForce('2026-12-15')?->id)->toBe($draft->id);
    app(ApproveConversionTable::class)->handle($preparer, $later);
    expect(ConversionTables::inForce('2026-12-15')?->id)->toBe($later)
        ->and(ConversionTables::inForce('2026-11-20')?->id)->toBe($draft->id);
    $discard = app(SaveConversionTable::class)->handle($approver, null, lcsTableInput('2027-01-01'));
    Livewire::test(LeaveCreditSettingsPage::class)->call('deleteTable', $discard);
    expect(LeaveConversionTable::query()->whereKey($discard)->exists())->toBeFalse()
        ->and(LeaveConversionTable::query()->count())->toBe(2);
});

it('refuses conversion rows that are not fractions of a day at the version\'s decimals', function () {
    LeaveConversionTable::query()->delete();
    $actor = lcsActor();
    $input = lcsTableInput();
    $input['rows']['minutes'][30] = '0.0625';
    expect(fn () => app(SaveConversionTable::class)->handle($actor, null, $input))->toThrow(RuntimeException::class, 'up to 3 decimals');
    $input = lcsTableInput();
    unset($input['rows']['hours'][8]);
    expect(fn () => app(SaveConversionTable::class)->handle($actor, null, $input))->toThrow(RuntimeException::class, 'Hours row 8 is missing');
    $input = lcsTableInput();
    $input['hours_per_day'] = '7.333';
    expect(fn () => app(SaveConversionTable::class)->handle($actor, null, $input))->toThrow(RuntimeException::class, 'whole number of minutes');
    expect(LeaveConversionTable::query()->count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| Presets and precision
|--------------------------------------------------------------------------
*/

it('saves a preset only over types whose rules allow it, and deletes it', function () {
    $actor = lcsActor();
    $vacation = lcsType('Vacation Leave');
    $sick = lcsType('Sick Leave');
    app(SaveLeaveTypeCredits::class)->handle($actor, $vacation->id, ['earns_credits' => true, 'unit' => 'DAYS', 'charge_late' => true, 'charge_absence' => true, 'charge_order' => '1', 'below_zero_limit' => '0']);
    app(SaveLeaveTypeCredits::class)->handle($actor, $sick->id, ['earns_credits' => true, 'unit' => 'DAYS', 'charge_late' => false, 'charge_absence' => true, 'charge_order' => '2', 'below_zero_limit' => '0']);

    $params = [
        'posting_day' => 'MONTH_END', 'unpaid_counts' => ['UNPAID_LEAVE', 'ABSENCE'],
        'earning' => [
            'VACATION_LEAVE' => ['amount' => '1.25', 'method' => 'TABLE', 'table' => [['up_to' => '0', 'credits' => '1.25'], ['up_to' => '1', 'credits' => '1.208'], ['up_to' => '2', 'credits' => '1.167']], 'partial' => 'PRO_RATA', 'cap' => null],
            'SICK_LEAVE' => ['amount' => '1.25', 'method' => 'FLAT', 'table' => [], 'partial' => 'FULL', 'cap' => '300'],
        ],
        'charges' => ['enabled' => true, 'combine' => 'DAY', 'late' => ['VACATION_LEAVE'], 'absence' => ['VACATION_LEAVE', 'SICK_LEAVE']],
    ];
    $message = app(SaveLeavePreset::class)->handle($actor, null, 'Regular', $params);
    $preset = LeavePolicyPreset::query()->sole();
    expect($message)->toBe('Preset "Regular" saved.')
        ->and($preset->params['earning']['VACATION_LEAVE']['table'][1])->toEqual(['up_to' => 1, 'credits' => 1.208])
        ->and($preset->params['earning']['SICK_LEAVE']['cap'])->toEqual(300)
        ->and($preset->params['charges']['absence'])->toBe(['VACATION_LEAVE', 'SICK_LEAVE'])
        ->and(AuditLog::query()->where('action', 'LEAVE_PRESET_SAVED')->value('details'))->toContain('Sick Leave 1.25 a month · Vacation Leave 1.25 a month (lookup table) · posts at month end · charges to credits: lateness Vacation Leave; absences Vacation Leave, Sick Leave');

    // Sick Leave may not be charged for lateness, so a preset that charges it is refused.
    $bad = $params;
    $bad['charges']['late'] = ['SICK_LEAVE'];
    expect(fn () => app(SaveLeavePreset::class)->handle($actor, null, 'Bad', $bad))->toThrow(RuntimeException::class, 'cannot be charged for lateness');
    $bad = $params;
    $bad['earning']['SPECIAL'] = $params['earning']['SICK_LEAVE'];
    expect(fn () => app(SaveLeavePreset::class)->handle($actor, null, 'Bad', $bad))->toThrow(RuntimeException::class, 'cannot earn credits');

    Livewire::test(LeaveCreditSettingsPage::class)
        ->assertSee('Regular')
        ->call('openPreset', $preset->id)
        ->assertSet('presetForm.name', 'Regular')
        ->assertSet('presetForm.earning.VACATION_LEAVE.method', 'TABLE')
        ->assertSet('presetForm.charge_absence.SICK_LEAVE', true)
        ->call('addPresetRow', 'VACATION_LEAVE')
        ->assertCount('presetForm.earning.VACATION_LEAVE.rows', 4)
        ->call('removePresetRow', 'VACATION_LEAVE', 3)
        ->assertCount('presetForm.earning.VACATION_LEAVE.rows', 3)
        ->set('presetForm.name', 'Regular staff')
        ->call('savePreset')
        ->assertSet('presetOpen', false);
    expect($preset->refresh()->name)->toBe('Regular staff');

    Livewire::test(LeaveCreditSettingsPage::class)->call('deletePreset', $preset->id);
    expect(LeavePolicyPreset::query()->count())->toBe(0);
});

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
