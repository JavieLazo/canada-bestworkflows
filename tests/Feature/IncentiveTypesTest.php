<?php

declare(strict_types=1);

use App\Livewire\Records\IncentiveTypesPage;
use App\Models\AuditLog;
use App\Models\EmployeeProfile;
use App\Models\PayrollIncentiveType;
use App\Models\PayrollRun;
use App\Models\PayrollRunMember;
use App\Models\PayrollRunMemberIncentive;
use App\Models\Role;
use App\Models\RoleModuleGrant;
use App\Models\User;
use App\Modules\Payroll\Application\Commands\DeleteIncentiveType;
use App\Modules\Payroll\Application\Commands\SaveIncentiveType;
use App\Support\Access;
use Database\Seeders\IncentiveTypeSeeder;
use Illuminate\Support\Str;
use Livewire\Livewire;

/**
 * The incentive and bonus catalog under Records → Payroll Settings. The page
 * opens for PAYROLL holders and every write needs all-data PAYROLL
 * (PayrollSettingsAccess: whoever manages payroll manages its settings),
 * checked inside the Payroll module's commands; a Records holder without
 * Payroll is turned away, its old address included. Ids are minted as bare numbers under a locked
 * scan; a type named by any payroll line cannot be deleted; the seeder only
 * ever adds.
 */

/** @param list<string> $modules */
function inctypeActor(array $modules, bool $allData = true): Access
{
    $role = new Role;
    $role->forceFill(['name' => 'IncType '.Str::random(6), 'all_data' => $allData, 'is_system' => false])->save();
    foreach ($modules as $module) {
        $grant = new RoleModuleGrant;
        $grant->forceFill(['role_id' => $role->getKey(), 'module' => $module])->save();
    }

    $user = User::factory()->create(['role_id' => $role->getKey()]);
    test()->actingAs($user);
    app()->forgetInstance(Access::class);

    return Access::forActor($user);
}

function inctypeUsed(PayrollIncentiveType $type): void
{
    $employee = new EmployeeProfile;
    $employee->forceFill(['employee_code' => 'ITY-'.random_int(1000, 9999), 'first_name' => 'In', 'last_name' => 'Use',
        'employment_status' => 'Regular', 'date_hired' => '2024-01-01', 'is_active' => true])->save();
    $run = new PayrollRun;
    $run->forceFill(['code' => 'PR-ITY', 'name' => 'Uses a type', 'period_start' => '2026-08-01', 'period_end' => '2026-08-15', 'status' => 'DRAFT'])->save();
    $member = PayrollRunMember::create(['run_id' => $run->id, 'employee_id' => $employee->id, 'position' => 0,
        'period_start' => '2026-08-01', 'period_end' => '2026-08-15']);
    PayrollRunMemberIncentive::create(['member_id' => $member->id, 'type_id' => $type->id, 'amount' => 500]);
}

/* |---| The page |---| */

it('opens the page under Payroll Settings for PAYROLL holders and nobody else', function () {
    $this->get('/records/payroll-settings/incentive-types')->assertRedirect(route('login'));

    inctypeActor(['PORTAL']);
    $this->get('/records/payroll-settings/incentive-types')->assertRedirect();

    inctypeActor(['PAYROLL'], allData: false);
    $this->get('/records/payroll-settings/incentive-types')->assertOk()->assertSee('Incentives &amp; Bonuses', false);

    // Records Management alone opens neither the page nor its former address.
    inctypeActor(['RECORDS'], allData: false);
    $this->get('/records/payroll-settings/incentive-types')->assertRedirect();
    $this->get('/records/incentive-types')->assertRedirect('/records/positions');

    // A PAYROLL holder's old bookmark forwards.
    inctypeActor(['PAYROLL'], allData: false);
    $this->get('/records/incentive-types')->assertRedirect('/records/payroll-settings/incentive-types');
});

it('lists the catalog and offers edits only to an all-data holder', function () {
    $actor = inctypeActor(['PAYROLL'], allData: false);
    PayrollIncentiveType::create(['code' => '1', 'name' => 'Christmas Bonus', 'is_taxable' => false]);

    Livewire::test(IncentiveTypesPage::class)
        ->assertSee('Christmas Bonus')
        ->assertSee('Tax-exempt')
        ->assertDontSee('Add Incentive Type');

    inctypeActor(['PAYROLL']);
    Livewire::test(IncentiveTypesPage::class)->assertSee('Add Incentive Type');
    expect($actor->userId)->not->toBeNull();
});

it('adds, edits and deletes a type through the page', function () {
    inctypeActor(['PAYROLL']);

    Livewire::test(IncentiveTypesPage::class)
        ->call('openAdd')
        ->set('formName', '  Christmas Bonus ')
        ->set('formDescription', 'Year-end')
        ->set('formTax', 'EXEMPT')
        ->call('save')
        ->assertSet('dialogOpen', false);

    $type = PayrollIncentiveType::query()->sole();
    expect([$type->code, $type->name, $type->description, $type->is_taxable])->toBe(['1', 'Christmas Bonus', 'Year-end', false]);

    Livewire::test(IncentiveTypesPage::class)
        ->call('startEdit', $type->id)
        ->assertSet('formName', 'Christmas Bonus')
        ->set('formTax', 'TAXABLE')
        ->call('save');
    expect($type->fresh()?->is_taxable)->toBeTrue()
        ->and(AuditLog::query()->where('action', 'INCENTIVE_TYPE_UPDATED')->sole()->details)
        ->toBe('Incentive type 1 "Christmas Bonus" updated; now taxable');

    Livewire::test(IncentiveTypesPage::class)
        ->call('confirmDelete', $type->id)
        ->call('deleteConfirmed');
    expect(PayrollIncentiveType::query()->count())->toBe(0)
        ->and(AuditLog::query()->where('action', 'INCENTIVE_TYPE_DELETED')->count())->toBe(1);
});

/* |---| The commands, with no screen |---| */

it('lets an all-data PAYROLL holder write, and refuses everyone else, Records all-data included', function (string $who, bool $allowed) {
    $actor = match ($who) {
        'RECORDS all-data' => inctypeActor(['RECORDS']),
        'PAYROLL all-data' => inctypeActor(['PAYROLL']),
        'RECORDS without all-data' => inctypeActor(['RECORDS'], false),
        'PAYROLL without all-data' => inctypeActor(['PAYROLL'], false),
        default => inctypeActor(['EDTR']),
    };
    $save = fn () => app(SaveIncentiveType::class)->handle($actor, null, 'Commission', null, true);

    if ($allowed) {
        expect($save())->toBe('Commission added.')
            ->and(PayrollIncentiveType::query()->count())->toBe(1);

        return;
    }

    expect($save)->toThrow(RuntimeException::class, 'Your role cannot manage organization-wide data.');
    $type = PayrollIncentiveType::create(['code' => '9', 'name' => 'Existing', 'is_taxable' => true]);
    expect(fn () => app(DeleteIncentiveType::class)->handle($actor, $type->id))
        ->toThrow(RuntimeException::class, 'Your role cannot manage organization-wide data.');
    expect(PayrollIncentiveType::query()->count())->toBe(1)
        ->and(AuditLog::query()->count())->toBe(0);
})->with([
    'RECORDS all-data' => ['RECORDS all-data', false],
    'PAYROLL all-data' => ['PAYROLL all-data', true],
    'RECORDS without all-data' => ['RECORDS without all-data', false],
    'PAYROLL without all-data' => ['PAYROLL without all-data', false],
    'all-data without either' => ['all-data without either', false],
]);

it('mints the next bare number over the whole catalog and never reuses a live one', function () {
    $actor = inctypeActor(['PAYROLL']);
    PayrollIncentiveType::create(['code' => '7', 'name' => 'Held by HR', 'is_taxable' => true]);

    app(SaveIncentiveType::class)->handle($actor, null, 'Commission', null, true);
    app(SaveIncentiveType::class)->handle($actor, null, 'Attendance Incentive', '', true);

    expect(PayrollIncentiveType::query()->orderBy('name')->pluck('code', 'name')->all())
        ->toBe(['Attendance Incentive' => '9', 'Commission' => '8', 'Held by HR' => '7'])
        ->and(PayrollIncentiveType::query()->where('name', 'Attendance Incentive')->sole()->description)->toBeNull();
});

it('refuses a blank, long or duplicate name and a long description', function () {
    $actor = inctypeActor(['PAYROLL']);
    $existing = PayrollIncentiveType::create(['code' => '1', 'name' => 'Commission', 'is_taxable' => true]);
    $other = PayrollIncentiveType::create(['code' => '2', 'name' => 'Other Incentive', 'is_taxable' => true]);
    $save = app(SaveIncentiveType::class);

    expect(fn () => $save->handle($actor, null, '   ', null, true))->toThrow(RuntimeException::class, 'Name is required.')
        ->and(fn () => $save->handle($actor, null, str_repeat('x', 101), null, true))->toThrow(RuntimeException::class, 'Keep the name to 100 characters.')
        ->and(fn () => $save->handle($actor, null, 'Bonus', str_repeat('x', 501), true))->toThrow(RuntimeException::class, 'Keep the description to 500 characters.')
        ->and(fn () => $save->handle($actor, null, 'Commission', null, false))->toThrow(RuntimeException::class, 'An incentive type with that name already exists.')
        ->and(fn () => $save->handle($actor, $other->id, 'Commission', null, true))->toThrow(RuntimeException::class, 'An incentive type with that name already exists.')
        ->and(fn () => $save->handle($actor, 'missing', 'Anything', null, true))->toThrow(RuntimeException::class, 'That incentive type no longer exists.');

    // An unchanged edit writes nothing.
    expect($save->handle($actor, $existing->id, 'Commission', null, true))->toBe('Nothing changed.')
        ->and(AuditLog::query()->count())->toBe(0);
});

it('refuses to delete a type any payroll line names', function () {
    $actor = inctypeActor(['PAYROLL']);
    $type = PayrollIncentiveType::create(['code' => '1', 'name' => 'Commission', 'is_taxable' => true]);
    inctypeUsed($type);

    expect(fn () => app(DeleteIncentiveType::class)->handle($actor, $type->id))
        ->toThrow(RuntimeException::class, 'Commission is on a payroll, so it cannot be deleted. Rename it instead.');
    expect(PayrollIncentiveType::query()->count())->toBe(1);

    $html = Livewire::test(IncentiveTypesPage::class)->html();
    expect($html)->toContain('Delete · in use by 1');
});

/* |---| The seeder |---| */

it('seeds the six starting types once and never overwrites what HR changed', function () {
    test()->seed(IncentiveTypeSeeder::class);

    expect(PayrollIncentiveType::query()->orderBy('code')->get()->map(static fn (PayrollIncentiveType $t): array => [$t->code, $t->name, $t->is_taxable])->all())
        ->toBe([
            ['1', 'Performance Bonus', false], ['2', 'Christmas Bonus', false], ['3', 'Productivity Incentive', false],
            ['4', 'Commission', true], ['5', 'Attendance Incentive', true], ['6', 'Other Incentive', true],
        ]);

    // HR renames one and re-flags another; a re-run changes neither and adds nothing.
    PayrollIncentiveType::query()->whereKey(seededId(PayrollIncentiveType::class, 'ity-2'))->update(['name' => 'Year-end Bonus']);
    PayrollIncentiveType::query()->whereKey(seededId(PayrollIncentiveType::class, 'ity-4'))->update(['is_taxable' => false]);
    test()->seed(IncentiveTypeSeeder::class);

    expect(PayrollIncentiveType::query()->count())->toBe(6)
        ->and(PayrollIncentiveType::query()->findOrFail(seededId(PayrollIncentiveType::class, 'ity-2'))->name)->toBe('Year-end Bonus')
        ->and(PayrollIncentiveType::query()->findOrFail(seededId(PayrollIncentiveType::class, 'ity-4'))->is_taxable)->toBeFalse();
});

it('seeds around a type HR created first under a seeded name or code', function () {
    PayrollIncentiveType::create(['code' => '1', 'name' => 'Commission', 'is_taxable' => true]);

    test()->seed(IncentiveTypeSeeder::class);

    // Commission is not seeded twice, and the others take the codes after HR's.
    expect(PayrollIncentiveType::query()->where('name', 'Commission')->count())->toBe(1)
        ->and(PayrollIncentiveType::query()->count())->toBe(6)
        ->and(PayrollIncentiveType::query()->pluck('code')->sort()->values()->all())->toBe(['1', '2', '3', '4', '5', '6']);
});
