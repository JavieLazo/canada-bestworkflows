<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * NEW COLUMN — `overtime_approvals.credited_minutes`: the part of a day's
 * APPROVED overtime the approver CREDITED as Compensatory Leave instead of
 * paying it. Payroll pays `approved_minutes - credited_minutes`; the
 * credited minutes are the employee's Compensatory Leave hours earned
 * (CompensatoryHours). 0 on every decision before this column, so nothing
 * moves. `ota_credited_chk` keeps it between 0 and the approved minutes.
 *
 * RETRY-SAFE: the column and the CHECK each stand behind their own guard;
 * the column is appended (no `after()`) so MariaDB takes ALGORITHM=INSTANT.
 *
 * ROLLBACK LOSES which approved minutes were credited: payroll would pay
 * every approved minute again, and the hours earned would read as zero.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('overtime_approvals')) {
            return;
        }
        if (! Schema::hasColumn('overtime_approvals', 'credited_minutes')) {
            Schema::table('overtime_approvals', static fn (Blueprint $t) => $t->unsignedSmallInteger('credited_minutes')->default(0));
        }
        if ($this->isMySql() && ! $this->hasCheck('ota_credited_chk')) {
            DB::statement('ALTER TABLE `overtime_approvals` ADD CONSTRAINT `ota_credited_chk` CHECK (`credited_minutes` >= 0 AND `credited_minutes` <= `approved_minutes`)');
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('overtime_approvals') || ! Schema::hasColumn('overtime_approvals', 'credited_minutes')) {
            return;
        }
        if ($this->isMySql() && $this->hasCheck('ota_credited_chk')) {
            DB::statement('ALTER TABLE `overtime_approvals` DROP CONSTRAINT `ota_credited_chk`');
        }
        Schema::table('overtime_approvals', static fn (Blueprint $t) => $t->dropColumn('credited_minutes'));
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
