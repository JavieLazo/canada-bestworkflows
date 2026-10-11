<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * NEW COLUMN — `employee_profiles.position_id`, the entry on the Position list
 * an employee holds. The employee's department and unit are read through it
 * (`positions.org_node_id`), never stored here, so moving a position or a
 * unit moves its holders with it.
 *
 * `position` stays the text copy of the title that the record, the payslips
 * and Employment History already read. The Workforce commands write both
 * together; a position rename rewrites the holders' copies in the same
 * transaction. NULL is an unlinked record: one hired before the link, or one
 * whose title is not on the list ("Messenger"). 2026_10_07_0014 links the
 * existing records whose text matches a position exactly; this migration
 * adds the column empty.
 *
 * The key (`employee_profiles_position_id_foreign`, Laravel's own name, so
 * down() can drop it by column on sqlite too) RESTRICTS deletion, the
 * exception the database guide asks a reason for: a position may not be
 * deleted while anyone, active or inactive, holds it, and SET NULL would
 * silently strip its holders' department and unit. `DeletePosition` refuses
 * first with a readable sentence; the key is the backstop. `ep_position_idx`
 * is declared BEFORE the key, so MySQL/MariaDB reuse it for the constraint,
 * and serves the "held by" counts.
 *
 * ROW SIZE. The table depends on ROW_FORMAT=DYNAMIC (see 2026_10_01_0001 and
 * the database guide): InnoDB counts a CHAR(26) in utf8mb4 at its full 104
 * bytes. tests/Integration/OrgStructureSchemaTest adds this column, its index
 * and its key as three statements to a `CREATE TABLE … LIKE` copy under
 * innodb_strict_mode, and replays the column with ALGORITHM=INSTANT on the
 * real table. Measured on MariaDB 11.4.13, the copy still takes twelve more
 * VARCHAR(63) columns after this one, and Employee201FieldsSchemaTest's floor
 * of ten instant VARCHAR(63) columns on the real table still holds.
 *
 * RETRY-SAFE, STEP BY STEP. Production migrates from a temporary cPanel cron
 * that can die between statements, and MariaDB DDL does not roll back, so the
 * column, the index and the key are three ALTERs, each behind its own guard
 * (`hasColumn`, `hasIndex` by name, `hasForeignKey` on `position_id`). The
 * column is appended without `->after()`, which keeps the ADD instant on
 * MariaDB 11.4. down() mirrors up() in reverse with the same guards.
 *
 * ROLLBACK LOSES every link, and with it every employee's department and
 * unit. The `position` text is untouched either way.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('employee_profiles', 'position_id')) {
            Schema::table('employee_profiles', function (Blueprint $t): void {
                $t->char('position_id', 26)->nullable();
            });
        }

        if (! Schema::hasIndex('employee_profiles', 'ep_position_idx')) {
            Schema::table('employee_profiles', function (Blueprint $t): void {
                $t->index('position_id', 'ep_position_idx');
            });
        }

        // Checked on its own, not under the column check: a run that stopped
        // between the statements has the column and not the constraint.
        if (! Schema::hasForeignKey('employee_profiles', ['position_id'])) {
            Schema::table('employee_profiles', function (Blueprint $t): void {
                $t->foreign('position_id')->references('id')->on('positions')->restrictOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasForeignKey('employee_profiles', ['position_id'])) {
            Schema::table('employee_profiles', function (Blueprint $t): void {
                $t->dropForeign(['position_id']);
            });
        }

        if (Schema::hasIndex('employee_profiles', 'ep_position_idx')) {
            Schema::table('employee_profiles', function (Blueprint $t): void {
                $t->dropIndex('ep_position_idx');
            });
        }

        if (Schema::hasColumn('employee_profiles', 'position_id')) {
            Schema::table('employee_profiles', function (Blueprint $t): void {
                $t->dropColumn('position_id');
            });
        }
    }
};
