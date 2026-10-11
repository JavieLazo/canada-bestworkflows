<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * NEW TABLE — `payslip_incentives`: the incentive and bonus lines a posted
 * payslip paid, itemised (2026-09-26, D4 and D7). Append-only, like
 * `payslips`: FinalizePayroll inserts them with the payslip, and nothing
 * updates them.
 *
 * Each line COPIES the type's code, name and taxable flag as they were at
 * finalization, with NO foreign key to the catalog: renaming a type, flipping
 * its flag or deleting it later must never rewrite what a payslip paid.
 * `over_ceiling` is the part of a tax-exempt line that went over the PHP
 * 90,000 yearly ceiling and was therefore taxed (ExemptBenefitCeiling
 * allocates the lines in `position` order); it is 0 on a taxable line.
 *
 * `payslip_id` CASCADEs: the lines are part of their payslip. The CHECKs
 * (MySQL/MariaDB only) pin amount > 0 and 0 <= over_ceiling <= amount, and
 * that a taxable line has nothing over the ceiling.
 *
 * RETRY-SAFE, STEP BY STEP: columns and primary key in the create; the index
 * BEFORE the payslip FK; the FK and each CHECK behind their own guards.
 * down() drops the table only if it exists.
 *
 * ROLLBACK LOSES the itemisation of every posted payslip — their totals stay
 * on `payslips` (bonus, exempt_benefits, taxable_benefits_excess) and the
 * lines stay in the finalization evidence.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('payslip_incentives')) {
            Schema::create('payslip_incentives', function (Blueprint $t): void {
                $t->engine = 'InnoDB';

                $t->char('id', 26)->primary();
                $t->char('payslip_id', 26);

                // The order the lines were allocated against the ceiling in.
                $t->unsignedSmallInteger('position');

                // Copies, never references: posted pay does not move.
                $t->string('type_code', 40);
                $t->string('name', 100);
                $t->boolean('is_taxable');

                $t->decimal('amount', 12, 2);
                $t->decimal('over_ceiling', 12, 2);

                $t->dateTime('created_at', 3);
            });
        }

        if (! Schema::hasIndex('payslip_incentives', 'psi_payslip_idx')) {
            Schema::table('payslip_incentives', function (Blueprint $t): void {
                $t->index(['payslip_id', 'position'], 'psi_payslip_idx');
            });
        }

        if (! Schema::hasForeignKey('payslip_incentives', ['payslip_id'])) {
            Schema::table('payslip_incentives', function (Blueprint $t): void {
                $t->foreign('payslip_id')->references('id')->on('payslips')->cascadeOnDelete();
            });
        }

        $this->dynamicRowFormat('payslip_incentives');

        if ($this->isMySql() && ! $this->hasCheck('payslip_incentives', 'psi_amount_chk')) {
            DB::statement('ALTER TABLE `payslip_incentives` ADD CONSTRAINT `psi_amount_chk` CHECK (`amount` > 0)');
        }

        if ($this->isMySql() && ! $this->hasCheck('payslip_incentives', 'psi_over_ceiling_chk')) {
            DB::statement('ALTER TABLE `payslip_incentives` ADD CONSTRAINT `psi_over_ceiling_chk` CHECK (`over_ceiling` >= 0 AND `over_ceiling` <= `amount` AND (`is_taxable` = 0 OR `over_ceiling` = 0))');
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('payslip_incentives')) {
            Schema::drop('payslip_incentives');
        }
    }

    /** Whether the named CHECK exists — information_schema, so MySQL/MariaDB only. */
    private function hasCheck(string $table, string $name): bool
    {
        $row = DB::selectOne(
            "SELECT COUNT(*) AS n FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ? AND CONSTRAINT_TYPE = 'CHECK'",
            [$table, $name],
        );

        return (int) ($row->n ?? 0) > 0;
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
