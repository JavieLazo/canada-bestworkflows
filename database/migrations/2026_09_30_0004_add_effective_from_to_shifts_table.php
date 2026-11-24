<?php

declare(strict_types=1);

use App\Support\LocalClock;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * NEW COLUMNS — `shifts.effective_from` and `shifts.change_id`: the default
 * shift becomes a history instead of one overwritten row (2026-09-30).
 *
 * Every existing row keeps NULL in both, which reads "since before history
 * was kept": the row in effect on every date, exactly as today, so no DTR
 * result moves when this runs. A later change inserts a new row with the
 * date it takes effect and the `roster_schedule_changes` row it belongs to;
 * the row for a date is the latest one whose `effective_from` is on or
 * before it (NULL first), unless its change was cancelled.
 *
 * `shifts_employee_unq` therefore goes. The composite
 * `shifts_employee_eff_idx` is added FIRST, because the employee foreign key
 * needs an index on `employee_id` at every moment and MariaDB refuses to drop
 * the one it is using (error 1553).
 *
 * RETRY-SAFE: each column, index, foreign key and the unique drop has its own
 * guard. Both columns are appended without `->after()`, so MariaDB 11.4 adds
 * them instantly. ROLLBACK LOSES the history: per employee, only the row in
 * effect on the Manila day of the rollback is kept (future and superseded
 * rows are deleted) so the unique can return.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('shifts', 'effective_from')) {
            Schema::table('shifts', static fn (Blueprint $t) => $t->date('effective_from')->nullable());
        }
        if (! Schema::hasColumn('shifts', 'change_id')) {
            Schema::table('shifts', static fn (Blueprint $t) => $t->char('change_id', 26)->nullable());
        }
        if (! Schema::hasIndex('shifts', 'shifts_employee_eff_idx')) {
            Schema::table('shifts', static fn (Blueprint $t) => $t->index(['employee_id', 'effective_from'], 'shifts_employee_eff_idx'));
        }
        if (! Schema::hasIndex('shifts', 'shifts_change_idx')) {
            Schema::table('shifts', static fn (Blueprint $t) => $t->index('change_id', 'shifts_change_idx'));
        }
        if (! Schema::hasForeignKey('shifts', ['change_id'])) {
            Schema::table('shifts', static fn (Blueprint $t) => $t->foreign('change_id')->references('id')->on('roster_schedule_changes')->cascadeOnDelete());
        }
        if (Schema::hasIndex('shifts', 'shifts_employee_unq')) {
            Schema::table('shifts', static fn (Blueprint $t) => $t->dropUnique('shifts_employee_unq'));
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('shifts', 'effective_from')) {
            $this->keepOnlyTodaysRows();
        }
        if (! Schema::hasIndex('shifts', 'shifts_employee_unq')) {
            Schema::table('shifts', static fn (Blueprint $t) => $t->unique('employee_id', 'shifts_employee_unq'));
        }
        if (Schema::hasForeignKey('shifts', ['change_id'])) {
            Schema::table('shifts', static fn (Blueprint $t) => $t->dropForeign(['change_id']));
        }
        if (Schema::hasIndex('shifts', 'shifts_change_idx')) {
            Schema::table('shifts', static fn (Blueprint $t) => $t->dropIndex('shifts_change_idx'));
        }
        if (Schema::hasIndex('shifts', 'shifts_employee_eff_idx')) {
            Schema::table('shifts', static fn (Blueprint $t) => $t->dropIndex('shifts_employee_eff_idx'));
        }
        if (Schema::hasColumn('shifts', 'change_id')) {
            Schema::table('shifts', static fn (Blueprint $t) => $t->dropColumn('change_id'));
        }
        if (Schema::hasColumn('shifts', 'effective_from')) {
            Schema::table('shifts', static fn (Blueprint $t) => $t->dropColumn('effective_from'));
        }
    }

    /** One row per employee again: the one in effect today, ignoring cancelled changes. */
    private function keepOnlyTodaysRows(): void
    {
        $today = LocalClock::todayKey();
        $cancelled = Schema::hasTable('roster_schedule_changes')
            ? DB::table('roster_schedule_changes')->where('status', 'CANCELLED')->pluck('id')->all()
            : [];
        $keep = [];
        $rows = DB::table('shifts')->orderBy('employee_id')->orderBy('effective_from')->orderBy('id')->get(['id', 'employee_id', 'effective_from', 'change_id']);
        foreach ($rows as $row) {
            $live = $row->change_id === null || ! in_array($row->change_id, $cancelled, true);
            if ($live && ($row->effective_from === null || (string) $row->effective_from <= $today)) {
                $keep[$row->employee_id] = $row->id;
            }
        }
        $drop = $rows->pluck('id')->diff(array_values($keep))->values()->all();
        foreach (array_chunk($drop, 500) as $chunk) {
            DB::table('shifts')->whereIn('id', $chunk)->delete();
        }
    }
};
