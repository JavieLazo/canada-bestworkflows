<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One employee record, at most one ACTIVE sign-in.
 *
 * `users.employee_id` carried a plain index, so nothing stopped a second
 * account from claiming an employee another account already held. The readers
 * that resolve an employee back to an account then disagree about who they
 * mean: Notifier::employee() fans out to every ACTIVE account
 * (app/Domain/Notifications/Notifier.php:162-165), while Projects hands a task
 * notification to `->orderBy('id')->first()`
 * (app/Livewire/Projects/ProjectsData.php:526).
 *
 * WHY ACTIVE-ONLY, NOT A BLANKET UNIQUE. A deactivated account keeping its link
 * is a real state this system supports and tests: "Notifier::employee tells
 * every ACTIVE login of that employee, and nobody else"
 * (tests/Feature/NotificationsModuleTest.php:250) builds exactly that — one
 * active account and one disabled account on a single employee — which is how a
 * rehire, or an account rotated after a credential incident, keeps its history
 * attached to the 201 record. A blanket UNIQUE would forbid it. Scoping the
 * constraint to active rows keeps that path open while making "who is this
 * employee's login" a question with one answer.
 *
 * NULL stays repeatable on both drivers: any number of accounts may hold no
 * employee link at all — the whole admin tier does.
 *
 * THE INDEX IS DRIVER-SPLIT because a partial unique index is not portable:
 *   - SQLite (the test connection) indexes a WHERE clause directly.
 *   - MySQL/MariaDB have no partial index, so the same rule is expressed as a
 *     VIRTUAL generated column that collapses to NULL for inactive rows, with
 *     the unique index on that. VIRTUAL rather than STORED: it costs no row
 *     bytes, which keeps this clear of the COMPACT row-size ceiling the create
 *     migration budgets against.
 * Both spellings also reject REACTIVATING a disabled account whose employee is
 * already held by an active one, which is the other half of the rule.
 *
 * `users_employee_idx` is deliberately left in place — it still supports
 * `users_employee_id_foreign`, which the new index does not cover on MySQL.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->guardAgainstExistingDuplicates();

        if ($this->onMySql()) {
            // Each step is skipped if already present. MySQL DDL does not roll
            // back, so a run that adds the column and then fails on the index
            // leaves the migration unrecorded and half-applied; without these
            // checks the operator's retry would die at 1060 "Duplicate column
            // name" and have to be unpicked by hand on a host with no SSH.
            if (! Schema::hasColumn('users', 'active_employee_id')) {
                DB::statement(
                    'ALTER TABLE `users` ADD COLUMN `active_employee_id` CHAR(26)'
                    .' COLLATE utf8mb4_unicode_ci'
                    .' GENERATED ALWAYS AS (CASE WHEN `is_active` = 1 THEN `employee_id` END) VIRTUAL'
                );
            }

            if (! $this->mySqlIndexExists('users_active_employee_unq')) {
                DB::statement('ALTER TABLE `users` ADD UNIQUE INDEX `users_active_employee_unq` (`active_employee_id`)');
            }

            return;
        }

        DB::statement(
            'CREATE UNIQUE INDEX IF NOT EXISTS "users_active_employee_unq" ON "users" ("employee_id")'
            .' WHERE "is_active" = 1 AND "employee_id" IS NOT NULL'
        );
    }

    public function down(): void
    {
        if ($this->onMySql()) {
            // Symmetrical with up(): a rollback after a half-applied run drops
            // whichever half exists rather than failing on the half that does not.
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

    /**
     * Refuse to run while two ACTIVE accounts still share an employee record.
     *
     * Reported rather than resolved: deciding which of two live sign-ins keeps
     * the 201 record is an HR question about who the person actually is, and a
     * migration that guessed would silently unlink somebody's working account.
     * Production migrates from a temporary cron job on cPanel, where a raw
     * SQLSTATE 23000 halfway through a deploy is expensive to unpick.
     */
    private function guardAgainstExistingDuplicates(): void
    {
        $duplicates = DB::table('users')
            ->select('employee_id', DB::raw('COUNT(*) as account_count'))
            ->whereNotNull('employee_id')
            ->where('is_active', true)
            ->groupBy('employee_id')
            ->having('account_count', '>', 1)
            ->pluck('account_count', 'employee_id');

        if ($duplicates->isEmpty()) {
            return;
        }

        $lines = [];

        foreach ($duplicates as $employeeId => $count) {
            $emails = DB::table('users')
                ->where('employee_id', $employeeId)
                ->where('is_active', true)
                ->orderBy('id')
                ->pluck('email')
                ->implode(', ');

            $lines[] = "  employee {$employeeId} — {$count} active accounts: {$emails}";
        }

        throw new RuntimeException(
            "users.employee_id cannot be made unique among active accounts while these employee records are claimed by more than one:\n"
            .implode("\n", $lines)
            ."\nIn User Management, open the accounts that should not hold the record and either set Linked employee to"
            .' "No linked employee" or deactivate them, then run the migration again.'
        );
    }
};
