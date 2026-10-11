<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * NEW TABLE — it has no counterpart in prisma/schema.prisma.
 *
 * Where an employee works on each weekday. The reference had ONE place per
 * employee, `employee_profiles.work_site_id` ("Assigned Worksite"), and no
 * notion of working from home: a WFH punch could only be faked through a
 * geofence-exempt site. Since 2026-09-26 the Shift Roster can say, per
 * employee and per weekday, "work from home" or "at this site". Hours are
 * NOT here: they stay on the single `shifts` row, so the DTR and payroll
 * engines read exactly what they read before this table existed.
 *
 * One row per (employee, weekday), `weekday` 0 Sunday to 6 Saturday — the
 * numbering `shifts.work_days` and `CalendarDays::dayOfWeek` use. No row
 * means the Assigned Worksite, geofenced, exactly as every day was before;
 * a database with no rows here behaves as it always did. `kind` is
 * ONSITE | WFH (varchar plus `rloc_kind_chk`), and `rloc_site_chk` pins the
 * pairing: a WFH row carries no site and an ONSITE row always does, so no
 * reader ever meets an ONSITE row with nothing to geofence against.
 *
 * `site_id` is a RESTRICT foreign key — the second in this schema after
 * `pfs_run_fk` (2026_09_17_0002); every other domain key cascades or nulls.
 * SET NULL would turn a schedule into the "onsite, nowhere" state the CHECK
 * forbids, and CASCADE would silently change where somebody is expected to
 * work. `Organization\DeleteSite` asks
 * the tagged retention sources first, and Timekeeping's `SiteReferences`
 * refuses with a readable sentence while any row here names the site; the
 * database RESTRICT is the backstop for a delete that bypasses the command.
 * `employee_id` cascades like `shifts`: a schedule is configuration, not
 * history.
 *
 * Shape follows project_task_submissions: a ULID primary key minted by
 * BaseModel, short explicit unique/index names added BEFORE the foreign keys
 * so MySQL reuses them for the constraints. `rloc_employee_weekday_unq` also
 * serves every per-employee read by its prefix.
 *
 * RETRY-SAFE, STEP BY STEP. Production migrates from a temporary cPanel cron
 * with no SSH and MySQL DDL does not roll back, so a run can stop between any
 * two statements and is simply run again. The create carries only the columns
 * and the primary key, which MySQL puts in the CREATE TABLE itself; every
 * other piece is its own ALTER with its own guard, so a retry resumes from
 * wherever the last run stopped: the table only while hasTable() is false,
 * the unique and the index only while hasIndex() does not list the name,
 * each foreign key only while hasForeignKey() finds none on its column, and
 * each CHECK only while information_schema.TABLE_CONSTRAINTS lists no
 * constraint of that name. ROW_FORMAT=DYNAMIC restates the same format on a
 * retry, so it needs no guard. On SQLite (tests) each foreign key is a table
 * rebuild, which carries the indexes over, and no CHECK is created. down()
 * drops the table, and every index, key and CHECK with it, only if it exists.
 *
 * ROLLBACK LOSES every weekly schedule: every employee falls back to the
 * Assigned Worksite on every day. Punches already recorded keep their own
 * `attendance_logs.location_kind` and `site_id` (2026_09_26_0002).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('roster_locations')) {
            Schema::create('roster_locations', function (Blueprint $t): void {
                $t->engine = 'InnoDB';

                $t->char('id', 26)->primary();
                $t->char('employee_id', 26);

                // 0 Sunday … 6 Saturday, as shifts.work_days counts them.
                $t->unsignedTinyInteger('weekday');

                // ONSITE | WFH — free text plus rloc_kind_chk below.
                $t->string('kind', 10);

                // The site on an ONSITE day; NULL on a WFH day (rloc_site_chk).
                $t->char('site_id', 26)->nullable();

                $t->dateTime('created_at', 3);
                $t->dateTime('updated_at', 3)->nullable();
            });
        }

        // Every step below is checked on its own, not under the table check:
        // a run that stopped after the create has the table and none of them.
        // The unique and the index come BEFORE the foreign keys, so MySQL
        // reuses them for the constraints instead of adding its own
        // (project_task_submissions precedent).
        // STANDS DOWN once 2026_09_30_0006 has dated the rows (owner-approved
        // edit, 2026-09-30): that migration retires this unique, and a re-run
        // here must not bring it back.
        if (! Schema::hasIndex('roster_locations', 'rloc_employee_weekday_unq')
            && ! Schema::hasColumn('roster_locations', 'effective_from')) {
            Schema::table('roster_locations', function (Blueprint $t): void {
                $t->unique(['employee_id', 'weekday'], 'rloc_employee_weekday_unq');
            });
        }

        if (! Schema::hasIndex('roster_locations', 'rloc_site_idx')) {
            Schema::table('roster_locations', function (Blueprint $t): void {
                $t->index('site_id', 'rloc_site_idx');
            });
        }

        if (! Schema::hasForeignKey('roster_locations', ['employee_id'])) {
            Schema::table('roster_locations', function (Blueprint $t): void {
                $t->foreign('employee_id')->references('id')->on('employee_profiles')->cascadeOnDelete();
            });
        }

        if (! Schema::hasForeignKey('roster_locations', ['site_id'])) {
            Schema::table('roster_locations', function (Blueprint $t): void {
                // RESTRICT — see the docblock. Retention refuses first with a
                // readable sentence; this is the backstop.
                $t->foreign('site_id')->references('id')->on('work_sites')->restrictOnDelete();
            });
        }

        // Idempotent: a retry restates the same row format.
        $this->dynamicRowFormat('roster_locations');

        // Each CHECK behind its own guard like every step above: a run that
        // stopped between two of them has the first and not the second.
        if ($this->isMySql() && ! $this->hasCheck('roster_locations', 'rloc_weekday_chk')) {
            DB::statement('ALTER TABLE `roster_locations` ADD CONSTRAINT `rloc_weekday_chk` CHECK (`weekday` BETWEEN 0 AND 6)');
        }

        if ($this->isMySql() && ! $this->hasCheck('roster_locations', 'rloc_kind_chk')) {
            DB::statement("ALTER TABLE `roster_locations` ADD CONSTRAINT `rloc_kind_chk` CHECK (`kind` IN ('ONSITE','WFH'))");
        }

        if ($this->isMySql() && ! $this->hasCheck('roster_locations', 'rloc_site_chk')) {
            DB::statement("ALTER TABLE `roster_locations` ADD CONSTRAINT `rloc_site_chk` CHECK ((`kind` = 'WFH' AND `site_id` IS NULL) OR (`kind` = 'ONSITE' AND `site_id` IS NOT NULL))");
        }
    }

    public function down(): void
    {
        // The CHECKs, the indexes and the foreign keys all go with the table.
        // Guarded like every step in up(), so a repeated rollback runs no DDL
        // at all rather than a DROP ... IF EXISTS that finds nothing.
        if (Schema::hasTable('roster_locations')) {
            Schema::drop('roster_locations');
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

    private function dynamicRowFormat(string ...$tables): void
    {
        if (! $this->isMySql()) {
            return;
        }

        foreach ($tables as $table) {
            DB::statement("ALTER TABLE `{$table}` ROW_FORMAT=DYNAMIC");
        }
    }

    private function isMySql(): bool
    {
        return in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true);
    }
};
