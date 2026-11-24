<?php

declare(strict_types=1);

use App\Support\LocalClock;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * NEW COLUMNS — `roster_shifts.effective_from` and `roster_shifts.change_id`:
 * weekday hours become a history, like the default shift in 2026_09_30_0004.
 *
 * Existing rows keep NULL ("since before history was kept") and still apply
 * on every date, so nothing recalculates. The row for a weekday on a date is
 * the latest one for that weekday whose `effective_from` is on or before it,
 * unless its change was cancelled.
 *
 * A weekday can go BACK to the default shift from a date, which used to be a
 * delete. A delete would erase that weekday's history, so it is now a row
 * with NULL `shift_data` and `shift_name` (a "follows the default shift"
 * row): `shift_data` and `shift_name` become nullable, and
 * `rshift_default_chk` allows NULL only on such a row — not a rest day, and
 * both columns together. `shift_type_id` has a SET NULL foreign key, which
 * MariaDB forbids in a CHECK (error 1901); the command clears it.
 *
 * `rshift_employee_weekday_unq` goes after `rshift_employee_weekday_eff_idx`
 * takes over the employee foreign key's index (MariaDB error 1553).
 *
 * RETRY-SAFE: each column, index, foreign key, nullability change, CHECK and
 * the unique drop has its own guard. ROLLBACK LOSES the history: per employee
 * and weekday only the row in effect on the Manila day of the rollback is
 * kept, and a "follows the default shift" row is deleted, as before.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('roster_shifts', 'effective_from')) {
            Schema::table('roster_shifts', static fn (Blueprint $t) => $t->date('effective_from')->nullable());
        }
        if (! Schema::hasColumn('roster_shifts', 'change_id')) {
            Schema::table('roster_shifts', static fn (Blueprint $t) => $t->char('change_id', 26)->nullable());
        }
        if (! Schema::hasIndex('roster_shifts', 'rshift_employee_weekday_eff_idx')) {
            Schema::table('roster_shifts', static fn (Blueprint $t) => $t->index(['employee_id', 'weekday', 'effective_from'], 'rshift_employee_weekday_eff_idx'));
        }
        if (! Schema::hasIndex('roster_shifts', 'rshift_change_idx')) {
            Schema::table('roster_shifts', static fn (Blueprint $t) => $t->index('change_id', 'rshift_change_idx'));
        }
        if (! Schema::hasForeignKey('roster_shifts', ['change_id'])) {
            Schema::table('roster_shifts', static fn (Blueprint $t) => $t->foreign('change_id')->references('id')->on('roster_schedule_changes')->cascadeOnDelete());
        }
        if (Schema::hasIndex('roster_shifts', 'rshift_employee_weekday_unq')) {
            Schema::table('roster_shifts', static fn (Blueprint $t) => $t->dropUnique('rshift_employee_weekday_unq'));
        }
        if (! $this->isNullable('shift_name')) {
            Schema::table('roster_shifts', static fn (Blueprint $t) => $t->string('shift_name', 160)->nullable()->change());
        }
        if (! $this->isNullable('shift_data')) {
            Schema::table('roster_shifts', static fn (Blueprint $t) => $t->json('shift_data')->nullable()->change());
        }
        if ($this->isMySql() && ! $this->hasCheck('rshift_default_chk')) {
            DB::statement('ALTER TABLE `roster_shifts` ADD CONSTRAINT `rshift_default_chk` CHECK ((`shift_data` IS NULL AND `shift_name` IS NULL AND `is_rest_day` = 0) OR (`shift_data` IS NOT NULL AND `shift_name` IS NOT NULL))');
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('roster_shifts', 'effective_from')) {
            $this->keepOnlyTodaysRows();
        }
        if ($this->isMySql() && $this->hasCheck('rshift_default_chk')) {
            DB::statement('ALTER TABLE `roster_shifts` DROP CONSTRAINT `rshift_default_chk`');
        }
        if ($this->isNullable('shift_data')) {
            Schema::table('roster_shifts', static fn (Blueprint $t) => $t->json('shift_data')->nullable(false)->change());
        }
        if ($this->isNullable('shift_name')) {
            Schema::table('roster_shifts', static fn (Blueprint $t) => $t->string('shift_name', 160)->nullable(false)->change());
        }
        if (! Schema::hasIndex('roster_shifts', 'rshift_employee_weekday_unq')) {
            Schema::table('roster_shifts', static fn (Blueprint $t) => $t->unique(['employee_id', 'weekday'], 'rshift_employee_weekday_unq'));
        }
        if (Schema::hasForeignKey('roster_shifts', ['change_id'])) {
            Schema::table('roster_shifts', static fn (Blueprint $t) => $t->dropForeign(['change_id']));
        }
        if (Schema::hasIndex('roster_shifts', 'rshift_change_idx')) {
            Schema::table('roster_shifts', static fn (Blueprint $t) => $t->dropIndex('rshift_change_idx'));
        }
        if (Schema::hasIndex('roster_shifts', 'rshift_employee_weekday_eff_idx')) {
            Schema::table('roster_shifts', static fn (Blueprint $t) => $t->dropIndex('rshift_employee_weekday_eff_idx'));
        }
        if (Schema::hasColumn('roster_shifts', 'change_id')) {
            Schema::table('roster_shifts', static fn (Blueprint $t) => $t->dropColumn('change_id'));
        }
        if (Schema::hasColumn('roster_shifts', 'effective_from')) {
            Schema::table('roster_shifts', static fn (Blueprint $t) => $t->dropColumn('effective_from'));
        }
    }

    /** One row per employee and weekday again: the one in effect today; a default-shift row means no row. */
    private function keepOnlyTodaysRows(): void
    {
        $today = LocalClock::todayKey();
        $cancelled = Schema::hasTable('roster_schedule_changes')
            ? DB::table('roster_schedule_changes')->where('status', 'CANCELLED')->pluck('id')->all()
            : [];
        $keep = [];
        $rows = DB::table('roster_shifts')->orderBy('employee_id')->orderBy('weekday')->orderBy('effective_from')->orderBy('id')
            ->get(['id', 'employee_id', 'weekday', 'effective_from', 'change_id', 'shift_data']);
        foreach ($rows as $row) {
            $live = $row->change_id === null || ! in_array($row->change_id, $cancelled, true);
            if ($live && ($row->effective_from === null || (string) $row->effective_from <= $today)) {
                $keep[$row->employee_id.'|'.$row->weekday] = $row;
            }
        }
        $kept = array_values(array_map(static fn (object $row): string => $row->id, array_filter($keep, static fn (object $row): bool => $row->shift_data !== null)));
        $drop = $rows->pluck('id')->diff($kept)->values()->all();
        foreach (array_chunk($drop, 500) as $chunk) {
            DB::table('roster_shifts')->whereIn('id', $chunk)->delete();
        }
    }

    private function isNullable(string $column): bool
    {
        foreach (Schema::getColumns('roster_shifts') as $definition) {
            if ($definition['name'] === $column) {
                return (bool) $definition['nullable'];
            }
        }

        return false;
    }

    private function hasCheck(string $name): bool
    {
        $row = DB::selectOne("SELECT COUNT(*) AS n FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'roster_shifts' AND CONSTRAINT_NAME = ? AND CONSTRAINT_TYPE = 'CHECK'", [$name]);

        return (int) ($row->n ?? 0) > 0;
    }

    private function isMySql(): bool
    {
        return in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true);
    }
};
