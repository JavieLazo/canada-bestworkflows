<?php

declare(strict_types=1);

use App\Domain\Attendance\CalendarDays;
use App\Support\ManilaClock;
use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * DATA — one BASELINE `roster_schedule_changes` row per existing employee,
 * recording what is NOT known about their schedule history (the owner's D9,
 * 2026-09-30). No schedule row is copied or rewritten: existing `shifts`,
 * `roster_shifts`, `roster_locations` and date rows keep NULL
 * `effective_from` and go on applying to every date, so every DTR result
 * reads exactly as it did before this ran.
 *
 *   - `assumed_before` = the Manila day this runs. Before it, each change
 *     overwrote the last, so a date's schedule is the one the employee has
 *     today by ASSUMPTION; the DTR labels those dates "Assumed".
 *   - `may_differ_through` = the last Manila day, before that, on which the
 *     audit trail records a change that could have altered this employee's
 *     schedule: SHIFT_ASSIGNED, SHIFT_UPDATED, ROSTER_SHIFTS_SET or
 *     ROSTER_LOCATIONS_SET for the employee, or a SHIFT_TYPE_UPDATED that
 *     re-copied the pattern onto rostered rows (its sentence says "re-copied
 *     onto"; only 2cf4f14 on did that, so an older pattern edit changed
 *     nobody's shift) for a pattern their current rows are linked to — the
 *     audit names the pattern, not the employees it reached. Dates up to it
 *     read "Schedule may have differed". NULL when no such entry exists. ROSTER_DATE_SET changes one
 *     date only and is not evidence for any other. The audit is best-effort,
 *     so a missing marker is NOT proof the schedule stayed the same, and no
 *     schedule is reconstructed from the entries' text.
 *
 * RETRY-SAFE: reads the employees still without a BASELINE row first, then
 * inserts them in chunks with explicit ULIDs and millisecond timestamps; a
 * re-run adds only the missing ones. ROLLBACK LOSES the markers only (it
 * deletes BASELINE rows; no schedule row points at them).
 */
return new class extends Migration
{
    private const EMPLOYEE_EVENTS = ['SHIFT_ASSIGNED', 'SHIFT_UPDATED', 'ROSTER_SHIFTS_SET', 'ROSTER_LOCATIONS_SET'];

    public function up(): void
    {
        if (! Schema::hasTable('roster_schedule_changes')) {
            return;
        }
        $missing = DB::table('employee_profiles')
            ->whereNotExists(static fn ($q) => $q->select(DB::raw(1))->from('roster_schedule_changes')
                ->whereColumn('roster_schedule_changes.employee_id', 'employee_profiles.id')
                ->where('roster_schedule_changes.source', 'BASELINE'))
            ->orderBy('id')->pluck('id')->all();
        if ($missing === []) {
            return;
        }

        $today = ManilaClock::todayKey();
        $lastEvidence = $this->employeeEvidence();
        foreach ($this->patternEvidence() as $employeeId => $day) {
            $lastEvidence[$employeeId] = max($lastEvidence[$employeeId] ?? $day, $day);
        }
        $yesterday = CalendarDays::previous($today);
        $now = CarbonImmutable::now('UTC')->format('Y-m-d H:i:s.v');

        foreach (array_chunk($missing, 500) as $chunk) {
            DB::table('roster_schedule_changes')->insert(array_map(static fn (string $employeeId): array => [
                'id' => (string) Str::ulid(),
                'employee_id' => $employeeId,
                'effective_from' => null,
                'source' => 'BASELINE',
                'status' => 'ACTIVE',
                'assumed_before' => $today,
                'may_differ_through' => isset($lastEvidence[$employeeId]) ? min($lastEvidence[$employeeId], $yesterday) : null,
                'created_by' => null,
                'cancelled_by' => null,
                'cancelled_at' => null,
                'created_at' => $now,
                'updated_at' => null,
            ], $chunk));
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('roster_schedule_changes')) {
            DB::table('roster_schedule_changes')->where('source', 'BASELINE')->delete();
        }
    }

    /** @return array<string, string> employee id => Manila day of the latest schedule audit entry naming them */
    private function employeeEvidence(): array
    {
        $out = [];
        $rows = DB::table('audit_logs')->whereIn('action', self::EMPLOYEE_EVENTS)->whereNotNull('entity_id')
            ->groupBy('entity_id')->selectRaw('entity_id, MAX(created_at) AS last_at')->get();
        foreach ($rows as $row) {
            $out[(string) $row->entity_id] = $this->manilaDay((string) $row->last_at);
        }

        return $out;
    }

    /** @return array<string, string> employee id => Manila day of the latest re-copying edit of a pattern their rows link to */
    private function patternEvidence(): array
    {
        $edits = [];
        $rows = DB::table('audit_logs')->where('action', 'SHIFT_TYPE_UPDATED')->whereNotNull('entity_id')
            ->where('details', 'like', '%re-copied onto%')
            ->groupBy('entity_id')->selectRaw('entity_id, MAX(created_at) AS last_at')->get();
        foreach ($rows as $row) {
            $edits[(string) $row->entity_id] = $this->manilaDay((string) $row->last_at);
        }
        if ($edits === []) {
            return [];
        }

        $out = [];
        foreach (['shifts', 'roster_shifts', 'roster_day_overrides'] as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            foreach (DB::table($table)->whereIn('shift_type_id', array_keys($edits))->get(['employee_id', 'shift_type_id']) as $link) {
                $day = $edits[(string) $link->shift_type_id];
                $out[(string) $link->employee_id] = max($out[(string) $link->employee_id] ?? $day, $day);
            }
        }

        return $out;
    }

    private function manilaDay(string $utc): string
    {
        return ManilaClock::dayKey(CarbonImmutable::parse($utc, 'UTC'));
    }
};
