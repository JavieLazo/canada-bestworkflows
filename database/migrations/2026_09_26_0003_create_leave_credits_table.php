<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * NEW TABLE — it has no counterpart in prisma/schema.prisma.
 *
 * Leave credits: how many days of each leave type an employee is entitled to
 * in a calendar year, entered BY HAND on Leave Management's Leave Credits tab
 * (2026-09-26). The reference carried two numbers on the employee record,
 * `vacation_credits` and `sick_credits`, that nothing ever read or drew down;
 * ems removed them (2026_09_25_0002) and, one day later, replaced them with
 * this table so a credit can exist per Leave Settings type and per year
 * rather than as two fixed columns forever.
 *
 * One row per (employee, year, request_type). `request_type` is the SAME
 * token `leave_travel_requests.request_type` stores — a Leave Settings name
 * normalised by `HrisFormat::normalizeRequestType` (`VACATION_LEAVE`) — and
 * deliberately NOT a foreign key to `leave_types`: requests are keyed by that
 * token too, so the two tables agree by construction, and a text copy
 * survives a type being deleted from Records Management exactly as the
 * requests it labels do (the rule every option name in this schema follows).
 *
 * NOTHING IS DRAWN DOWN HERE. The balance an employee sees is derived at read
 * time — this row's `credits` minus the covered days of the APPROVED,
 * with-pay requests of that type whose start date falls in `year`
 * (`Modules\Timekeeping\Domain\Absence\LeaveCreditPolicy`) — so a re-review
 * or a withdrawal never leaves a stale deduction behind. Filing and approval
 * are not refused on an exhausted balance; the screens show it and warn.
 *
 * `credits` is DECIMAL(6,2) like the two columns it replaces (a half day is
 * the smallest unit the DTR produces), cast `float` on the model, never
 * `decimal:N`. `lcr_credits_chk` and `lcr_year_chk` pin the ranges on
 * MySQL/MariaDB; the command validates the same ranges everywhere.
 *
 * `employee_id` cascades like `roster_locations`: an entitlement is
 * configuration, not history. Shape follows 2026_09_26_0001: a ULID primary
 * key minted by BaseModel, the unique named BEFORE the foreign key so MySQL
 * reuses it for the constraint, and `lcr_employee_year_type_unq` also serves
 * every per-employee read by its prefix.
 *
 * RETRY-SAFE, STEP BY STEP. Production migrates from a temporary cPanel cron
 * with no SSH and MySQL DDL does not roll back, so a run can stop between any
 * two statements and is simply run again. The create carries only the columns
 * and the primary key; every other piece is its own ALTER with its own guard,
 * so a retry resumes wherever the last run stopped: the table only while
 * hasTable() is false, the unique only while hasIndex() does not list the
 * name, the foreign key only while hasForeignKey() finds none on its column,
 * and each CHECK only while information_schema.TABLE_CONSTRAINTS lists no
 * constraint of that name. ROW_FORMAT=DYNAMIC restates the same format on a
 * retry, so it needs no guard. On SQLite (tests) the foreign key is a table
 * rebuild, which carries the index over, and no CHECK is created. down()
 * drops the table, and the index, key and CHECKs with it, only if it exists.
 *
 * ROLLBACK LOSES every entered leave credit. Requests are untouched: they
 * never referenced this table.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('leave_credits')) {
            Schema::create('leave_credits', function (Blueprint $t): void {
                $t->engine = 'InnoDB';

                $t->char('id', 26)->primary();
                $t->char('employee_id', 26);

                // The calendar year the entitlement belongs to (lcr_year_chk).
                $t->unsignedSmallInteger('year');

                // The leave type's normalised key — the token requests store
                // (leave_travel_requests.request_type is string(60) too).
                $t->string('request_type', 60);

                // Days credited, halves allowed; 0 is an explicit zero
                // (lcr_credits_chk). No row means "not entered".
                $t->decimal('credits', 6, 2);

                $t->dateTime('created_at', 3);
                $t->dateTime('updated_at', 3)->nullable();
            });
        }

        // Every step below is checked on its own, not under the table check:
        // a run that stopped after the create has the table and none of them.
        // The unique comes BEFORE the foreign key, so MySQL reuses it for the
        // constraint instead of adding its own (roster_locations precedent).
        if (! Schema::hasIndex('leave_credits', 'lcr_employee_year_type_unq')) {
            Schema::table('leave_credits', function (Blueprint $t): void {
                $t->unique(['employee_id', 'year', 'request_type'], 'lcr_employee_year_type_unq');
            });
        }

        if (! Schema::hasForeignKey('leave_credits', ['employee_id'])) {
            Schema::table('leave_credits', function (Blueprint $t): void {
                $t->foreign('employee_id')->references('id')->on('employee_profiles')->cascadeOnDelete();
            });
        }

        // Idempotent: a retry restates the same row format.
        $this->dynamicRowFormat('leave_credits');

        // Each CHECK behind its own guard like every step above: a run that
        // stopped between the two has the first and not the second. `year`
        // is a MySQL keyword, hence the backticks.
        if ($this->isMySql() && ! $this->hasCheck('leave_credits', 'lcr_credits_chk')) {
            DB::statement('ALTER TABLE `leave_credits` ADD CONSTRAINT `lcr_credits_chk` CHECK (`credits` >= 0 AND `credits` <= 9999.99)');
        }

        if ($this->isMySql() && ! $this->hasCheck('leave_credits', 'lcr_year_chk')) {
            DB::statement('ALTER TABLE `leave_credits` ADD CONSTRAINT `lcr_year_chk` CHECK (`year` BETWEEN 2000 AND 2100)');
        }
    }

    public function down(): void
    {
        // The CHECKs, the index and the foreign key all go with the table.
        // Guarded like every step in up(), so a repeated rollback runs no DDL
        // at all rather than a DROP ... IF EXISTS that finds nothing.
        if (Schema::hasTable('leave_credits')) {
            Schema::drop('leave_credits');
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
