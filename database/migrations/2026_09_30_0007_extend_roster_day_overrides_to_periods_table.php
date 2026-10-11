<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * NEW COLUMNS — `roster_day_overrides` becomes the temporary-assignment
 * history (2026-09-30): a single date or a From–To period, kept after it is
 * edited or cancelled instead of being overwritten or deleted.
 *
 *   - `date` stays the first day; `end_date` is the last (NULL = that one
 *     day, which is what every existing row is).
 *   - `include_rest_days`: TRUE applies the hours on every date in the span,
 *     which is how a one-date change has always worked, so it is the default
 *     and every existing row keeps its meaning. A period that keeps the usual
 *     rest days (the owner's D6) writes FALSE.
 *   - `status` ACTIVE | CANCELLED, `cancelled_by`, `cancelled_at`: who ended
 *     it and when. `cut_after` is the last date it still applies once it was
 *     cut short or cancelled — before `date` for one cancelled before it
 *     began — so the part already worked keeps its hours.
 *   - `revision_of`: the assignment an edit replaced from a later date.
 *   - `created_by`: the account that saved it. Account columns are SET NULL
 *     like the other ported ones, and so never named in a CHECK (MariaDB
 *     error 1901).
 *
 * `rdate_employee_date_unq` goes, since a date can now carry a cancelled
 * assignment and its replacement; `rdate_employee_date_idx` takes over the
 * employee foreign key's index first (MariaDB error 1553).
 *
 * RETRY-SAFE: each column, index, foreign key, CHECK and the unique drop has
 * its own guard; every column is appended, so MariaDB 11.4 adds them
 * instantly and existing rows take the defaults. ROLLBACK LOSES periods,
 * cancelled and superseded rows: per employee and date only the latest
 * active one-day row is kept, so the unique can return.
 */
return new class extends Migration
{
    public function up(): void
    {
        $columns = [
            'end_date' => static fn (Blueprint $t) => $t->date('end_date')->nullable(),
            'include_rest_days' => static fn (Blueprint $t) => $t->boolean('include_rest_days')->default(true),
            'status' => static fn (Blueprint $t) => $t->string('status', 12)->default('ACTIVE'),
            'cut_after' => static fn (Blueprint $t) => $t->date('cut_after')->nullable(),
            'revision_of' => static fn (Blueprint $t) => $t->char('revision_of', 26)->nullable(),
            'created_by' => static fn (Blueprint $t) => $t->unsignedBigInteger('created_by')->nullable(),
            'cancelled_by' => static fn (Blueprint $t) => $t->unsignedBigInteger('cancelled_by')->nullable(),
            'cancelled_at' => static fn (Blueprint $t) => $t->dateTime('cancelled_at', 3)->nullable(),
        ];
        foreach ($columns as $column => $definition) {
            if (! Schema::hasColumn('roster_day_overrides', $column)) {
                Schema::table('roster_day_overrides', $definition);
            }
        }

        $indexes = [
            'rdate_employee_date_idx' => ['employee_id', 'date'],
            'rdate_revision_idx' => ['revision_of'],
            'rdate_created_by_idx' => ['created_by'],
            'rdate_cancelled_by_idx' => ['cancelled_by'],
        ];
        foreach ($indexes as $name => $indexColumns) {
            if (! Schema::hasIndex('roster_day_overrides', $name)) {
                Schema::table('roster_day_overrides', static fn (Blueprint $t) => $t->index($indexColumns, $name));
            }
        }

        if (! Schema::hasForeignKey('roster_day_overrides', ['revision_of'])) {
            Schema::table('roster_day_overrides', static fn (Blueprint $t) => $t->foreign('revision_of')->references('id')->on('roster_day_overrides')->nullOnDelete());
        }
        if (! Schema::hasForeignKey('roster_day_overrides', ['created_by'])) {
            Schema::table('roster_day_overrides', static fn (Blueprint $t) => $t->foreign('created_by')->references('id')->on('users')->nullOnDelete());
        }
        if (! Schema::hasForeignKey('roster_day_overrides', ['cancelled_by'])) {
            Schema::table('roster_day_overrides', static fn (Blueprint $t) => $t->foreign('cancelled_by')->references('id')->on('users')->nullOnDelete());
        }
        if (Schema::hasIndex('roster_day_overrides', 'rdate_employee_date_unq')) {
            Schema::table('roster_day_overrides', static fn (Blueprint $t) => $t->dropUnique('rdate_employee_date_unq'));
        }

        if ($this->isMySql() && ! $this->hasCheck('rdate_status_chk')) {
            DB::statement("ALTER TABLE `roster_day_overrides` ADD CONSTRAINT `rdate_status_chk` CHECK (`status` IN ('ACTIVE','CANCELLED'))");
        }
        if ($this->isMySql() && ! $this->hasCheck('rdate_span_chk')) {
            DB::statement('ALTER TABLE `roster_day_overrides` ADD CONSTRAINT `rdate_span_chk` CHECK (`end_date` IS NULL OR `end_date` >= `date`)');
        }
        if ($this->isMySql() && ! $this->hasCheck('rdate_cancel_chk')) {
            DB::statement("ALTER TABLE `roster_day_overrides` ADD CONSTRAINT `rdate_cancel_chk` CHECK ((`status` = 'ACTIVE' AND `cancelled_at` IS NULL) OR (`status` = 'CANCELLED' AND `cancelled_at` IS NOT NULL AND `cut_after` IS NOT NULL))");
        }
        if ($this->isMySql() && ! $this->hasCheck('rdate_rest_days_chk')) {
            DB::statement('ALTER TABLE `roster_day_overrides` ADD CONSTRAINT `rdate_rest_days_chk` CHECK (`include_rest_days` IN (0,1))');
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('roster_day_overrides', 'status')) {
            $this->keepOnlyOneDayRows();
        }
        foreach (['rdate_rest_days_chk', 'rdate_cancel_chk', 'rdate_span_chk', 'rdate_status_chk'] as $check) {
            if ($this->isMySql() && $this->hasCheck($check)) {
                DB::statement("ALTER TABLE `roster_day_overrides` DROP CONSTRAINT `{$check}`");
            }
        }
        if (! Schema::hasIndex('roster_day_overrides', 'rdate_employee_date_unq')) {
            Schema::table('roster_day_overrides', static fn (Blueprint $t) => $t->unique(['employee_id', 'date'], 'rdate_employee_date_unq'));
        }
        foreach (['cancelled_by', 'created_by', 'revision_of'] as $column) {
            if (Schema::hasForeignKey('roster_day_overrides', [$column])) {
                Schema::table('roster_day_overrides', static fn (Blueprint $t) => $t->dropForeign([$column]));
            }
        }
        foreach (['rdate_cancelled_by_idx', 'rdate_created_by_idx', 'rdate_revision_idx', 'rdate_employee_date_idx'] as $index) {
            if (Schema::hasIndex('roster_day_overrides', $index)) {
                Schema::table('roster_day_overrides', static fn (Blueprint $t) => $t->dropIndex($index));
            }
        }
        foreach (['cancelled_at', 'cancelled_by', 'created_by', 'revision_of', 'cut_after', 'status', 'include_rest_days', 'end_date'] as $column) {
            if (Schema::hasColumn('roster_day_overrides', $column)) {
                Schema::table('roster_day_overrides', static fn (Blueprint $t) => $t->dropColumn($column));
            }
        }
    }

    /** Per employee and date, the latest active single-day row that still applies. */
    private function keepOnlyOneDayRows(): void
    {
        $keep = [];
        $rows = DB::table('roster_day_overrides')->orderBy('id')->get(['id', 'employee_id', 'date', 'end_date', 'status', 'cut_after']);
        foreach ($rows as $row) {
            $date = (string) $row->date;
            $oneDay = $row->end_date === null || (string) $row->end_date === $date;
            $applies = $row->status === 'ACTIVE' && ($row->cut_after === null || (string) $row->cut_after >= $date);
            if ($oneDay && $applies) {
                $keep[$row->employee_id.'|'.$date] = $row->id;
            }
        }
        $drop = $rows->pluck('id')->diff(array_values($keep))->values()->all();
        foreach (array_chunk($drop, 500) as $chunk) {
            DB::table('roster_day_overrides')->whereIn('id', $chunk)->delete();
        }
    }

    private function hasCheck(string $name): bool
    {
        $row = DB::selectOne("SELECT COUNT(*) AS n FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'roster_day_overrides' AND CONSTRAINT_NAME = ? AND CONSTRAINT_TYPE = 'CHECK'", [$name]);

        return (int) ($row->n ?? 0) > 0;
    }

    private function isMySql(): bool
    {
        return in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true);
    }
};
