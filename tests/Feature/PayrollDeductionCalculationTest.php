<?php

declare(strict_types=1);

use App\Livewire\Records\ReferenceListPage;
use App\Models\AuditLog;
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
use App\Modules\Payroll\Domain\DeductionCalculation;
use App\Platform\Records\CatalogImport;
use App\Platform\Transfer\ImportOutcome;
use App\Platform\Transfer\ImportRow;
use App\Support\Access;
use Database\Seeders\ReferenceListSeeder;
use Illuminate\Database\UniqueConstraintViolationException;
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
function pdcActor(array $modules = ['RECORDS'], bool $allData = true): Access
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

it('lets Records rename a computed row, delete it to switch the deduction off, and add it back by name', function () {
    $actor = pdcActor();
    $sss = PayrollDeduction::query()->findOrFail('ded-1');
    $catalog = app(Deductions::class);

    // The shared save renames and leaves the calculation as it is.
    expect($catalog->save($actor, $sss->id, 'SSS Contribution', ''))->toBe('SSS Contribution updated.')
        ->and($sss->refresh()->calculation)->toBe('SSS')
        ->and($sss->name)->toBe('SSS Contribution');

    // The dialog offers no calculation: a new row is typed per employee,
    // unless it is named exactly as a computed deduction that has no row.
    $page = Livewire::test(ReferenceListPage::class, ['slug' => 'payroll-deductions']);
    expect($page->html())->not->toContain('>Calculation</th>')
        ->not->toContain('data-calculation-field')
        ->toContain('Delete · switches the deduction off');
    $page->call('openAdd')->set('formName', 'Cash Advance')->call('save');
    expect(PayrollDeduction::query()->where('name', 'Cash Advance')->sole()->calculation)->toBeNull();
    $page->call('openAdd')->set('formName', 'Pag-IBIG Loan')->call('save');
    expect(PayrollDeduction::query()->where('name', 'Pag-IBIG Loan')->sole()->calculation)->toBeNull();

    // A CSV import renames through the shared save and leaves the calculation as it is.
    $import = new CatalogImport('payroll-deductions', ReferenceListPage::LISTS['payroll-deductions']);
    $outcome = $import->row($actor, 'records.payroll-deductions', new ImportRow(2, ['ID' => '1', 'Name' => 'SSS']));
    expect($outcome)->toBe(ImportOutcome::UPDATED)
        ->and($sss->refresh()->name)->toBe('SSS')
        ->and($sss->calculation)->toBe('SSS');

    // Deleting a computed row: the typed figures drafts held over its
    // column are cleared with a required audit row per draft, the row goes,
    // and the deduction is off.
    $line = pdcCharge(PayrollDeduction::query()->where('name', 'Cash Advance')->sole());
    $member = $line->member;
    $member?->forceFill(['deduction_sss' => 12.5])->save();
    $page->call('confirmDelete', 'ded-1');
    expect($page->html())->toContain('switches the SSS deduction off')->toContain('named exactly "SSS"');
    $page->call('deleteConfirmed');
    expect(PayrollDeduction::query()->whereKey('ded-1')->exists())->toBeFalse()
        ->and($member?->refresh()->deduction_sss)->toBeNull()
        ->and(AuditLog::query()->where('action', 'REFERENCE_DELETED')->count())->toBe(1)
        ->and(AuditLog::query()->where('action', 'PAYROLL_RUN_UPDATED')->value('details'))
        ->toContain('1 typed SSS figure cleared because the deduction was deleted in Records and is switched off')
        ->and(PayrollData::activeCalculations())->toBe(['PHILHEALTH', 'PAGIBIG', 'WITHHOLDING_TAX', 'LATE_UNDERTIME']);

    // Adding a row named as the deduction is known (case aside) switches it
    // back on — while its calculation is free; a computed row that exists
    // keeps a same-named newcomer typed.
    $page->call('openAdd')->set('formName', ' sss ')->call('save');
    expect(PayrollDeduction::query()->where('name', 'sss')->sole()->calculation)->toBe('SSS')
        ->and(PayrollData::activeCalculations())->toBe(DeductionCalculation::all());
    expect($catalog->save($actor, null, 'Pag-IBIG Contribution', ''))->toBe('Pag-IBIG Contribution added.')
        ->and(PayrollDeduction::query()->where('name', 'Pag-IBIG Contribution')->sole()->calculation)->toBeNull();

    // The database keeps each calculation on one row, whatever writes it.
    expect(fn () => PayrollDeduction::query()->where('name', 'Pag-IBIG Contribution')->update(['calculation' => DeductionCalculation::PAGIBIG]))
        ->toThrow(UniqueConstraintViolationException::class);
});

it('refuses every delete to an actor without organization-wide Records access', function () {
    $loan = PayrollDeduction::create(['code' => '7', 'name' => 'SSS Loan']);
    $actor = pdcActor(['RECORDS'], allData: false);

    expect(fn () => app(Deductions::class)->delete($actor, $loan->id))
        ->toThrow(RuntimeException::class, 'Your role cannot manage organization-wide data.');
    expect(fn () => app(Deductions::class)->delete($actor, 'ded-1'))
        ->toThrow(RuntimeException::class, 'Your role cannot manage organization-wide data.');
    expect(PayrollDeduction::query()->count())->toBe(6)
        ->and(AuditLog::query()->count())->toBe(0);

    $records = pdcActor();
    expect(app(Deductions::class)->delete($records, $loan->id))->toBe('SSS Loan deleted.')
        ->and(PayrollDeduction::query()->count())->toBe(5);
});

it('seeds the five statutory rows with their calculation on a fresh install, twice over', function () {
    $this->seed(ReferenceListSeeder::class);
    $this->seed(ReferenceListSeeder::class);

    expect(PayrollDeduction::query()->orderBy('code')->pluck('calculation', 'id')->all())->toBe([
        seededId(PayrollDeduction::class, 'ded-1') => 'SSS', seededId(PayrollDeduction::class, 'ded-2') => 'PHILHEALTH', seededId(PayrollDeduction::class, 'ded-3') => 'PAGIBIG', seededId(PayrollDeduction::class, 'ded-4') => 'WITHHOLDING_TAX', seededId(PayrollDeduction::class, 'ded-5') => 'LATE_UNDERTIME',
        seededId(PayrollDeduction::class, 'ded-6') => null, seededId(PayrollDeduction::class, 'ded-7') => null, seededId(PayrollDeduction::class, 'ded-8') => null,
    ]);
    // Eight rows: five computed (the Late / Undertime one heads no column)
    // and three typed, every one a real row.
    $columns = DeductionColumns::fromCatalog();
    expect($columns->count())->toBe(8)
        ->and($columns->filter(static fn (DeductionColumnView $c): bool => $c->id === null)->count())->toBe(0)
        ->and($columns->filter(static fn (DeductionColumnView $c): bool => $c->isAutomatic())->count())->toBe(5)
        ->and($columns->filter(static fn (DeductionColumnView $c): bool => $c->headsColumn())->count())->toBe(7)
        ->and(PayrollData::activeCalculations())->toBe(DeductionCalculation::all());
});

it('keeps the other reference lists unaware of payroll usage', function () {
    pdcActor();

    expect(Livewire::test(ReferenceListPage::class, ['slug' => 'employment'])->html())
        ->not->toContain('used by payroll')
        ->not->toContain('finalized payroll');
});
