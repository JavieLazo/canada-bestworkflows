<?php

declare(strict_types=1);

use App\Models\EmployeeProfile;
use App\Models\PayrollDeduction;
use App\Models\PayrollRun;
use App\Models\PayrollRunMember;
use App\Models\PayrollRunMemberDeduction;
use App\Models\Payslip;
use App\Models\PayslipDeduction;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * MariaDB 11.4 proves a named deduction line may be ₱0.00 and never negative
 * (2026_11_11_0004), that each interrupted step resumes, and that the
 * rollback tightens a table only when no ₱0 line would break it. sqlite
 * creates no CHECK, so only this suite can show it.
 */
function zdlMigration(): mixed
{
    return require base_path('database/migrations/2026_11_11_0004_allow_zero_custom_deduction_lines_table.php');
}

function zdlClause(string $name): ?string
{
    $row = DB::selectOne('SELECT CHECK_CLAUSE AS clause_text FROM information_schema.CHECK_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_NAME = ?', [$name]);

    return $row === null ? null : (string) $row->clause_text;
}

/** @return array{member: PayrollRunMember, payslip: Payslip, catalog: PayrollDeduction} */
function zdlRows(): array
{
    $employee = new EmployeeProfile;
    $employee->forceFill(['employee_code' => 'ZDL-'.Str::upper(Str::random(7)), 'first_name' => 'Zero', 'last_name' => 'Line',
        'employment_status' => 'Regular', 'date_hired' => '2024-01-01', 'is_active' => true])->save();
    $run = new PayrollRun;
    $run->forceFill(['code' => 'PR-ZDL-'.Str::upper(Str::random(5)), 'name' => 'Zero line', 'period_start' => '2026-08-01', 'period_end' => '2026-08-15', 'status' => 'DRAFT'])->save();
    $member = PayrollRunMember::create(['run_id' => $run->id, 'employee_id' => $employee->id, 'position' => 0, 'period_start' => '2026-08-01', 'period_end' => '2026-08-15']);
    $payslip = new Payslip;
    $payslip->forceFill(['run_id' => $run->id, 'employee_id' => $employee->id, 'period_start' => '2026-08-01', 'period_end' => '2026-08-15',
        'gross_pay' => 1000, 'custom_deductions' => 0, 'total_deductions' => 0, 'net_pay' => 1000])->save();
    $catalog = PayrollDeduction::create(['code' => Str::upper(Str::random(8)), 'name' => 'Provident Fund']);

    return compact('member', 'payslip', 'catalog');
}

it('allows a zero line and still refuses a negative one', function () {
    ['member' => $member, 'payslip' => $payslip, 'catalog' => $catalog] = zdlRows();

    $draft = PayrollRunMemberDeduction::create(['member_id' => $member->id, 'deduction_id' => $catalog->id, 'amount' => 0]);
    $posted = PayslipDeduction::create(['payslip_id' => $payslip->id, 'position' => 0, 'deduction_code' => $catalog->code, 'name' => $catalog->name, 'amount' => 0, 'employer_amount' => 450]);
    expect($draft->exists)->toBeTrue()
        ->and($posted->fresh()?->amount)->toBe(0.0)
        ->and($posted->fresh()?->employer_amount)->toBe(450.0);

    try {
        DB::transaction(fn () => PayslipDeduction::create(['payslip_id' => $payslip->id, 'position' => 1, 'deduction_code' => $catalog->code, 'name' => $catalog->name, 'amount' => -1]));
        $this->fail('A negative posted line was accepted.');
    } catch (QueryException $e) {
        expect($e->getMessage())->toContain('psd_amount_chk');
    }
    $other = PayrollDeduction::create(['code' => Str::upper(Str::random(8)), 'name' => 'Union dues']);
    try {
        DB::transaction(fn () => PayrollRunMemberDeduction::create(['member_id' => $member->id, 'deduction_id' => $other->id, 'amount' => -1]));
        $this->fail('A negative draft line was accepted.');
    } catch (QueryException $e) {
        expect($e->getMessage())->toContain('prmd_amount_chk');
    }
});

it('resumes each interrupted step and is safe to run twice', function () {
    $m = zdlMigration();
    try {
        $m->up();
        $m->up();
        expect(zdlClause('prmd_amount_chk'))->toContain('>=')
            ->and(zdlClause('psd_amount_chk'))->toContain('>=');

        // Killed between the DROP and the ADD: only the ADD is left.
        DB::statement('ALTER TABLE `payslip_deductions` DROP CONSTRAINT `psd_amount_chk`');
        expect(zdlClause('psd_amount_chk'))->toBeNull();
        $m->up();
        expect(zdlClause('psd_amount_chk'))->toContain('>=');

        // Not yet run on this table: the old constraint is replaced.
        DB::statement('ALTER TABLE `payroll_run_member_deductions` DROP CONSTRAINT `prmd_amount_chk`');
        DB::statement('ALTER TABLE `payroll_run_member_deductions` ADD CONSTRAINT `prmd_amount_chk` CHECK (`amount` > 0)');
        $m->up();
        expect(zdlClause('prmd_amount_chk'))->toContain('>=');
    } finally {
        $m->up();
    }
});

it('rolls back to a positive amount, twice over, with no zero line present', function () {
    $m = zdlMigration();
    try {
        $m->down();
        $m->down();
        expect(zdlClause('prmd_amount_chk'))->not->toContain('>=')
            ->and(zdlClause('psd_amount_chk'))->not->toContain('>=');
    } finally {
        $m->up();
    }
    expect(zdlClause('psd_amount_chk'))->toContain('>=');
});
