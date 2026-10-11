<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * DATA COPY ONLY — links each existing employee record to the position its
 * `employee_profiles.position` text names, by filling the empty
 * `position_id` (2026_10_07_0013).
 *
 * A record is linked only when its text is EXACTLY the name of exactly one
 * position: compared in PHP, byte for byte, so a text that differs in case,
 * accents or spacing ("driver", "Driver ") stays unlinked even though the
 * case- and accent-insensitive collation would call it equal. Any other text
 * — off the list ("Messenger"), or blank — stays unlinked, and the record
 * shows its text with the note that it is not on the Position list until HR
 * picks one. This runs while the global `positions_name_unique` still stands
 * (2026_10_07_0015 drops it afterwards), so a title can match at most one
 * row; the exactly-one rule keeps a later re-run just as safe.
 *
 * IT NEVER WRITES `position` OR `updated_at`. The text stays exactly what HR
 * entered; the update goes through the query builder, which sets only
 * `position_id`, and only on rows where it is still NULL. One `Log::error`
 * line names the texts it left unlinked and how many records hold each (the
 * host logs at error level, so that is the level an operator sees).
 *
 * RETRY-SAFE. It reads the whole still-unlinked set first, then updates by
 * primary key in chunks, each guarded by `position_id IS NULL`, so a run
 * killed partway resumes with the records still unlinked and an overlapping
 * run writes nothing twice. It stands down while the column or a table is
 * missing.
 *
 * down() IS A NO-OP: it cannot tell the links it wrote from the ones HR chose
 * since. Rolling back 2026_10_07_0013 drops the column and every link with
 * it; the `position` text is untouched either way.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('positions') || ! Schema::hasColumn('employee_profiles', 'position_id')) {
            return;
        }

        $employees = DB::table('employee_profiles')
            ->whereNull('position_id')
            ->whereNotNull('position')
            ->orderBy('id')
            ->get(['id', 'position']);

        /** @var array<string, list<string>> $idsByName */
        $idsByName = [];
        foreach (DB::table('positions')->get(['id', 'name']) as $position) {
            $idsByName[(string) $position->name][] = (string) $position->id;
        }

        /** @var array<string, list<string>> $employeesByPosition */
        $employeesByPosition = [];
        /** @var array<string, int> $unmatched */
        $unmatched = [];
        foreach ($employees as $employee) {
            $text = (string) $employee->position;
            if (trim($text) === '') {
                continue;
            }

            $matches = $idsByName[$text] ?? [];
            if (count($matches) === 1) {
                $employeesByPosition[$matches[0]][] = (string) $employee->id;
            } else {
                $unmatched[$text] = ($unmatched[$text] ?? 0) + 1;
            }
        }

        foreach ($employeesByPosition as $positionId => $employeeIds) {
            foreach (array_chunk($employeeIds, 200) as $chunk) {
                DB::table('employee_profiles')
                    ->whereIn('id', $chunk)
                    ->whereNull('position_id')
                    ->update(['position_id' => $positionId]);
            }
        }

        if ($unmatched !== []) {
            ksort($unmatched, SORT_STRING);
            $listed = [];
            foreach ($unmatched as $text => $count) {
                $listed[] = '"'.$text.'" ('.$count.')';
            }

            Log::error(sprintf(
                '2026_10_07_0014: %d employee record(s) keep their position text unlinked, because it is not exactly the name of one position on the Position list: %s.',
                array_sum($unmatched),
                implode(', ', $listed),
            ));
        }
    }

    public function down(): void
    {
        // Nothing to undo here; see the class docblock.
    }
};
