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
 * (tests/Feature/NotificationsModuleTest.php) builds exactly that — one active
 * account and one disabled account on a single employee — which is how a
 * rehire, or an account rotated after a credential incident, keeps its history
 * attached to the 201 record. A blanket UNIQUE would forbid it. Scoping the
 * rule to active rows keeps that path open while making "who is this employee's
 * login" a question with one answer. NULL stays repeatable — any number of
 * accounts may hold no employee link at all; the whole admin tier does.
 *
 * THE ENFORCEMENT IS DRIVER-SPLIT because "unique among a subset of rows" has
 * no portable spelling — neither MySQL nor MariaDB has a partial index:
 *
 *   - SQLite (the test connection) DOES, so it indexes the WHERE clause
 *     directly. The suite therefore still proves the rule at the database
 *     level, not just through the application guard.
 *
 *   - MySQL / MariaDB use a BEFORE INSERT + BEFORE UPDATE trigger pair. The
 *     first, obvious attempt here was a generated column collapsing to NULL for
 *     inactive rows with a unique index on it — and it works on Oracle MySQL,
 *     but MariaDB (11.4, the cPanel host) refuses to index ANY generated column
 *     built from a conditional, throwing error 1901 for CASE, IF and both
 *     VIRTUAL and PERSISTENT. Verified against a real MariaDB 11.4 instance.
 *     The triggers do the same job with no generated column: they reject a
 *     write that would give an employee a second ACTIVE account, which covers
 *     both a fresh duplicate and REACTIVATING a disabled account onto an
 *     employee an active one already holds.
 *
 * The trigger is a BACKSTOP, not the front line. Every UI write already passes
 * through App\Livewire\Users\Index::activeAccountHolding(), which refuses the
 * clash with a named message before the row is written — so the trigger's raw
 * SIGNAL is only ever reached by a write that skips the app (a direct SQL edit,
 * a tinker session), which is exactly where a database-level guarantee earns
 * its place.
 *
 * `users_employee_idx` is left in place throughout — it still supports
 * `users_employee_id_foreign`.
 */
return new class extends Migration
{
    private const string TRIGGER_INSERT = 'users_active_employee_bi';

    private const string TRIGGER_UPDATE = 'users_active_employee_bu';

    private const string INDEX = 'users_active_employee_unq';

    /**
     * The two triggers, written out in full rather than built from a template.
     *
     * A helper that interpolated the name and the self-exclusion clause would
     * be shorter, but its result is a `string`, and DB::unprepared() insists on
     * a `literal-string` — the type system's way of guaranteeing nothing
     * caller-controlled reaches an unparameterised query. Two literals it is.
     *
     * The only real difference is the UPDATE form's `id <> NEW.id`: an account
     * being renamed or deactivated must not read as clashing with itself. The
     * INSERT form has no such row yet.
     */
    private const string CREATE_TRIGGER_INSERT = <<<'SQL'
CREATE TRIGGER `users_active_employee_bi` BEFORE INSERT ON `users` FOR EACH ROW
BEGIN
  IF NEW.`is_active` = 1 AND NEW.`employee_id` IS NOT NULL
     AND EXISTS (
       SELECT 1 FROM `users`
       WHERE `is_active` = 1 AND `employee_id` = NEW.`employee_id`
     ) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'That employee record already has an active account.';
  END IF;
END
SQL;

    private const string CREATE_TRIGGER_UPDATE = <<<'SQL'
CREATE TRIGGER `users_active_employee_bu` BEFORE UPDATE ON `users` FOR EACH ROW
BEGIN
  IF NEW.`is_active` = 1 AND NEW.`employee_id` IS NOT NULL
     AND EXISTS (
       SELECT 1 FROM `users`
       WHERE `is_active` = 1 AND `employee_id` = NEW.`employee_id` AND `id` <> NEW.`id`
     ) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'That employee record already has an active account.';
  END IF;
END
SQL;

    public function up(): void
    {
        $this->guardAgainstExistingDuplicates();

        if ($this->onMySql()) {
            $this->dropMySqlArtifacts();

            // One statement each — no DELIMITER, that is a CLI concern; the
            // driver sends the whole CREATE TRIGGER as a single statement.
            DB::unprepared(self::CREATE_TRIGGER_INSERT);
            DB::unprepared(self::CREATE_TRIGGER_UPDATE);

            return;
        }

        // SQLite: a partial unique index says the same thing in one line.
        DB::statement(
            'CREATE UNIQUE INDEX IF NOT EXISTS "'.self::INDEX.'" ON "users" ("employee_id")'
            .' WHERE "is_active" = 1 AND "employee_id" IS NOT NULL'
        );
    }

    public function down(): void
    {
        if ($this->onMySql()) {
            $this->dropMySqlArtifacts();

            return;
        }

        DB::statement('DROP INDEX IF EXISTS "'.self::INDEX.'"');
    }

    /**
     * Remove every trace of this migration's MySQL side, in any state.
     *
     * Covers the current trigger pair AND the abandoned generated-column
     * artifact from the version that MariaDB rejected — a host that got part
     * way through that one is left with an `active_employee_id` column and no
     * index, so both are dropped here. Every drop is guarded, so up() and
     * down() are both safe to re-run.
     */
    private function dropMySqlArtifacts(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS `'.self::TRIGGER_INSERT.'`');
        DB::unprepared('DROP TRIGGER IF EXISTS `'.self::TRIGGER_UPDATE.'`');

        if ($this->mySqlIndexExists(self::INDEX)) {
            DB::statement('ALTER TABLE `users` DROP INDEX `'.self::INDEX.'`');
        }

        if (Schema::hasColumn('users', 'active_employee_id')) {
            DB::statement('ALTER TABLE `users` DROP COLUMN `active_employee_id`');
        }
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
     * A trigger only judges the write in front of it, so it would happily let
     * an already-violating table stand. This keeps the invariant honest: when
     * the migration records as run, no employee has two active accounts.
     * Reported rather than resolved, because deciding which of two live
     * sign-ins keeps the 201 record is an HR question, and a migration that
     * guessed would silently unlink somebody's working account.
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
            "One employee record cannot be held by more than one active account when this rule is applied:\n"
            .implode("\n", $lines)
            ."\nIn User Management, open the accounts that should not hold the record and either set Linked employee to"
            .' "No linked employee" or deactivate them, then run the migration again.'
        );
    }
};
