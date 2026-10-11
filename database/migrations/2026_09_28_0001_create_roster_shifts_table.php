<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Weekly hours complement weekly places: one copied pattern or explicit rest
 * per employee and weekday. No row retains the standing shift's behavior.
 * Pattern deletion NULLS its link and preserves its name and hour snapshot;
 * employee deletion cascades because a roster is configuration, not history.
 * No existing rows are rewritten. Finalized payroll retains its own evidence.
 * MariaDB forbids a SET NULL foreign-key column in CHECK expressions. The
 * rest CHECK therefore validates the saved rest payload (no working weekday
 * or source pattern); SaveRosterShifts clears the live pattern link. The FK
 * only enforces reference existence and snapshot-preserving deletion.
 *
 * RETRY-SAFE: create, indexes, foreign keys and CHECKs have independent guards
 * because MariaDB DDL commits each step. Named indexes precede their foreign
 * keys. Repeating ROW_FORMAT=DYNAMIC is harmless. Rollback drops this table
 * only and LOSES weekday hour choices, reverting to every standing shift.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('roster_shifts')) {
            Schema::create('roster_shifts', function (Blueprint $t): void {
                $t->engine = 'InnoDB';
                $t->char('id', 26)->primary();
                $t->char('employee_id', 26);
                $t->unsignedTinyInteger('weekday');
                $t->char('shift_type_id', 26)->nullable();
                $t->string('shift_name', 160);
                $t->boolean('is_rest_day')->default(false);
                $t->json('shift_data');
                $t->dateTime('created_at', 3);
                $t->dateTime('updated_at', 3)->nullable();
            });
        }
        // STANDS DOWN once 2026_09_30_0005 has dated the rows (owner-approved
        // edit, 2026-09-30): that migration retires this unique, and a re-run
        // here must not bring it back.
        if (! Schema::hasIndex('roster_shifts', 'rshift_employee_weekday_unq')
            && ! Schema::hasColumn('roster_shifts', 'effective_from')) {
            Schema::table('roster_shifts', static fn (Blueprint $t) => $t->unique(['employee_id', 'weekday'], 'rshift_employee_weekday_unq'));
        }
        if (! Schema::hasIndex('roster_shifts', 'rshift_type_idx')) {
            Schema::table('roster_shifts', static fn (Blueprint $t) => $t->index('shift_type_id', 'rshift_type_idx'));
        }
        if (! Schema::hasForeignKey('roster_shifts', ['employee_id'])) {
            Schema::table('roster_shifts', static fn (Blueprint $t) => $t->foreign('employee_id')->references('id')->on('employee_profiles')->cascadeOnDelete());
        }
        if (! Schema::hasForeignKey('roster_shifts', ['shift_type_id'])) {
            Schema::table('roster_shifts', static fn (Blueprint $t) => $t->foreign('shift_type_id')->references('id')->on('shift_types')->nullOnDelete());
        }
        $this->dynamicRowFormat();
        if ($this->isMySql() && ! $this->hasCheck('rshift_weekday_chk')) {
            DB::statement('ALTER TABLE `roster_shifts` ADD CONSTRAINT `rshift_weekday_chk` CHECK (`weekday` BETWEEN 0 AND 6)');
        }
        if ($this->isMySql() && ! $this->hasCheck('rshift_rest_chk')) {
            DB::statement(<<<'SQL'
                ALTER TABLE `roster_shifts` ADD CONSTRAINT `rshift_rest_chk` CHECK (
                    `is_rest_day` IN (0,1) AND (`is_rest_day` = 0 OR (
                        COALESCE(JSON_EXTRACT(`shift_data`, '$.work_days') = JSON_ARRAY(-1), 0)
                        AND COALESCE(JSON_TYPE(JSON_EXTRACT(`shift_data`, '$.shift_type_id')) = 'NULL', 0)
                    ))
                )
                SQL);
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('roster_shifts')) {
            Schema::drop('roster_shifts');
        }
    }

    private function hasCheck(string $name): bool
    {
        $row = DB::selectOne("SELECT COUNT(*) AS n FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'roster_shifts' AND CONSTRAINT_NAME = ? AND CONSTRAINT_TYPE = 'CHECK'", [$name]);

        return (int) ($row->n ?? 0) > 0;
    }

    private function dynamicRowFormat(): void
    {
        if ($this->isMySql()) {
            DB::statement('ALTER TABLE `roster_shifts` ROW_FORMAT=DYNAMIC');
        }
    }

    private function isMySql(): bool
    {
        return in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true);
    }
};
