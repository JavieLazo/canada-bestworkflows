<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Compatibility cleanup for the withdrawn "one active account per employee"
 * rule.
 *
 * BestWorkflows enforced this with INSERT and UPDATE triggers on MySQL and
 * MariaDB, or the `users_active_employee_unq` partial index on SQLite. One
 * person may sign in through multiple accounts, and the employee record is
 * how those accounts are recognized as the same person.
 *
 * Keep the file rather than deleting it outright. Some deployed databases may
 * still have this migration pending; for them, `up()` must simply mark the
 * migration complete without creating the old constraint. Other databases may
 * already have the triggers or index; the 2026_09_10_0003 migration removes them.
 * This body also drops any half-applied artifacts if a retry ever lands here.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->dropWithdrawnArtifacts();
    }

    public function down(): void
    {
        // Intentionally do not recreate the withdrawn uniqueness rule.
    }

    private function dropWithdrawnArtifacts(): void
    {
        if ($this->onMySql()) {
            DB::unprepared('DROP TRIGGER IF EXISTS `users_active_employee_bi`');
            DB::unprepared('DROP TRIGGER IF EXISTS `users_active_employee_bu`');

            if ($this->mySqlIndexExists('users_active_employee_unq')) {
                DB::statement('ALTER TABLE `users` DROP INDEX `users_active_employee_unq`');
            }

            if (Schema::hasColumn('users', 'active_employee_id')) {
                DB::statement('ALTER TABLE `users` DROP COLUMN `active_employee_id`');
            }

            return;
        }

        DB::statement('DROP INDEX IF EXISTS "users_active_employee_unq"');
    }

    private function mySqlIndexExists(string $name): bool
    {
        return DB::select('SHOW INDEX FROM `users` WHERE Key_name = ?', [$name]) !== [];
    }

    private function onMySql(): bool
    {
        return in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true);
    }
};
