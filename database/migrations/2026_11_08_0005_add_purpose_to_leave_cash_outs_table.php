<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * WHAT A CASH CONVERSION IS FOR (the leave application's "Other purpose"):
 * `leave_cash_outs.purpose` VARCHAR(12) — MONETIZATION (Monetization of
 * Leave Credits: an employee's own request, `RequestLeaveCashOut`) or
 * TERMINAL (Terminal Leave: HR's payout on separation, `FileTerminalLeave`,
 * held to none of the type's limits and priced from the highest salary on
 * record). NULL on every request made before this column: nothing guesses
 * what those were for (`lco_purpose_chk`), and payroll reads it as a
 * monetization.
 *
 * Appended without ->after(), so MariaDB 11.4 takes ALGORITHM=INSTANT.
 *
 * RETRY-SAFE: the column and the CHECK each stand behind their own guard.
 *
 * ROLLBACK LOSES the purpose of every request; the requests, their review
 * and their payment stay.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('leave_cash_outs')) {
            return;
        }
        if (! Schema::hasColumn('leave_cash_outs', 'purpose')) {
            Schema::table('leave_cash_outs', static fn (Blueprint $t) => $t->string('purpose', 12)->nullable());
        }
        if ($this->isMySql() && ! $this->hasCheck('lco_purpose_chk')) {
            DB::statement("ALTER TABLE `leave_cash_outs` ADD CONSTRAINT `lco_purpose_chk` CHECK (`purpose` IS NULL OR `purpose` IN ('MONETIZATION','TERMINAL'))");
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('leave_cash_outs')) {
            return;
        }
        if ($this->isMySql() && $this->hasCheck('lco_purpose_chk')) {
            DB::statement('ALTER TABLE `leave_cash_outs` DROP CONSTRAINT `lco_purpose_chk`');
        }
        if (Schema::hasColumn('leave_cash_outs', 'purpose')) {
            Schema::table('leave_cash_outs', static fn (Blueprint $t) => $t->dropColumn('purpose'));
        }
    }

    private function hasCheck(string $name): bool
    {
        $row = DB::selectOne(
            "SELECT COUNT(*) AS n FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'leave_cash_outs' AND CONSTRAINT_NAME = ? AND CONSTRAINT_TYPE = 'CHECK'",
            [$name],
        );

        return (int) ($row->n ?? 0) > 0;
    }

    private function isMySql(): bool
    {
        return in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true);
    }
};
