<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * NEW TABLE — it has no counterpart in prisma/schema.prisma.
 *
 * An employee's educational background as the Personal Data Sheet lists it
 * (DELIBERATE DIVERGENCE: the reference record has no Personal Data Sheet
 * lists): one row per school attended, with its `level` — ELEMENTARY |
 * SECONDARY | VOCATIONAL | COLLEGE | GRADUATE (varchar plus
 * `eedu_level_chk`) — the school, the course, the years attended from and to,
 * the highest level or units earned, the year graduated and the scholarship
 * or honors received. The three years are four-digit years; the commands hold
 * them to 1900–2100.
 *
 * Any number of rows per level, so there is no unique: the Personal Data
 * Sheet has a continuation sheet for more schools, a row is identified by its
 * id, and reads order the rows by level and their years.
 * `eedu_employee_level_idx` serves every per-employee read, by level too.
 *
 * The rows are written only by the Workforce commands, inside a transaction
 * that locked the employee's `employee_profiles` row first — an insert, or an
 * update or delete by primary key, never a locking read or a delete by range,
 * which on InnoDB would gap-lock the index under two first saves — and read
 * only through `PdsRows` / `EmployeeRecords`, behind the record's own
 * visibility rule; nothing else joins, counts or reports on them. So they
 * carry no actor scope, like `employee_children`. A row is edited in place,
 * so the table has a nullable `updated_at`.
 *
 * `employee_id` cascades like `employee_children`: the schools are part of
 * the record and go with it. The index is declared BEFORE the foreign key so
 * MySQL/MariaDB reuse it for the constraint. No legacy column is read, copied
 * or written: `employee_profiles.college`, `college_course`,
 * `college_year_graduated`, `graduate_school`, `graduate_course` and
 * `graduate_year_graduated` keep what they hold and are read only as the
 * fallback while no row of their level exists.
 *
 * RETRY-SAFE, STEP BY STEP. Production migrates from a temporary cPanel cron
 * that can die between statements, and MariaDB DDL does not roll back, so the
 * create carries only the columns and the primary key, and every other piece
 * is its own ALTER behind its own guard: the index only while hasIndex() does
 * not list its name, the foreign key only while hasForeignKey() finds none on
 * its column, and the CHECK only while information_schema.TABLE_CONSTRAINTS
 * lists no constraint of that name. ROW_FORMAT=DYNAMIC restates the same
 * format on a retry and needs no guard. On sqlite (tests) the foreign key is
 * a table rebuild that carries the index over, and no CHECK is created.
 * down() drops the table, and the index, key and CHECK with it, only if it
 * exists.
 *
 * ROLLBACK LOSES every school typed since: nothing else in the system holds
 * them. The legacy college and graduate-school columns are untouched either
 * way.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('employee_education')) {
            Schema::create('employee_education', function (Blueprint $t): void {
                $t->engine = 'InnoDB';

                $t->char('id', 26)->primary();
                $t->char('employee_id', 26);

                // ELEMENTARY | SECONDARY | VOCATIONAL | COLLEGE | GRADUATE —
                // free text plus eedu_level_chk.
                $t->string('level', 12);

                $t->string('school', 150);
                $t->string('course', 150)->nullable();

                // Four-digit years.
                $t->unsignedSmallInteger('attended_from')->nullable();
                $t->unsignedSmallInteger('attended_to')->nullable();

                // "Highest level / units earned" — only if not graduated.
                $t->string('units_earned', 60)->nullable();
                $t->unsignedSmallInteger('year_graduated')->nullable();
                $t->string('honors', 150)->nullable();

                $t->dateTime('created_at', 3);
                $t->dateTime('updated_at', 3)->nullable();
            });
        }

        // Each step below is checked on its own, not under the table check: a
        // run that stopped after the create has the table and none of them.
        if (! Schema::hasIndex('employee_education', 'eedu_employee_level_idx')) {
            Schema::table('employee_education', function (Blueprint $t): void {
                $t->index(['employee_id', 'level'], 'eedu_employee_level_idx');
            });
        }

        if (! Schema::hasForeignKey('employee_education', ['employee_id'])) {
            Schema::table('employee_education', function (Blueprint $t): void {
                $t->foreign('employee_id')->references('id')->on('employee_profiles')->cascadeOnDelete();
            });
        }

        if ($this->isMySql() && ! $this->hasCheck('employee_education', 'eedu_level_chk')) {
            DB::statement("ALTER TABLE `employee_education` ADD CONSTRAINT `eedu_level_chk` CHECK (`level` IN ('ELEMENTARY','SECONDARY','VOCATIONAL','COLLEGE','GRADUATE'))");
        }

        // Idempotent: a retry restates the same row format.
        $this->dynamicRowFormat('employee_education');
    }

    public function down(): void
    {
        // The index, the foreign key and the CHECK go with the table. Guarded
        // like every step in up(), so a repeated rollback runs no DDL at all.
        if (Schema::hasTable('employee_education')) {
            Schema::drop('employee_education');
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
