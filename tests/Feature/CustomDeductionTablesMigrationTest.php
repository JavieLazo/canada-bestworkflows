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
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Both custom-deduction tables survive a repeated migration call. Their
 * rollback loses only draft and posted itemization, not the payroll itself.
 */

/** @return array{draft: mixed, posted: mixed} */
function cdtmigMigrations(): array
{
    return [
        'draft' => require base_path('database/migrations/2026_09_28_0003_create_payroll_run_member_deductions_table.php'),
        'posted' => require base_path('database/migrations/2026_09_28_0004_create_payslip_deductions_table.php'),
    ];
}

/** @param array{draft: mixed, posted: mixed} $m */
function cdtmigUp(array $m): void
{
    $m['draft']->up();
    $m['posted']->up();
}

/** @param array{draft: mixed, posted: mixed} $m */
function cdtmigDown(array $m): void
{
    $m['posted']->down();
    $m['draft']->down();
}

/** @return array{member: PayrollRunMember, payslip: Payslip, catalog: PayrollDeduction} */
function cdtmigRows(): array
{
    $employee = new EmployeeProfile;
    $employee->forceFill(['employee_code' => 'CDT-'.Str::upper(Str::random(7)), 'first_name' => 'Custom', 'last_name' => 'Deduction',
        'employment_status' => 'Regular', 'date_hired' => '2024-01-01', 'is_active' => true])->save();
    $run = new PayrollRun;
    $run->forceFill(['code' => 'PR-CDT-'.Str::upper(Str::random(5)), 'name' => 'Deduction migration',
        'period_start' => '2026-08-01', 'period_end' => '2026-08-15', 'status' => 'DRAFT'])->save();
    $member = PayrollRunMember::create(['run_id' => $run->id, 'employee_id' => $employee->id, 'position' => 0,
        'period_start' => '2026-08-01', 'period_end' => '2026-08-15']);
    $payslip = new Payslip;
    $payslip->forceFill(['run_id' => $run->id, 'employee_id' => $employee->id, 'period_start' => '2026-08-01',
        'period_end' => '2026-08-15', 'gross_pay' => 1000, 'total_deductions' => 100, 'net_pay' => 900])->save();
    $catalog = PayrollDeduction::create(['code' => Str::upper(Str::random(8)), 'name' => 'Union dues']);

    return compact('member', 'payslip', 'catalog');
}

it('restores both custom-deduction tables after repeated rollback and migration', function () {
    $m = cdtmigMigrations();

    try {
        ['member' => $member, 'payslip' => $payslip, 'catalog' => $catalog] = cdtmigRows();
        PayrollRunMemberDeduction::create(['member_id' => $member->id, 'deduction_id' => $catalog->id, 'amount' => 100]);
        PayslipDeduction::create(['payslip_id' => $payslip->id, 'position' => 0, 'deduction_code' => $catalog->code,
            'name' => $catalog->name, 'amount' => 100]);

        cdtmigDown($m);
        cdtmigDown($m);
        expect(Schema::hasTable('payroll_run_member_deductions'))->toBeFalse()
            ->and(Schema::hasTable('payslip_deductions'))->toBeFalse()
            ->and(Schema::hasColumn('payslips', 'custom_deductions'))->toBeFalse();

        cdtmigUp($m);
        cdtmigUp($m);
        expect(Schema::hasIndex('payroll_run_member_deductions', 'prmd_member_deduction_unq', 'unique'))->toBeTrue()
            ->and(Schema::hasIndex('payroll_run_member_deductions', 'prmd_deduction_idx'))->toBeTrue()
            ->and(Schema::hasForeignKey('payroll_run_member_deductions', ['member_id']))->toBeTrue()
            ->and(Schema::hasForeignKey('payroll_run_member_deductions', ['deduction_id']))->toBeTrue()
            ->and(Schema::hasIndex('payslip_deductions', 'psd_payslip_idx'))->toBeTrue()
            ->and(Schema::hasForeignKey('payslip_deductions', ['payslip_id']))->toBeTrue()
            ->and(Schema::hasColumn('payslips', 'custom_deductions'))->toBeTrue();

        expect(PayrollRunMemberDeduction::query()->count())->toBe(0)
            ->and(PayslipDeduction::query()->count())->toBe(0)
            ->and(PayrollRunMember::query()->whereKey($member->id)->exists())->toBeTrue()
            ->and(Payslip::query()->whereKey($payslip->id)->exists())->toBeTrue();

        PayrollRunMemberDeduction::create(['member_id' => $member->id, 'deduction_id' => $catalog->id, 'amount' => 50]);
        expect(fn () => PayrollRunMemberDeduction::create(['member_id' => $member->id, 'deduction_id' => $catalog->id,
            'amount' => 70]))->toThrow(QueryException::class);
    } finally {
        cdtmigUp($m);
    }
});
