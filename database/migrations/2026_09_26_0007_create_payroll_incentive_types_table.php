<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * NEW TABLE — `payroll_incentive_types`: the catalog of incentives and
 * bonuses a payroll may pay, under Records → Payroll Settings (2026-09-26,
 * the owner's decisions D4 and D10).
 *
 * DELIBERATE DIVERGENCE: the reference computes `bonus = 0` on every payslip
 * (payroll-server-calculation.ts:109) and has no incentive at all. Each type
 * here is TAXABLE (added to compensation before withholding) or TAX-EXEMPT
 * (added after it, up to the PHP 90,000 yearly ceiling of NIRC Sec.
 * 32(B)(7)(e); anything over it is taxed on the payslip that crosses it).
 * `is_taxable` is that one flag: a de minimis item's own per-item ceilings
 * cannot be expressed by it, so none is seeded.
 *
 * `code` is a bare number minted under a locked scan (codes-and-locks guide),
 * unique like every coded column; `name` is unique too, so two types can
 * never read the same on a payslip. A payroll's lines refer to a type by id
 * (payroll_run_member_incentives.type_id, RESTRICT), and a POSTED payslip
 * copies the code, name and flag it was paid under (payslip_incentives), so
 * renaming a type or flipping its flag never rewrites posted pay.
 *
 * RETRY-SAFE, STEP BY STEP. The create holds only the columns and the primary
 * key; each unique is a separate ALTER behind its own hasIndex guard, so a
 * cron run killed between statements resumes where it stopped.
 * ROW_FORMAT=DYNAMIC restates the same format on a retry. No CHECK: the flag
 * is a boolean and the rest is free text. down() drops the table only if it
 * exists — and fails, by design, while a payroll line still RESTRICTs it:
 * roll back `2026_09_26_0008` first.
 *
 * ROLLBACK LOSES the catalog: every type HR added or edited.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('payroll_incentive_types')) {
            Schema::create('payroll_incentive_types', function (Blueprint $t): void {
                $t->engine = 'InnoDB';

                $t->char('id', 26)->primary();
                $t->string('code', 40);
                $t->string('name', 100);
                $t->string('description', 500)->nullable();

                // TRUE: added before withholding. FALSE: tax-exempt, up to
                // the PHP 90,000 yearly ceiling.
                $t->boolean('is_taxable');

                $t->dateTime('created_at', 3);
                $t->dateTime('updated_at', 3)->nullable();
            });
        }

        if (! Schema::hasIndex('payroll_incentive_types', 'pit_code_unq')) {
            Schema::table('payroll_incentive_types', function (Blueprint $t): void {
                $t->unique('code', 'pit_code_unq');
            });
        }

        if (! Schema::hasIndex('payroll_incentive_types', 'pit_name_unq')) {
            Schema::table('payroll_incentive_types', function (Blueprint $t): void {
                $t->unique('name', 'pit_name_unq');
            });
        }

        $this->dynamicRowFormat('payroll_incentive_types');
    }

    public function down(): void
    {
        if (Schema::hasTable('payroll_incentive_types')) {
            Schema::drop('payroll_incentive_types');
        }
    }

    private function dynamicRowFormat(string ...$tables): void
    {
        if (! $this->isMySql()) {
            return;
        }

        foreach ($tables as $table) {
            DB::statement("ALTER TABLE `{$table}` ROW_FORMAT=DYNAMIC");
        }
    }

    private function isMySql(): bool
    {
        return in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true);
    }
};
