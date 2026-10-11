<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * NEW TABLE — it has no counterpart in prisma/schema.prisma.
 *
 * The Personal Data Sheet's other information (DELIBERATE DIVERGENCE: the
 * reference record has no Personal Data Sheet lists): three short lists of
 * text lines — special skills and hobbies, non-academic distinctions or
 * recognition, and membership in an association or organization. `kind` is
 * SKILL | DISTINCTION | MEMBERSHIP (varchar plus `eoth_kind_chk`), `position`
 * is the line within its list (0 first) and `text` the line itself.
 *
 * `eoth_employee_kind_position_unq` pins one row per line: a list is saved
 * whole, and the writer keeps line N on row N — it updates that row in place,
 * inserts a row for a line that has none and deletes the rows past the last
 * line by primary key — so two lines can never claim one position, and the
 * unique never sees a collision. It also serves every per-employee read by
 * its prefix.
 *
 * The rows are written only by the Workforce commands, inside a transaction
 * that locked the employee's `employee_profiles` row first — the rows read
 * plainly, never with a locking read or a delete by range, which on InnoDB
 * would gap-lock the unique and deadlock two first saves for different
 * employees — and read only through `EmployeeRecords`, behind the record's
 * own visibility rule; nothing else joins, counts or reports on them. So they
 * carry no actor scope, like `employee_children`. A row is edited in place,
 * so the table has a nullable `updated_at`.
 *
 * `employee_id` cascades like `employee_children`: the lines are part of the
 * record and go with it. The unique is declared BEFORE the foreign key so
 * MySQL/MariaDB reuse it for the constraint. No legacy column is read, copied
 * or written.
 *
 * RETRY-SAFE, STEP BY STEP. Production migrates from a temporary cPanel cron
 * that can die between statements, and MariaDB DDL does not roll back, so the
 * create carries only the columns and the primary key, and every other piece
 * is its own ALTER behind its own guard: the unique only while hasIndex()
 * does not list its name, the foreign key only while hasForeignKey() finds
 * none on its column, and the CHECK only while
 * information_schema.TABLE_CONSTRAINTS lists no constraint of that name.
 * ROW_FORMAT=DYNAMIC restates the same format on a retry and needs no guard.
 * On sqlite (tests) the foreign key is a table rebuild that carries the index
 * over, and no CHECK is created. down() drops the table, and the index, key
 * and CHECK with it, only if it exists.
 *
 * ROLLBACK LOSES every skill, distinction and membership typed since: nothing
 * else in the system holds them.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('employee_other_items')) {
            Schema::create('employee_other_items', function (Blueprint $t): void {
                $t->engine = 'InnoDB';

                $t->char('id', 26)->primary();
                $t->char('employee_id', 26);

                // SKILL | DISTINCTION | MEMBERSHIP — free text plus eoth_kind_chk.
                $t->string('kind', 12);

                // The line within its list, 0 first.
                $t->unsignedTinyInteger('position');

                $t->string('text', 255);

                $t->dateTime('created_at', 3);
                $t->dateTime('updated_at', 3)->nullable();
            });
        }

        // Each step below is checked on its own, not under the table check: a
        // run that stopped after the create has the table and none of them.
        if (! Schema::hasIndex('employee_other_items', 'eoth_employee_kind_position_unq')) {
            Schema::table('employee_other_items', function (Blueprint $t): void {
                $t->unique(['employee_id', 'kind', 'position'], 'eoth_employee_kind_position_unq');
            });
        }

        if (! Schema::hasForeignKey('employee_other_items', ['employee_id'])) {
            Schema::table('employee_other_items', function (Blueprint $t): void {
                $t->foreign('employee_id')->references('id')->on('employee_profiles')->cascadeOnDelete();
            });
        }

        if ($this->isMySql() && ! $this->hasCheck('employee_other_items', 'eoth_kind_chk')) {
            DB::statement("ALTER TABLE `employee_other_items` ADD CONSTRAINT `eoth_kind_chk` CHECK (`kind` IN ('SKILL','DISTINCTION','MEMBERSHIP'))");
        }

        // Idempotent: a retry restates the same row format.
        $this->dynamicRowFormat('employee_other_items');
    }

    public function down(): void
    {
        // The index, the foreign key and the CHECK go with the table. Guarded
        // like every step in up(), so a repeated rollback runs no DDL at all.
        if (Schema::hasTable('employee_other_items')) {
            Schema::drop('employee_other_items');
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
