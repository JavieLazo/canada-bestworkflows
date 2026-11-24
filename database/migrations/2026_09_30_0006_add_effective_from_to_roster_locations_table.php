<?php

declare(strict_types=1);

use App\Support\LocalClock;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * NEW COLUMNS — `roster_locations.effective_from` and
 * `roster_locations.change_id`: weekday places become a history, like the
 * hours in 2026_09_30_0004/0005.
 *
 * Existing rows keep NULL ("since before history was kept") and still apply
 * on every date. The place for a weekday on a date is the latest row for that
 * weekday whose `effective_from` is on or before it, unless its change was
 * cancelled.
 *
 * Going back to the Assigned Worksite from a date used to be a delete, which
 * would erase that weekday's history. It is now a row with NULL `kind` and
 * NULL `site_id`, so `kind` becomes nullable. `rloc_kind_chk` and
 * `rloc_site_chk` read NULL as unknown and pass it, so `rloc_default_chk`
 * adds the one rule they cannot state: a row with no kind has no site.
 * `site_id` keeps its RESTRICT foreign key, so a site that any version names
 * stays in the history.
 *
 * `rloc_employee_weekday_unq` goes after `rloc_employee_weekday_eff_idx`
 * takes over the employee foreign key's index (MariaDB error 1553).
 *
 * RETRY-SAFE: each column, index, foreign key, nullability change, CHECK and
 * the unique drop has its own guard. ROLLBACK LOSES the history: per employee
 * and weekday only the row in effect on the Manila day of the rollback is
 * kept, and an Assigned Worksite row is deleted, as before.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('roster_locations', 'effective_from')) {
            Schema::table('roster_locations', static fn (Blueprint $t) => $t->date('effective_from')->nullable());
        }
        if (! Schema::hasColumn('roster_locations', 'change_id')) {
            Schema::table('roster_locations', static fn (Blueprint $t) => $t->char('change_id', 26)->nullable());
        }
        if (! Schema::hasIndex('roster_locations', 'rloc_employee_weekday_eff_idx')) {
            Schema::table('roster_locations', static fn (Blueprint $t) => $t->index(['employee_id', 'weekday', 'effective_from'], 'rloc_employee_weekday_eff_idx'));
        }
        if (! Schema::hasIndex('roster_locations', 'rloc_change_idx')) {
            Schema::table('roster_locations', static fn (Blueprint $t) => $t->index('change_id', 'rloc_change_idx'));
        }
        if (! Schema::hasForeignKey('roster_locations', ['change_id'])) {
            Schema::table('roster_locations', static fn (Blueprint $t) => $t->foreign('change_id')->references('id')->on('roster_schedule_changes')->cascadeOnDelete());
        }
        if (Schema::hasIndex('roster_locations', 'rloc_employee_weekday_unq')) {
            Schema::table('roster_locations', static fn (Blueprint $t) => $t->dropUnique('rloc_employee_weekday_unq'));
        }
        if (! $this->isNullable('kind')) {
            Schema::table('roster_locations', static fn (Blueprint $t) => $t->string('kind', 10)->nullable()->change());
        }
        if ($this->isMySql() && ! $this->hasCheck('rloc_default_chk')) {
            DB::statement('ALTER TABLE `roster_locations` ADD CONSTRAINT `rloc_default_chk` CHECK (`kind` IS NOT NULL OR `site_id` IS NULL)');
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('roster_locations', 'effective_from')) {
            $this->keepOnlyTodaysRows();
        }
        if ($this->isMySql() && $this->hasCheck('rloc_default_chk')) {
            DB::statement('ALTER TABLE `roster_locations` DROP CONSTRAINT `rloc_default_chk`');
        }
        if ($this->isNullable('kind')) {
            Schema::table('roster_locations', static fn (Blueprint $t) => $t->string('kind', 10)->nullable(false)->change());
        }
        if (! Schema::hasIndex('roster_locations', 'rloc_employee_weekday_unq')) {
            Schema::table('roster_locations', static fn (Blueprint $t) => $t->unique(['employee_id', 'weekday'], 'rloc_employee_weekday_unq'));
        }
        if (Schema::hasForeignKey('roster_locations', ['change_id'])) {
            Schema::table('roster_locations', static fn (Blueprint $t) => $t->dropForeign(['change_id']));
        }
        if (Schema::hasIndex('roster_locations', 'rloc_change_idx')) {
            Schema::table('roster_locations', static fn (Blueprint $t) => $t->dropIndex('rloc_change_idx'));
        }
        if (Schema::hasIndex('roster_locations', 'rloc_employee_weekday_eff_idx')) {
            Schema::table('roster_locations', static fn (Blueprint $t) => $t->dropIndex('rloc_employee_weekday_eff_idx'));
        }
        if (Schema::hasColumn('roster_locations', 'change_id')) {
            Schema::table('roster_locations', static fn (Blueprint $t) => $t->dropColumn('change_id'));
        }
        if (Schema::hasColumn('roster_locations', 'effective_from')) {
            Schema::table('roster_locations', static fn (Blueprint $t) => $t->dropColumn('effective_from'));
        }
    }

    /** One row per employee and weekday again: the one in effect today; an Assigned Worksite row means no row. */
    private function keepOnlyTodaysRows(): void
    {
        $today = LocalClock::todayKey();
        $cancelled = Schema::hasTable('roster_schedule_changes')
            ? DB::table('roster_schedule_changes')->where('status', 'CANCELLED')->pluck('id')->all()
            : [];
        $keep = [];
        $rows = DB::table('roster_locations')->orderBy('employee_id')->orderBy('weekday')->orderBy('effective_from')->orderBy('id')
            ->get(['id', 'employee_id', 'weekday', 'effective_from', 'change_id', 'kind']);
        foreach ($rows as $row) {
            $live = $row->change_id === null || ! in_array($row->change_id, $cancelled, true);
            if ($live && ($row->effective_from === null || (string) $row->effective_from <= $today)) {
                $keep[$row->employee_id.'|'.$row->weekday] = $row;
            }
        }
        $kept = array_values(array_map(static fn (object $row): string => $row->id, array_filter($keep, static fn (object $row): bool => $row->kind !== null)));
        $drop = $rows->pluck('id')->diff($kept)->values()->all();
        foreach (array_chunk($drop, 500) as $chunk) {
            DB::table('roster_locations')->whereIn('id', $chunk)->delete();
        }
    }

    private function isNullable(string $column): bool
    {
        foreach (Schema::getColumns('roster_locations') as $definition) {
            if ($definition['name'] === $column) {
                return (bool) $definition['nullable'];
            }
        }

        return false;
    }

    private function hasCheck(string $name): bool
    {
        $row = DB::selectOne("SELECT COUNT(*) AS n FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'roster_locations' AND CONSTRAINT_NAME = ? AND CONSTRAINT_TYPE = 'CHECK'", [$name]);

        return (int) ($row->n ?? 0) > 0;
    }

    private function isMySql(): bool
    {
        return in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true);
    }
};
