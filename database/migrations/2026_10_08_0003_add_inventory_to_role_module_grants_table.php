<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Allow INVENTORY grants without assigning access to any existing role.
 *
 * The full literal rmg_module_chk list preserves every existing code. Keep
 * it independent of ModuleCode so future registry edits cannot rewrite this
 * migration's meaning. SQLite has no module CHECK and needs no DDL.
 *
 * RETRY-SAFE: drop the existing CHECK only while present, then add its new
 * definition only while absent. A cron killed between statements resumes at
 * the add. The table guard also tolerates an already removed parent table.
 *
 * ROLLBACK LOSES INVENTORY grants only: remove those rows before restoring the
 * prior CHECK, which would reject them. Existing roles and other grants stay.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->reissue(
            "`module` IN ('EMPLOYEES','EDTR','ROSTER','LEAVES','PROJECTS','INVENTORY','PAYROLL','REPORTS',"
                ."'RECORDS','USERS','AUDIT','PORTAL','TASKS','COMMUNITY','CHAT','PROFILE')"
        );
    }

    public function down(): void
    {
        if (! Schema::hasTable('role_module_grants')) {
            return;
        }

        DB::table('role_module_grants')->where('module', 'INVENTORY')->delete();

        $this->reissue(
            "`module` IN ('EMPLOYEES','EDTR','ROSTER','LEAVES','PROJECTS','PAYROLL','REPORTS',"
                ."'RECORDS','USERS','AUDIT','PORTAL','TASKS','COMMUNITY','CHAT','PROFILE')"
        );
    }

    private function reissue(string $expression): void
    {
        if (! in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true)
            || ! Schema::hasTable('role_module_grants')) {
            return;
        }

        if ($this->hasCheck()) {
            DB::statement('ALTER TABLE `role_module_grants` DROP CONSTRAINT `rmg_module_chk`');
        }

        if (! $this->hasCheck()) {
            DB::statement("ALTER TABLE `role_module_grants` ADD CONSTRAINT `rmg_module_chk` CHECK ({$expression})");
        }
    }

    private function hasCheck(): bool
    {
        $row = DB::selectOne(
            "SELECT COUNT(*) AS n FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ? AND CONSTRAINT_TYPE = 'CHECK'",
            ['role_module_grants', 'rmg_module_chk'],
        );

        return (int) ($row->n ?? 0) > 0;
    }
};
