<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Preserve the custom deduction lines on a posted payslip. Each line copies
 * the catalog code and name at finalization; no catalog FK can rename or
 * erase the historical explanation for money already deducted.
 *
 * `payslips.custom_deductions` is the posted aggregate. Earlier payslips get
 * 0, while new payslips store a separate total from `other_deductions`.
 *
 * RETRY-SAFE: the payslip column, CREATE, index, FK and MariaDB/MySQL CHECK
 * each have their own guard. The index precedes its FK. A rerun resumes any
 * unfinished DDL step. down() is repeatable but loses posted deduction
 * itemization and its aggregate from payslips.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('payslips', 'custom_deductions')) {
            Schema::table('payslips', function (Blueprint $t): void {
                $t->decimal('custom_deductions', 12, 2)->default(0);
            });
        }

        if (! Schema::hasTable('payslip_deductions')) {
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
        }

        if (! Schema::hasIndex('payslip_deductions', 'psd_payslip_idx')) {
            Schema::table('payslip_deductions', function (Blueprint $t): void {
                $t->index(['payslip_id', 'position'], 'psd_payslip_idx');
            });
        }

        if (! Schema::hasForeignKey('payslip_deductions', ['payslip_id'])) {
            Schema::table('payslip_deductions', function (Blueprint $t): void {
                $t->foreign('payslip_id')->references('id')->on('payslips')->cascadeOnDelete();
            });
        }

        if ($this->isMySql()) {
            DB::statement('ALTER TABLE `payslip_deductions` ROW_FORMAT=DYNAMIC');

            if (! $this->hasCheck('psd_amount_chk')) {
                DB::statement('ALTER TABLE `payslip_deductions` ADD CONSTRAINT `psd_amount_chk` CHECK (`amount` > 0)');
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('payslip_deductions')) {
            Schema::drop('payslip_deductions');
        }

        if (Schema::hasColumn('payslips', 'custom_deductions')) {
            Schema::table('payslips', function (Blueprint $t): void {
                $t->dropColumn('custom_deductions');
            });
        }
    }

    private function hasCheck(string $name): bool
    {
        $row = DB::selectOne(
            "SELECT COUNT(*) AS n FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'payslip_deductions' AND CONSTRAINT_NAME = ? AND CONSTRAINT_TYPE = 'CHECK'",
            [$name],
        );

        return (int) ($row->n ?? 0) > 0;
    }

    private function isMySql(): bool
    {
        return in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true);
    }
};
