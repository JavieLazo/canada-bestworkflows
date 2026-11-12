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
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * MariaDB 11.4 proves each interrupted schema step resumes, the amount
 * CHECKs are real, catalog deletion cannot change a draft, and posted copies
 * survive catalog edits or deletion.
 */

/** @return array{draft: mixed, posted: mixed} */
function cdtschemaMigrations(): array
{
    return [
        'draft' => require base_path('database/migrations/2026_09_28_0003_create_payroll_run_member_deductions_table.php'),
        'posted' => require base_path('database/migrations/2026_09_28_0004_create_payslip_deductions_table.php'),
    ];
}

function cdtschemaCheck(string $table, string $name): int
{
    $row = DB::selectOne(
        "SELECT COUNT(*) AS n FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ? AND CONSTRAINT_TYPE = 'CHECK'",
        [$table, $name],
    );

    return (int) ($row->n ?? 0);
}

/** @return array{member: PayrollRunMember, payslip: Payslip, catalog: PayrollDeduction} */
function cdtschemaRows(): array
{
    $employee = new EmployeeProfile;
    $employee->forceFill(['employee_code' => 'CDS-'.Str::upper(Str::random(7)), 'first_name' => 'Custom', 'last_name' => 'Schema',
        'employment_status' => 'Regular', 'date_hired' => '2024-01-01', 'is_active' => true])->save();
    $run = new PayrollRun;
    $run->forceFill(['code' => 'PR-CDS-'.Str::upper(Str::random(5)), 'name' => 'Deduction schema',
        'period_start' => '2026-08-01', 'period_end' => '2026-08-15', 'status' => 'DRAFT'])->save();
    $member = PayrollRunMember::create(['run_id' => $run->id, 'employee_id' => $employee->id, 'position' => 0,
        'period_start' => '2026-08-01', 'period_end' => '2026-08-15']);
    $payslip = new Payslip;
    $payslip->forceFill(['run_id' => $run->id, 'employee_id' => $employee->id, 'period_start' => '2026-08-01',
        'period_end' => '2026-08-15', 'gross_pay' => 1000, 'custom_deductions' => 100,
        'total_deductions' => 100, 'net_pay' => 900])->save();
    $catalog = PayrollDeduction::create(['code' => Str::upper(Str::random(8)), 'name' => 'Union dues']);

    return compact('member', 'payslip', 'catalog');
}

it('resumes both tables after CREATE and restores every index, foreign key and CHECK', function () {
    $m = cdtschemaMigrations();

    try {
        $m['posted']->down();
        $m['draft']->down();

        // Simulate a cron killed immediately after each CREATE statement.
        Schema::create('payroll_run_member_deductions', function (Blueprint $t): void {
            $t->engine = 'InnoDB';
            $t->char('id', 26)->primary();
            $t->char('member_id', 26);
            $t->char('deduction_id', 26);
            $t->decimal('amount', 12, 2);
            $t->dateTime('created_at', 3);
        });
        Schema::create('payslip_deductions', function (Blueprint $t): void {
            $t->engine = 'InnoDB';
            $t->char('id', 26)->primary();
            $t->char('payslip_id', 26);
            $t->unsignedSmallInteger('position');
            $t->string('deduction_code', 40);
            $t->string('name', 160);
            $t->decimal('amount', 12, 2);
            $t->dateTime('created_at', 3);
        });

        $m['draft']->up();
        $m['posted']->up();
        $m['draft']->up();
        $m['posted']->up();

        expect(Schema::hasIndex('payroll_run_member_deductions', 'prmd_member_deduction_unq', 'unique'))->toBeTrue()
            ->and(Schema::hasIndex('payroll_run_member_deductions', 'prmd_deduction_idx'))->toBeTrue()
            ->and(Schema::hasForeignKey('payroll_run_member_deductions', ['member_id']))->toBeTrue()
            ->and(Schema::hasForeignKey('payroll_run_member_deductions', ['deduction_id']))->toBeTrue()
            ->and(Schema::hasIndex('payslip_deductions', 'psd_payslip_idx'))->toBeTrue()
            ->and(Schema::hasForeignKey('payslip_deductions', ['payslip_id']))->toBeTrue()
            ->and(Schema::hasColumn('payslips', 'custom_deductions'))->toBeTrue()
            ->and(cdtschemaCheck('payroll_run_member_deductions', 'prmd_amount_chk'))->toBe(1)
            ->and(cdtschemaCheck('payslip_deductions', 'psd_amount_chk'))->toBe(1);

        // A second interrupted position: the only missing piece is one CHECK.
        DB::statement('ALTER TABLE `payslip_deductions` DROP CONSTRAINT `psd_amount_chk`');
        $m['posted']->up();
        expect(cdtschemaCheck('payslip_deductions', 'psd_amount_chk'))->toBe(1);
    } finally {
        $m['draft']->up();
        $m['posted']->up();
        // These creates shipped `amount > 0`; the schema after them allows 0.
        (require base_path('database/migrations/2026_11_11_0004_allow_zero_custom_deduction_lines_table.php'))->up();
    }
});

it('refuses a negative line, keeps one draft amount per catalog entry, and a stable posted copy', function () {
    ['member' => $member, 'payslip' => $payslip, 'catalog' => $catalog] = cdtschemaRows();

    expect(fn () => PayrollRunMemberDeduction::create(['member_id' => $member->id,
        'deduction_id' => $catalog->id, 'amount' => -1]))->toThrow(QueryException::class, 'prmd_amount_chk');
    expect(fn () => PayslipDeduction::create(['payslip_id' => $payslip->id, 'position' => 0,
        'deduction_code' => $catalog->code, 'name' => $catalog->name, 'amount' => -1]))->toThrow(QueryException::class, 'psd_amount_chk');

    $draft = PayrollRunMemberDeduction::create(['member_id' => $member->id, 'deduction_id' => $catalog->id, 'amount' => 100]);
    expect(fn () => PayrollRunMemberDeduction::create(['member_id' => $member->id,
        'deduction_id' => $catalog->id, 'amount' => 50]))->toThrow(QueryException::class, 'prmd_member_deduction_unq');
    $posted = PayslipDeduction::create(['payslip_id' => $payslip->id, 'position' => 0,
        'deduction_code' => $catalog->code, 'name' => $catalog->name, 'amount' => 100]);

    try {
        $catalog->delete();
        test()->fail('A catalog row still named by a draft must be retained.');
    } catch (QueryException $e) {
        expect((int) ($e->errorInfo[1] ?? 0))->toBe(1451);
    }

    $draft->delete();
    $catalog->update(['name' => 'New label']);
    expect($posted->fresh()?->name)->toBe('Union dues');
    $catalog->delete();
    expect($posted->fresh()?->name)->toBe('Union dues')
        ->and($posted->fresh()?->deduction_code)->toBe($posted->deduction_code)
        ->and($payslip->fresh()?->custom_deductions)->toBe(100.0);

    $member->delete();
    $payslip->delete();
    expect(PayrollRunMemberDeduction::query()->count())->toBe(0)
        ->and(PayslipDeduction::query()->count())->toBe(0);
});
