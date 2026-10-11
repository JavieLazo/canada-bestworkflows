<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * NEW TABLE — it has no counterpart in prisma/schema.prisma.
 *
 * The Personal Data Sheet lines of an employee's 201 file that the record's
 * own columns do not hold (DELIBERATE DIVERGENCE: the reference record has
 * none of them): the specification of civil status "Other/s", the structured
 * citizenship (`citizenship_kind` FILIPINO | DUAL, and for DUAL how it was
 * acquired, BIRTH | NATURALIZATION, and the other country), the "same as
 * residential" answer for the permanent address, and the UMID and PhilSys
 * numbers. Every column is optional and has no default.
 *
 * 0 or 1 row per employee. The row is created by the first save that carries
 * one of its values; a missing row reads as all blank, exactly as a row of
 * NULLs does. `permanent_address_same` is an answer, never assumed: NULL is
 * "not answered", true "same as residential", false "the permanent address
 * has its own parts" (rows of `employee_addresses`, 2026_10_07_0002).
 *
 * A table of its own rather than columns on `employee_profiles`: that table,
 * its columns, its values and its row-size budget stay exactly as they are,
 * and the legacy `employee_profiles.citizenship` text keeps what it holds as
 * the fallback read while no structured citizenship is chosen. The rows are
 * written only by the Workforce commands, inside the transaction that saves
 * the employee's record, and read only through `EmployeeRecords`, behind the
 * record's own visibility rule; nothing else joins, counts or reports on
 * them. So they carry no actor scope, like `employee_children`. A writer
 * locks the employee's `employee_profiles` row first and reads this table
 * plainly, never with a locking read: on InnoDB a locking read that finds no
 * row gap-locks `epds_employee_unq`, and two first saves for different
 * employees would then deadlock. The row is updated in place, so the table
 * has a nullable `updated_at`.
 *
 * `employee_id` cascades like `employee_children`: the details are part of
 * the record and go with it. `epds_employee_unq` pins the one row and serves
 * every per-employee read; it is declared BEFORE the foreign key so
 * MySQL/MariaDB reuse it for the constraint. `epds_citizenship_kind_chk` and
 * `epds_dual_by_chk` hold the two coded columns to their literal lists. There
 * is no CHECK across columns: the commands clear the dual-citizenship parts
 * when FILIPINO is chosen.
 *
 * RETRY-SAFE, STEP BY STEP. Production migrates from a temporary cPanel cron
 * that can die between statements, and MariaDB DDL does not roll back, so the
 * create carries only the columns and the primary key, and every other piece
 * is its own ALTER behind its own guard: the unique only while hasIndex()
 * does not list its name, the foreign key only while hasForeignKey() finds
 * none on its column, and each CHECK only while
 * information_schema.TABLE_CONSTRAINTS lists no constraint of that name.
 * ROW_FORMAT=DYNAMIC restates the same format on a retry and needs no guard.
 * On sqlite (tests) the foreign key is a table rebuild that carries the index
 * over, and no CHECK is created. down() drops the table, and the index, key
 * and CHECKs with it, only if it exists.
 *
 * ROLLBACK LOSES every civil-status specification, structured citizenship,
 * "same as residential" answer, UMID number and PhilSys number entered since:
 * nothing else in the system holds them. The legacy
 * `employee_profiles.citizenship` text is untouched either way.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('employee_pds_details')) {
            Schema::create('employee_pds_details', function (Blueprint $t): void {
                $t->engine = 'InnoDB';

                $t->char('id', 26)->primary();
                $t->char('employee_id', 26);

                // What "Other/s" means for this employee's civil status.
                $t->string('civil_status_other', 60)->nullable();

                // FILIPINO | DUAL — free text plus epds_citizenship_kind_chk.
                $t->string('citizenship_kind', 10)->nullable();

                // BIRTH | NATURALIZATION — free text plus epds_dual_by_chk.
                $t->string('dual_citizenship_by', 15)->nullable();
                $t->string('dual_citizenship_country', 60)->nullable();

                // NULL not answered, true same as residential, false its own parts.
                $t->boolean('permanent_address_same')->nullable();

                $t->string('umid_no', 40)->nullable();
                $t->string('philsys_pcn', 40)->nullable();

                $t->dateTime('created_at', 3);
                $t->dateTime('updated_at', 3)->nullable();
            });
        }

        // Each step below is checked on its own, not under the table check: a
        // run that stopped after the create has the table and none of them.
        if (! Schema::hasIndex('employee_pds_details', 'epds_employee_unq')) {
            Schema::table('employee_pds_details', function (Blueprint $t): void {
                $t->unique(['employee_id'], 'epds_employee_unq');
            });
        }

        if (! Schema::hasForeignKey('employee_pds_details', ['employee_id'])) {
            Schema::table('employee_pds_details', function (Blueprint $t): void {
                $t->foreign('employee_id')->references('id')->on('employee_profiles')->cascadeOnDelete();
            });
        }

        // Each CHECK behind its own guard like every step above: a run that
        // stopped between the two has the first and not the second.
        if ($this->isMySql() && ! $this->hasCheck('employee_pds_details', 'epds_citizenship_kind_chk')) {
            DB::statement("ALTER TABLE `employee_pds_details` ADD CONSTRAINT `epds_citizenship_kind_chk` CHECK (`citizenship_kind` IS NULL OR `citizenship_kind` IN ('FILIPINO','DUAL'))");
        }

        if ($this->isMySql() && ! $this->hasCheck('employee_pds_details', 'epds_dual_by_chk')) {
            DB::statement("ALTER TABLE `employee_pds_details` ADD CONSTRAINT `epds_dual_by_chk` CHECK (`dual_citizenship_by` IS NULL OR `dual_citizenship_by` IN ('BIRTH','NATURALIZATION'))");
        }

        // Idempotent: a retry restates the same row format.
        $this->dynamicRowFormat('employee_pds_details');
    }

    public function down(): void
    {
        // The index, the foreign key and the CHECKs go with the table. Guarded
        // like every step in up(), so a repeated rollback runs no DDL at all.
        if (Schema::hasTable('employee_pds_details')) {
            Schema::drop('employee_pds_details');
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
