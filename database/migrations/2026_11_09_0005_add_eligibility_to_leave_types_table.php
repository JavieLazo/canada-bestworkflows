<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * WHO MAY USE A LEAVE TYPE, set by HR on Leave Settings
 * (LeaveEligibility):
 *
 *  - `eligible_statuses` JSON: the Employment list ids whose employees may
 *    use the type; NULL for every employment status;
 *  - `eligible_sex` VARCHAR(10): FEMALE or MALE for a type only one sex
 *    may use (maternity, paternity); NULL for anyone.
 *
 * Every type starts at NULL for both — anyone may use it — so nothing
 * changes on upgrade. `lt_eligible_sex_chk` pins the sex's literals on
 * MySQL/MariaDB. Columns appended without ->after(), so MariaDB 11.4 takes
 * ALGORITHM=INSTANT.
 *
 * RETRY-SAFE: each column and the CHECK stand behind their own guard.
 *
 * ROLLBACK LOSES who may use each type: every type is open to everyone
 * again. Credits, requests and earning postings already written keep what
 * they recorded.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('leave_types')) {
            return;
        }
        if (! Schema::hasColumn('leave_types', 'eligible_statuses')) {
            Schema::table('leave_types', static fn (Blueprint $t) => $t->json('eligible_statuses')->nullable());
        }
        if (! Schema::hasColumn('leave_types', 'eligible_sex')) {
            Schema::table('leave_types', static fn (Blueprint $t) => $t->string('eligible_sex', 10)->nullable());
        }
        if ($this->isMySql() && ! $this->hasCheck('lt_eligible_sex_chk')) {
            DB::statement("ALTER TABLE `leave_types` ADD CONSTRAINT `lt_eligible_sex_chk` CHECK (`eligible_sex` IS NULL OR `eligible_sex` IN ('FEMALE', 'MALE'))");
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('leave_types')) {
            return;
        }
        if ($this->isMySql() && $this->hasCheck('lt_eligible_sex_chk')) {
            DB::statement('ALTER TABLE `leave_types` DROP CONSTRAINT `lt_eligible_sex_chk`');
        }
        foreach (['eligible_sex', 'eligible_statuses'] as $column) {
            if (Schema::hasColumn('leave_types', $column)) {
                Schema::table('leave_types', static fn (Blueprint $t) => $t->dropColumn($column));
            }
        }
    }

    private function hasCheck(string $name): bool
    {
        $row = DB::selectOne(
            "SELECT COUNT(*) AS n FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'leave_types' AND CONSTRAINT_NAME = ? AND CONSTRAINT_TYPE = 'CHECK'",
            [$name],
        );

        return (int) ($row->n ?? 0) > 0;
    }

    private function isMySql(): bool
    {
        return in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true);
    }
};
