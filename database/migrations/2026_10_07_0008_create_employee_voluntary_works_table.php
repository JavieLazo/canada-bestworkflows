<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * NEW TABLE — it has no counterpart in prisma/schema.prisma.
 *
 * An employee's voluntary work as the Personal Data Sheet lists it
 * (DELIBERATE DIVERGENCE: the reference record has no Personal Data Sheet
 * lists): one row per involvement in a civic, non-government, people or
 * voluntary organization, with the organization's name and address, the
 * dates from and to, the number of hours and the position or nature of the
 * work. Only the organization is required; the dates are optional because
 * the sheet allows an entry without them.
 *
 * No unique: a row is identified by its id, an employee may list any number
 * of entries, and reads order the rows by their dates. `evol_employee_idx`
 * serves every per-employee read.
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
 * `employee_id` cascades like `employee_children`: the entries are part of
 * the record and go with it. The index is declared BEFORE the foreign key so
 * MySQL/MariaDB reuse it for the constraint. No legacy column is read, copied
 * or written.
 *
 * RETRY-SAFE, STEP BY STEP. Production migrates from a temporary cPanel cron
 * that can die between statements, and MariaDB DDL does not roll back, so the
 * create carries only the columns and the primary key, and every other piece
 * is its own ALTER behind its own guard: the index only while hasIndex() does
 * not list its name, and the foreign key only while hasForeignKey() finds
 * none on its column. ROW_FORMAT=DYNAMIC restates the same format on a retry
 * and needs no guard. On sqlite (tests) the foreign key is a table rebuild
 * that carries the index over. down() drops the table, and the index and key
 * with it, only if it exists.
 *
 * ROLLBACK LOSES every voluntary work entry typed since: nothing else in the
 * system holds them.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('employee_voluntary_works')) {
            Schema::create('employee_voluntary_works', function (Blueprint $t): void {
                $t->engine = 'InnoDB';

                $t->char('id', 26)->primary();
                $t->char('employee_id', 26);

                $t->string('organization', 150);
                $t->string('organization_address', 255)->nullable();

                $t->date('date_from')->nullable();
                $t->date('date_to')->nullable();

                // Hours to two decimals, like a day count.
                $t->decimal('hours', 6, 2)->nullable();

                // "Position / nature of work".
                $t->string('position', 150)->nullable();

                $t->dateTime('created_at', 3);
                $t->dateTime('updated_at', 3)->nullable();
            });
        }

        // Each step below is checked on its own, not under the table check: a
        // run that stopped after the create has the table and none of them.
        if (! Schema::hasIndex('employee_voluntary_works', 'evol_employee_idx')) {
            Schema::table('employee_voluntary_works', function (Blueprint $t): void {
                $t->index(['employee_id'], 'evol_employee_idx');
            });
        }

        if (! Schema::hasForeignKey('employee_voluntary_works', ['employee_id'])) {
            Schema::table('employee_voluntary_works', function (Blueprint $t): void {
                $t->foreign('employee_id')->references('id')->on('employee_profiles')->cascadeOnDelete();
            });
        }

        // Idempotent: a retry restates the same row format.
        $this->dynamicRowFormat('employee_voluntary_works');
    }

    public function down(): void
    {
        // The index and the foreign key go with the table. Guarded like every
        // step in up(), so a repeated rollback runs no DDL at all.
        if (Schema::hasTable('employee_voluntary_works')) {
            Schema::drop('employee_voluntary_works');
        }
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
