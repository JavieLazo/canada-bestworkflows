<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Re-issue `rmg_module_chk` so a role can be granted COMMUNITY and CHAT.
 *
 * The CHECK on `role_module_grants.module` names every code literally, so a new
 * module is not grantable until the constraint is re-issued with the full list.
 * Values stay literal rather than interpolated from ModuleCode, for the same
 * reason the original migration gives: a later edit to that class must not
 * silently rewrite this one. ArchitectureTest reads the NEWEST migration that
 * mentions `rmg_module_chk` and fails if it is missing any code.
 *
 * `DROP CONSTRAINT` rather than `DROP CHECK`: the first is MySQL 8.0.19+ and
 * MariaDB 10.2.1+, the second MySQL only. The drop is skipped when the
 * constraint is absent (a server that parsed and ignored the original), so the
 * migration is retry-safe. sqlite never had the constraint; nothing to do there.
 *
 * RENUMBERED 2026-09-29 from 2026_09_21_0001, with the rest of the Social chain
 * (2026_09_21_0002 … 2026_09_28_0001 became 2026_09_29_0002 … 0011), so the
 * chain runs after every migration it was merged beside and no day repeats a
 * sequence number. A database that applied the chain under the old names runs
 * it again under the new ones; every step in it is guarded, so that second run
 * changes nothing (this one re-issues the same list).
 *
 * Rolling back deletes the COMMUNITY and CHAT grants first: the narrower CHECK
 * cannot be added while a row violates it, and those codes mean nothing once
 * the modules are gone.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->reissue(
            "`module` IN ('EMPLOYEES','EDTR','ROSTER','LEAVES','PROJECTS','PAYROLL',"
                ."'RECORDS','USERS','AUDIT','PORTAL','TASKS','COMMUNITY','CHAT','PROFILE')"
        );
    }

    public function down(): void
    {
        DB::table('role_module_grants')->whereIn('module', ['COMMUNITY', 'CHAT'])->delete();

        $this->reissue(
            "`module` IN ('EMPLOYEES','EDTR','ROSTER','LEAVES','PROJECTS','PAYROLL',"
                ."'RECORDS','USERS','AUDIT','PORTAL','TASKS','PROFILE')"
        );
    }

    private function reissue(string $expression): void
    {
        if (! $this->onMySql()) {
            return;
        }

        if ($this->checkExists('rmg_module_chk')) {
            DB::statement('ALTER TABLE `role_module_grants` DROP CONSTRAINT `rmg_module_chk`');
        }

        DB::statement("ALTER TABLE `role_module_grants` ADD CONSTRAINT `rmg_module_chk` CHECK ({$expression})");
    }

    private function checkExists(string $name): bool
    {
        return DB::select(
            'SELECT 1 FROM information_schema.CHECK_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_NAME = ?',
            [$name],
        ) !== [];
    }

    private function onMySql(): bool
    {
        return in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true);
    }
};
