<?php

declare(strict_types=1);

use App\Livewire\Records\ReferenceListPage;
use App\Models\EmployeeProfile;
use App\Models\PayrollDeduction;
use App\Models\PayrollRun;
use App\Models\PayrollRunMember;
use App\Models\PayrollRunMemberDeduction;
use App\Models\Role;
use App\Models\RoleModuleGrant;
use App\Models\User;
use App\Modules\Payroll\Application\Catalogs\Deductions;
use App\Modules\Payroll\Application\DeductionColumns;
use App\Modules\Payroll\Application\PayrollData;
use App\Modules\Payroll\Contracts\Views\DeductionColumnView;
use App\Support\Access;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Livewire\Livewire;

/**
 * A Payroll Deductions row's `calculation` is what makes it a computed or
 * a typed column of the Basic Payroll. It is system data: the
 * migration tags the seeded rows (and survives a second run), the seeder
 * tags them on a fresh install, Records only renames such a row and can
 * never delete it, and the database keeps each calculation on one row.
 */
/** @param list<string> $modules */
function pdcActor(array $modules = ['PAYROLL'], bool $allData = true): Access
{
    $role = Role::create(['name' => 'Deduction calculation '.Str::random(6), 'all_data' => $allData, 'is_system' => false]);
    foreach ($modules as $module) {
        RoleModuleGrant::create(['role_id' => $role->id, 'module' => $module]);
    }
    test()->actingAs(User::factory()->create(['role_id' => $role->id]));
    app()->forgetInstance(Access::class);

    return app(Access::class);
}

/** A draft member charging the given row, so the row is "on a payroll". */
function pdcCharge(PayrollDeduction $row): PayrollRunMemberDeduction
{
    $employee = new EmployeeProfile;
    $employee->forceFill(['employee_code' => 'PDC-'.Str::upper(Str::random(6)), 'first_name' => 'Typed', 'last_name' => 'Row',
        'employment_status' => 'Regular', 'date_hired' => '2024-01-01', 'is_active' => true])->save();
    $run = new PayrollRun;
    $run->forceFill(['code' => 'PR-PDC-'.Str::upper(Str::random(5)), 'name' => 'Calculation draft '.Str::random(4),
        'period_start' => '2026-08-01', 'period_end' => '2026-08-15', 'status' => 'DRAFT'])->save();
    $member = PayrollRunMember::create(['run_id' => $run->id, 'employee_id' => $employee->id, 'position' => 0,
        'period_start' => '2026-08-01', 'period_end' => '2026-08-15']);

    return PayrollRunMemberDeduction::create(['member_id' => $member->id, 'deduction_id' => $row->id, 'amount' => 100]);
}

/** The migration under test, as `require` hands it back. */
function pdcMigration(): mixed
{
    return require base_path('database/migrations/2026_09_30_0010_add_calculation_to_payroll_deductions_table.php');
}

it('tags the seeded rows once, by id or by the seeded name, puts a missing one back, and survives a second run', function () {
    $m = pdcMigration();
    $m->down();
    expect(Schema::hasColumn('payroll_deductions', 'calculation'))->toBeFalse();

    // An installation seeded before the column existed: ded-1 is present;
    // ded-2 was deleted and re-added by hand under the seed's name; ded-3
    // is gone altogether; ded-4 is present but a draft already charges it as
    // a typed line.
    DB::table('payroll_deductions')->delete();
    DB::table('payroll_deductions')->insert([
        ['id' => 'ded-1', 'code' => '1', 'name' => 'SSS', 'description' => null, 'created_at' => '2026-01-01 00:00:00.000', 'updated_at' => null],
        ['id' => (string) Str::ulid(), 'code' => '2', 'name' => 'PhilHealth', 'description' => null, 'created_at' => '2026-01-01 00:00:00.000', 'updated_at' => null],
        ['id' => 'ded-4', 'code' => '4', 'name' => 'Withholding Tax', 'description' => null, 'created_at' => '2026-01-01 00:00:00.000', 'updated_at' => null],
        ['id' => 'ded-5', 'code' => '5', 'name' => 'Late / Undertime', 'description' => null, 'created_at' => '2026-01-01 00:00:00.000', 'updated_at' => null],
    ]);
    pdcCharge(PayrollDeduction::query()->findOrFail('ded-4'));

    $m->up();
    $m->up();

    $tags = PayrollDeduction::query()->pluck('calculation', 'code')->all();
    expect($tags['1'])->toBe('SSS')
        ->and($tags['2'])->toBe('PHILHEALTH')
        ->and($tags['4'])->toBeNull()
        ->and($tags['5'])->toBe('LATE_UNDERTIME')
        ->and(Schema::hasIndex('payroll_deductions', 'pded_calculation_unq'))->toBeTrue();

    // Pag-IBIG had no row: one was put back (id ded-3 was free, the next
    // code), so the deduction stays on. Withholding Tax's name is held by
    // the typed row a draft charges, so nothing could be inserted: that
    // deduction is off until a row of that name is added.
    $pagibig = PayrollDeduction::query()->where('calculation', 'PAGIBIG')->sole();
    expect($pagibig->id)->toBe('ded-3')
        ->and($pagibig->code)->toBe('6')
        ->and($pagibig->name)->toBe('Pag-IBIG')
        ->and(PayrollDeduction::query()->where('calculation', 'WITHHOLDING_TAX')->exists())->toBeFalse()
        ->and(PayrollDeduction::query()->count())->toBe(5);

    // No fallback columns: the list is the whole truth. Code order, the
    // Late / Undertime row heading none, the typed ded-4 typed.
    $columns = DeductionColumns::fromCatalog();
    expect($columns->map(static fn (DeductionColumnView $c): string => $c->name)->all())
        ->toBe(['SSS', 'PhilHealth', 'Withholding Tax', 'Late / Undertime', 'Pag-IBIG'])
        ->and($columns->filter(static fn (DeductionColumnView $c): bool => $c->headsColumn())->count())->toBe(4)
        ->and($columns->get(2)->calculation)->toBeNull()
        ->and($columns->get(2)->id)->toBe('ded-4')
        ->and(PayrollData::activeCalculations())->toBe(['SSS', 'PHILHEALTH', 'PAGIBIG', 'LATE_UNDERTIME']);

    $m->down();
    $m->down();
    expect(Schema::hasColumn('payroll_deductions', 'calculation'))->toBeFalse();
    $m->up();
});

it('keeps the other reference lists unaware of payroll usage', function () {
    pdcActor();

    expect(Livewire::test(ReferenceListPage::class, ['slug' => 'employment'])->html())
        ->not->toContain('used by payroll')
        ->not->toContain('finalized payroll');
});
