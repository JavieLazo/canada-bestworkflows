<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * NEW TABLE — `roster_schedule_changes`: one row per change to an employee's
 * default shift, weekday hours or weekday places, and the date it takes
 * effect (the owner's decision, 2026-09-30: each DTR date keeps the schedule
 * that applied to it; a later change never re-judges an earlier date).
 *
 * The hours and places themselves stay in `shifts`, `roster_shifts` and
 * `roster_locations`, which gain `effective_from` and `change_id` in the
 * three migrations after this one: a row belongs to the change that wrote it
 * and applies from that change's date until a later row for the same default
 * shift or weekday replaces it. This table holds what the rows share — when,
 * how (MANUAL, IMPORT, PATTERN_EDIT), by whom, and whether the change was
 * cancelled before it took effect.
 *
 * BASELINE rows (effective_from NULL) mark the history that existed before
 * this table: `assumed_before` is the first date the system recorded
 * schedules for that employee, so every earlier date reads "Assumed", and
 * `may_differ_through` is the last date audit evidence suggests a schedule
 * change was made — dates up to it read "Schedule may have differed".
 * 2026_09_30_0008 writes them. Neither is ever presented as verified history.
 *
 * `created_by` and `cancelled_by` are accounts (`users.id`, bigint), set from
 * the signed-in actor, never from input, and kept out of `$fillable`; SET NULL
 * like the other ported account columns, with Timekeeping's
 * AccountReferences refusing the delete first. MariaDB forbids SET NULL
 * foreign-key columns in a CHECK (error 1901), so the CHECKs below name
 * neither.
 *
 * RETRY-SAFE: the CREATE, each index, each foreign key and each CHECK has its
 * own guard, so a cron run killed between any two statements resumes where
 * it stopped. ROLLBACK LOSES every recorded schedule change and baseline
 * marker. It runs after the 0004–0006 rollbacks, which remove the foreign
 * keys that point here.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('roster_schedule_changes')) {
            Schema::create('roster_schedule_changes', function (Blueprint $t): void {
                $t->engine = 'InnoDB';
                $t->char('id', 26)->primary();
                $t->char('employee_id', 26);

                // NULL only on the BASELINE row: "since before history was kept".
                $t->date('effective_from')->nullable();

                // BASELINE | MANUAL | IMPORT | PATTERN_EDIT — rsc_source_chk.
                $t->string('source', 16);

                // ACTIVE | CANCELLED — rsc_status_chk. Only a change that has
                // not yet taken effect is ever cancelled.
                $t->string('status', 12)->default('ACTIVE');

                $t->date('assumed_before')->nullable();
                $t->date('may_differ_through')->nullable();

                $t->unsignedBigInteger('created_by')->nullable();
                $t->unsignedBigInteger('cancelled_by')->nullable();
                $t->dateTime('cancelled_at', 3)->nullable();
                $t->dateTime('created_at', 3);
                $t->dateTime('updated_at', 3)->nullable();
            });
        }

        if (! Schema::hasIndex('roster_schedule_changes', 'rsc_employee_eff_idx')) {
            Schema::table('roster_schedule_changes', static fn (Blueprint $t) => $t->index(['employee_id', 'effective_from'], 'rsc_employee_eff_idx'));
        }
        if (! Schema::hasIndex('roster_schedule_changes', 'rsc_created_by_idx')) {
            Schema::table('roster_schedule_changes', static fn (Blueprint $t) => $t->index('created_by', 'rsc_created_by_idx'));
        }
        if (! Schema::hasIndex('roster_schedule_changes', 'rsc_cancelled_by_idx')) {
            Schema::table('roster_schedule_changes', static fn (Blueprint $t) => $t->index('cancelled_by', 'rsc_cancelled_by_idx'));
        }
        if (! Schema::hasForeignKey('roster_schedule_changes', ['employee_id'])) {
            Schema::table('roster_schedule_changes', static fn (Blueprint $t) => $t->foreign('employee_id')->references('id')->on('employee_profiles')->cascadeOnDelete());
        }
        if (! Schema::hasForeignKey('roster_schedule_changes', ['created_by'])) {
            Schema::table('roster_schedule_changes', static fn (Blueprint $t) => $t->foreign('created_by')->references('id')->on('users')->nullOnDelete());
        }
        if (! Schema::hasForeignKey('roster_schedule_changes', ['cancelled_by'])) {
            Schema::table('roster_schedule_changes', static fn (Blueprint $t) => $t->foreign('cancelled_by')->references('id')->on('users')->nullOnDelete());
        }

        $this->dynamicRowFormat();

        if ($this->isMySql() && ! $this->hasCheck('rsc_source_chk')) {
            DB::statement("ALTER TABLE `roster_schedule_changes` ADD CONSTRAINT `rsc_source_chk` CHECK (`source` IN ('BASELINE','MANUAL','IMPORT','PATTERN_EDIT'))");
        }
        if ($this->isMySql() && ! $this->hasCheck('rsc_status_chk')) {
            DB::statement("ALTER TABLE `roster_schedule_changes` ADD CONSTRAINT `rsc_status_chk` CHECK (`status` IN ('ACTIVE','CANCELLED'))");
        }
        if ($this->isMySql() && ! $this->hasCheck('rsc_baseline_chk')) {
            DB::statement("ALTER TABLE `roster_schedule_changes` ADD CONSTRAINT `rsc_baseline_chk` CHECK ((`source` = 'BASELINE' AND `effective_from` IS NULL) OR (`source` <> 'BASELINE' AND `effective_from` IS NOT NULL AND `assumed_before` IS NULL AND `may_differ_through` IS NULL))");
        }
        if ($this->isMySql() && ! $this->hasCheck('rsc_cancel_chk')) {
            DB::statement("ALTER TABLE `roster_schedule_changes` ADD CONSTRAINT `rsc_cancel_chk` CHECK ((`status` = 'ACTIVE' AND `cancelled_at` IS NULL) OR (`status` = 'CANCELLED' AND `cancelled_at` IS NOT NULL AND `source` <> 'BASELINE'))");
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('roster_schedule_changes')) {
            Schema::drop('roster_schedule_changes');
        }
    }

    private function hasCheck(string $name): bool
    {
        $row = DB::selectOne("SELECT COUNT(*) AS n FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'roster_schedule_changes' AND CONSTRAINT_NAME = ? AND CONSTRAINT_TYPE = 'CHECK'", [$name]);

        return (int) ($row->n ?? 0) > 0;
    }

    private function dynamicRowFormat(): void
    {
        if ($this->isMySql()) {
            DB::statement('ALTER TABLE `roster_schedule_changes` ROW_FORMAT=DYNAMIC');
        }
    }

    private function isMySql(): bool
    {
        return in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true);
    }
};
