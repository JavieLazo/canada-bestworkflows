<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * NEW TABLE — it has no counterpart in prisma/schema.prisma.
 *
 * EACH EMPLOYEE'S CONTRIBUTIONS & TAX POLICY as dated VERSIONS: how SSS,
 * PhilHealth, Pag-IBIG and withholding tax are deducted for them (the
 * sixteen switches, fixed figures and schedules the engine reads off the
 * employee, `App\Modules\Payroll\Domain\Deductions\GovDeductionSettings`)
 * and the rate of their own on each percentage Payroll Deduction (the
 * Provident Fund share an employee chooses). One row per employee and
 * effective date, holding the `params`, the `reason`, who PREPARED it
 * (status DRAFT) and who APPROVED it — a different person — after which it
 * is in force from its date until a later approved version's and never
 * changes. The employee's profile columns stay as the baseline an employee
 * with no approved version is still priced by; no screen edits them.
 *
 * RETRY-SAFE: CREATE, each index, each FK and each CHECK have their own
 * guard; indexes precede their FKs. The employee key RESTRICTS deletion:
 * a policy is pay history. down() drops the table; employees with no
 * version then read their baseline again.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('payroll_gov_deduction_policies')) {
            Schema::create('payroll_gov_deduction_policies', static function (Blueprint $t): void {
                $t->engine = 'InnoDB';
                $t->char('id', 26)->primary();
                $t->char('employee_id', 26);
                $t->date('effective_from');
                $t->json('params');
                $t->string('reason', 160);
                $t->string('status', 10);
                $t->unsignedBigInteger('prepared_by')->nullable();
                $t->string('prepared_by_name', 200)->nullable();
                $t->unsignedBigInteger('approved_by')->nullable();
                $t->string('approved_by_name', 200)->nullable();
                $t->dateTime('approved_at', 3)->nullable();
                $t->dateTime('created_at', 3);
                $t->dateTime('updated_at', 3)->nullable();
            });
        }
        if (! Schema::hasIndex('payroll_gov_deduction_policies', 'pgdp_employee_from_unq')) {
            Schema::table('payroll_gov_deduction_policies', static fn (Blueprint $t) => $t->unique(['employee_id', 'effective_from'], 'pgdp_employee_from_unq'));
        }
        if (! Schema::hasIndex('payroll_gov_deduction_policies', 'pgdp_status_idx')) {
            Schema::table('payroll_gov_deduction_policies', static fn (Blueprint $t) => $t->index(['status', 'effective_from'], 'pgdp_status_idx'));
        }
        if (! Schema::hasForeignKey('payroll_gov_deduction_policies', ['employee_id'])) {
            Schema::table('payroll_gov_deduction_policies', static fn (Blueprint $t) => $t->foreign('employee_id', 'pgdp_employee_fk')->references('id')->on('employee_profiles')->restrictOnDelete());
        }
        if (! Schema::hasForeignKey('payroll_gov_deduction_policies', ['prepared_by'])) {
            Schema::table('payroll_gov_deduction_policies', static fn (Blueprint $t) => $t->foreign('prepared_by', 'pgdp_prepared_by_fk')->references('id')->on('users')->nullOnDelete());
        }
        if (! Schema::hasForeignKey('payroll_gov_deduction_policies', ['approved_by'])) {
            Schema::table('payroll_gov_deduction_policies', static fn (Blueprint $t) => $t->foreign('approved_by', 'pgdp_approved_by_fk')->references('id')->on('users')->nullOnDelete());
        }

        if ($this->isMySql()) {
            DB::statement('ALTER TABLE `payroll_gov_deduction_policies` ROW_FORMAT=DYNAMIC');
            foreach ([
                'pgdp_status_chk' => "`status` IN ('DRAFT','APPROVED')",
                'pgdp_approval_chk' => "(`status` = 'DRAFT' AND `approved_at` IS NULL) OR (`status` = 'APPROVED' AND `approved_at` IS NOT NULL)",
            ] as $name => $expression) {
                if (! $this->hasCheck($name)) {
                    DB::statement("ALTER TABLE `payroll_gov_deduction_policies` ADD CONSTRAINT `{$name}` CHECK ({$expression})");
                }
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('payroll_gov_deduction_policies')) {
            Schema::drop('payroll_gov_deduction_policies');
        }
    }

    private function hasCheck(string $name): bool
    {
        $row = DB::selectOne(
            "SELECT COUNT(*) AS n FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'payroll_gov_deduction_policies' AND CONSTRAINT_NAME = ? AND CONSTRAINT_TYPE = 'CHECK'",
            [$name],
        );

        return (int) ($row->n ?? 0) > 0;
    }

    private function isMySql(): bool
    {
        return in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true);
    }
};
