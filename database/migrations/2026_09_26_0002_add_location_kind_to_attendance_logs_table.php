<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * NEW COLUMN — `attendance_logs.location_kind`: whether a punch was made on
 * site (ONSITE) or from home (WFH).
 *
 * DELIBERATE DIVERGENCE: the reference `model AttendanceLog`
 * (copems prisma/schema.prisma:319-343) always has a site to name, because
 * every employee had exactly one. Since 2026-09-26 the Shift Roster can mark
 * a weekday as work-from-home (roster_locations, 2026_09_26_0001), and a
 * punch on such a day has NO site: `site_id` is NULL, `distance_meters` is
 * NULL, there is no geofence verdict. But a NULL `site_id` alone cannot mean
 * "from home" — a correction for an employee with no Assigned Worksite has
 * always written NULL too — so the kind is stored on the punch itself.
 * Stored, not derived: a later roster edit must not rewrite what a feed
 * said about a punch that already happened.
 *
 * `varchar(10) NOT NULL DEFAULT 'ONSITE'`, so every existing punch reads
 * ONSITE — exactly what it was. There is no backfill and no index: nothing
 * filters by it. The column is appended without `->after()`, which keeps the
 * ADD instant on MariaDB 11.4 (the table is ROW_FORMAT=DYNAMIC,
 * 2026_09_01_0009) instead of rebuilding the table. The CHECK that follows
 * is a different matter: MariaDB validates the existing rows against a new
 * constraint, one pass over `attendance_logs`, which the default turns into
 * a scan that finds nothing to refuse — cost, not risk. Like the
 * coordinates, the column never reaches payroll: AttendanceInputQuery
 * selects only the id, employee, type and instant of a punch.
 *
 * `attlog_location_kind_chk` pins the two literal values on MySQL/MariaDB;
 * sqlite (the test suite) never creates it, and every writer sets the kind
 * from `Modules\Timekeeping\Domain\Roster\LocationKind` itself.
 *
 * RETRY-SAFE, STEP BY STEP. Production migrates from a temporary cPanel cron
 * that can be killed between any two statements, and MySQL DDL does not roll
 * back, so a half-applied run is simply run again. The column and the CHECK
 * are two ALTERs and get two guards: the column only while hasColumn() is
 * false, the CHECK only while information_schema lists no constraint of that
 * name. A run killed after the column resumes at the CHECK. down() mirrors
 * this: the CHECK goes first, so the column is never dropped out from under
 * a constraint that still names it, then the column, each only while it
 * exists.
 *
 * ROLLBACK LOSES which punches were made from home. They re-read as ONSITE
 * with no site (an em dash in the feeds); the punches themselves, their
 * coordinates, their accuracy and their instants stay.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('attendance_logs', 'location_kind')) {
            Schema::table('attendance_logs', function (Blueprint $t): void {
                // ONSITE | WFH — varchar plus the CHECK below. A new value
                // needs a migration that drops and re-adds the constraint.
                $t->string('location_kind', 10)->default('ONSITE');
            });
        }

        // Checked on its own, not under the column check: a run that stopped
        // between the two statements has the column and not the constraint.
        if ($this->isMySql() && ! $this->hasCheck('attendance_logs', 'attlog_location_kind_chk')) {
            DB::statement("ALTER TABLE `attendance_logs` ADD CONSTRAINT `attlog_location_kind_chk` CHECK (`location_kind` IN ('ONSITE','WFH'))");
        }
    }

    public function down(): void
    {
        if ($this->isMySql() && $this->hasCheck('attendance_logs', 'attlog_location_kind_chk')) {
            DB::statement('ALTER TABLE `attendance_logs` DROP CONSTRAINT `attlog_location_kind_chk`');
        }

        if (Schema::hasColumn('attendance_logs', 'location_kind')) {
            Schema::table('attendance_logs', function (Blueprint $t): void {
                $t->dropColumn('location_kind');
            });
        }
    }

    /** Whether the named CHECK exists — information_schema, so MySQL/MariaDB only. */
    private function hasCheck(string $table, string $name): bool
    {
        $row = DB::selectOne(
            "SELECT COUNT(*) AS n FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ? AND CONSTRAINT_TYPE = 'CHECK'",
            [$table, $name],
        );

        return (int) ($row->n ?? 0) > 0;
    }

    private function isMySql(): bool
    {
        return in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true);
    }
};
