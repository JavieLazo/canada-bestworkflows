<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Allow several ACTIVE user accounts to link to the same employee record.
 *
 * The employee profile represents the person. User accounts represent ways
 * that person can sign in. A company login and a personal login may therefore
 * both point at the same `employee_profiles` row, and scoping still treats
 * them as the same employee.
 *
 * BestWorkflows enforced the rule with INSERT and UPDATE triggers on MySQL
 * and MariaDB, or a partial unique index on SQLite. Remove both triggers and
 * any older generated-column artifacts for databases where the 2026_09_08
 * migration already ran. Its updated cleanup body handles pending installs.
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
