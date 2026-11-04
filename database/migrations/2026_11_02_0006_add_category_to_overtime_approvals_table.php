<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * NEW COLUMN — `overtime_approvals.category`: the overtime CATEGORY the
 * Daily Time Record approved a day under. NULL is ordinary overtime, as
 * every decision before this column was; `SEAPORT` is seaport overtime,
 * which Pay Basis & Rates prices at its own rate and never caps
 * (PayslipCalculator divergence 16). `ota_category_chk` pins the literal.
 *
 * RETRY-SAFE: the column and the CHECK each stand behind their own guard;
 * the column is appended (no `after()`) so MariaDB takes ALGORITHM=INSTANT.
 *
 * ROLLBACK LOSES which approved days were seaport overtime: payroll would
 * cap and price them as ordinary overtime again. Posted payslips keep the
 * category inside their finalization evidence.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('overtime_approvals')) {
            return;
        }

        if (! Schema::hasColumn('overtime_approvals', 'category')) {
            Schema::table('overtime_approvals', static fn (Blueprint $t) => $t->string('category', 10)->nullable());
        }

        if ($this->isMySql() && ! $this->hasCheck('ota_category_chk')) {
            DB::statement("ALTER TABLE `overtime_approvals` ADD CONSTRAINT `ota_category_chk` CHECK (`category` IS NULL OR `category` IN ('SEAPORT'))");
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('overtime_approvals') || ! Schema::hasColumn('overtime_approvals', 'category')) {
            return;
        }

        if ($this->isMySql() && $this->hasCheck('ota_category_chk')) {
            DB::statement('ALTER TABLE `overtime_approvals` DROP CONSTRAINT `ota_category_chk`');
        }

        Schema::table('overtime_approvals', static fn (Blueprint $t) => $t->dropColumn('category'));
    }

    /** Whether the named CHECK exists — information_schema, so MySQL/MariaDB only. */
    private function hasCheck(string $name): bool
    {
        $row = DB::selectOne(
            "SELECT COUNT(*) AS n FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'overtime_approvals' AND CONSTRAINT_NAME = ? AND CONSTRAINT_TYPE = 'CHECK'",
            [$name],
        );

        return (int) ($row->n ?? 0) > 0;
    }

    private function isMySql(): bool
    {
        return in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true);
    }
};
