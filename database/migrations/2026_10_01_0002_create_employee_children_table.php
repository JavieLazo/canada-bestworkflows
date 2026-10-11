<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * NEW TABLE — it has no counterpart in prisma/schema.prisma.
 *
 * The children on an employee's paper 201 file (2026-10-01, DELIBERATE
 * DIVERGENCE: the reference record had no family section): the "Children's
 * Name / Birthdate" lines under the spouse. One row per child, `position`
 * being the line it is printed on (0 first), so the sheet lists them in the
 * order HR typed them and a re-save keeps that order. `name` is required on
 * every row and `birth_date` may be left blank, exactly as the paper form
 * allows.
 *
 * Rows of their own rather than a JSON column on `employee_profiles`: that
 * table takes no TEXT or JSON column (the database guide's row-format rule),
 * and a list of children is not a column. They are written only with the
 * Family section of the record — `UpdateEmployee` replaces the employee's
 * rows inside the same transaction as the profile save, `CreateEmployee`
 * inserts them with the hire — and read only through the record they belong
 * to, behind its visibility rule, never on their own; nothing else joins,
 * counts or reports on them. So they carry no actor scope, the way
 * `project_task_files` are read behind their task. The rows are replaced
 * whole on every save, never edited in place, so the table has `created_at`
 * and no `updated_at`.
 *
 * `employee_id` cascades like `employee_documents`: the children are part of
 * the record and go with it. `echild_employee_position_unq` pins one row per
 * line and serves every per-employee read by its prefix; it is declared
 * BEFORE the foreign key so MySQL/MariaDB reuse it for the constraint.
 *
 * RETRY-SAFE, STEP BY STEP. Production migrates from a temporary cPanel cron
 * that can die between statements, and MariaDB DDL does not roll back, so the
 * create carries only the columns and the primary key, and every other piece
 * is its own ALTER behind its own guard: the unique only while hasIndex()
 * does not list its name, the foreign key only while hasForeignKey() finds
 * none on its column. ROW_FORMAT=DYNAMIC restates the same format on a retry
 * and needs no guard. On sqlite (tests) the foreign key is a table rebuild
 * that carries the index over. down() drops the table, and the index and key
 * with it, only if it exists.
 *
 * ROLLBACK LOSES every child entered since: nothing else in the system holds
 * them.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('employee_children')) {
            Schema::create('employee_children', function (Blueprint $t): void {
                $t->engine = 'InnoDB';

                $t->char('id', 26)->primary();
                $t->char('employee_id', 26);

                // The line on the sheet, 0 first — the order HR typed them.
                $t->unsignedTinyInteger('position');

                $t->string('name', 120);
                $t->date('birth_date')->nullable();

                $t->dateTime('created_at', 3);
            });
        }

        // Each step below is checked on its own, not under the table check: a
        // run that stopped after the create has the table and neither of them.
        if (! Schema::hasIndex('employee_children', 'echild_employee_position_unq')) {
            Schema::table('employee_children', function (Blueprint $t): void {
                $t->unique(['employee_id', 'position'], 'echild_employee_position_unq');
            });
        }

        if (! Schema::hasForeignKey('employee_children', ['employee_id'])) {
            Schema::table('employee_children', function (Blueprint $t): void {
                $t->foreign('employee_id')->references('id')->on('employee_profiles')->cascadeOnDelete();
            });
        }

        // Idempotent: a retry restates the same row format.
        $this->dynamicRowFormat('employee_children');
    }

    public function down(): void
    {
        // The index and the foreign key go with the table. Guarded like every
        // step in up(), so a repeated rollback runs no DDL at all.
        if (Schema::hasTable('employee_children')) {
            Schema::drop('employee_children');
        }
    }

    private function dynamicRowFormat(string ...$tables): void
    {
        if (! in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }

        foreach ($tables as $table) {
            DB::statement("ALTER TABLE `{$table}` ROW_FORMAT=DYNAMIC");
        }
    }
};
