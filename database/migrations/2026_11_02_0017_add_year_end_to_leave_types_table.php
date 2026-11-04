<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * NEW COLUMN — `leave_types.year_end`: what a type's balance does when the
 * leave year closes. CARRY (the default, every type before this column)
 * carries the whole balance into the new year, a debt included; LAPSE
 * drops a balance above zero at the year end (a LAPSE ledger entry in the
 * old year) and still carries a debt. A yearly grant (special privilege
 * or solo parent leave) lapses; vacation and sick credits carry.
 * `lt_year_end_chk` pins the two values.
 *
 * RETRY-SAFE: the column and the CHECK each stand behind their own guard;
 * the column is appended (no `after()`) so MariaDB takes ALGORITHM=INSTANT.
 *
 * ROLLBACK LOSES which types lapse: every type carries again. LAPSE
 * entries already written stay in the ledger.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('leave_types')) {
            return;
        }
        if (! Schema::hasColumn('leave_types', 'year_end')) {
            Schema::table('leave_types', static fn (Blueprint $t) => $t->string('year_end', 6)->default('CARRY'));
        }
        if ($this->isMySql() && ! $this->hasCheck('lt_year_end_chk')) {
            DB::statement("ALTER TABLE `leave_types` ADD CONSTRAINT `lt_year_end_chk` CHECK (`year_end` IN ('CARRY','LAPSE'))");
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('leave_types') || ! Schema::hasColumn('leave_types', 'year_end')) {
            return;
        }
        if ($this->isMySql() && $this->hasCheck('lt_year_end_chk')) {
            DB::statement('ALTER TABLE `leave_types` DROP CONSTRAINT `lt_year_end_chk`');
        }
        Schema::table('leave_types', static fn (Blueprint $t) => $t->dropColumn('year_end'));
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
