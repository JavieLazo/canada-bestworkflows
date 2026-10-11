<?php

declare(strict_types=1);

use App\Support\CodeSequence;
use App\Support\CodeSort;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * ELEVEN OF THE THIRTEEN MINTED IDS LOSE THEIR PREFIX: `COP-00042` becomes
 * `42`, `PR-001` becomes `1`, `HOL-007` becomes `7`. Data only — no column
 * changes type, no index is touched.
 *
 * PROJECT MANAGEMENT IS EXCLUDED, by an explicit decision: `projects.code` and
 * `project_tasks.code` keep `PRJ-001` and `TSK-014` and are not in TARGETS, so
 * this migration never reads or writes those two tables. They are the ids
 * people quote to each other in a project thread, where the prefix is the part
 * that says which of the two numbers is meant. Leaving them alone also leaves
 * their zero padding alone, which is what lets those two lists keep sorting as
 * plain text while every converted column sorts numerically. Do not "finish
 * the job" by adding them back: the mint ({@see CodeSequence})
 * spells them the old way on purpose.
 *
 * This reverses `.claude/rules/codes-and-locks.md`'s "never renumber existing
 * codes", by an explicit decision on 2026-09-19: the prefixes are system
 * noise, nobody says "see employee COP dash zero zero zero four two" out loud,
 * and every one of these columns is labelled plainly "ID" on screen. The rule
 * file was updated in the same change. The NUMBER is preserved, never
 * reassigned: 42 stays 42, gaps stay gaps, and nothing that was printed or
 * quoted before this migration points at a different row afterwards.
 *
 * AN EXPLICIT ALLOWLIST, NOT A COLUMN-NAME SWEEP. `employee_profiles` also
 * holds `bank_code`, `pay_code`, `agency_code`, `section_code` and `tax_code`
 * (migration 2026_09_01_0004:103-114) — hand-entered, never minted, nothing to
 * recompute them from — and the demo seeder writes the literal `COP` into
 * `agency_code`. Anything driven by the name `%code%`, or by grepping for the
 * token COP, would mangle them. The eleven tuples below are the whole target
 * set and they are written out one per line for exactly that reason.
 *
 * WHAT IT REFUSES TO DO. Per table, it maps every `PREFIX-<digits>` row to its
 * number and CHECKS THE WHOLE MAPPING BEFORE WRITING ANYTHING:
 *
 *   - two rows mapping to one number (`COP-00001` beside a hand-added
 *     `COP-1`), or
 *   - a number some other row in the column already holds,
 *
 * throw, and that table is left exactly as it was — each table converts inside
 * its own transaction, so a refusal cannot leave one half-numbered. A row that
 * does not match `PREFIX-<digits>` at all (an import like `LR-EXP`, a
 * hand-edited value, a foreign prefix) is LEFT VERBATIM and reported through
 * `Log::error` — the host logs at error level, so that is the only level an
 * operator would ever see. Those rows keep working: the mint reads both shapes
 * and {@see CodeSort} orders leftovers after every number.
 *
 * RETRY-SAFE, AND SAFE UNDER OVERLAPPING RUNS. The release runbook adds a cron
 * that fires `migrate --force` EVERY MINUTE (docs/DEPLOY.md:155) and Laravel
 * only records a migration once it returns, so a conversion running longer
 * than a minute has a second process inside it. Each table is therefore read
 * `lockForUpdate()` inside its own transaction: the second process blocks,
 * then reads the converted rows, matches nothing against `PREFIX-%`, and does
 * nothing. A killed run simply resumes — already-numbered rows no longer match
 * the filter.
 *
 * WHAT IT DOES NOT REWRITE, ON PURPOSE:
 *
 *   - `payroll_finalization_snapshots.payload` and the payroll evidence it
 *     hashes. Finalized evidence is immutable (AGENTS.md), so a run finalized
 *     before today keeps quoting `COP-00042` and `PR-007` forever while the
 *     live rows read `42` and `7`. Nothing reads those payloads back into a
 *     screen; they are there to prove what was computed.
 *   - `audit_logs.details`. Append-only, no update, no delete — the sentences
 *     already written keep the codes they were written with.
 *   - Notifications already sent.
 *
 * OUTSTANDING PAYROLL PREVIEWS GO STALE. `employee_code` is part of the
 * captured input payload that `PayrollInputs::fingerprint()` hashes, so a
 * preview taken before this migration no longer matches one taken after it and
 * finalization will ask for a fresh review. That is the designed behaviour for
 * changed inputs, not damage — but it is why this should run when no payroll
 * run is mid-review.
 *
 * ROLLBACK re-prefixes and re-pads, which is exact for everything this
 * migration wrote (the number and the table's width are all it takes to
 * rebuild `COP-00042`). It skips any row that is not all digits, so a code it
 * declined to convert keeps its own shape — but it does NOT know which
 * all-digit rows it wrote: a row that already held `999` before this ran comes
 * back as `COP-00999`. After this migration the mint produces bare numbers for
 * every column named here, so that is the wanted behaviour; before it, such a
 * row is a stray
 * the `Log::error` line above has already named. What rollback
 * cannot undo at all is the text written WHILE the numbers were bare: audit
 * sentences and notification titles from that window keep saying `42`.
 */
return new class extends Migration
{
    /**
     * table, column, legacy prefix, pad width — the ELEVEN minted id columns
     * this converts, and nothing else. `projects.code` and `project_tasks.code`
     * are the deliberate omissions; see the class docblock.
     *
     * @var list<array{0: string, 1: string, 2: string, 3: int}>
     */
    private const array TARGETS = [
        ['employee_profiles', 'employee_code', 'COP', 5],
        ['payroll_runs', 'code', 'PR', 3],
        ['leave_travel_requests', 'code', 'LR', 3],
        ['holidays', 'code', 'HOL', 3],
        ['shift_types', 'code', 'SFT', 3],
        ['payroll_formulas', 'code', 'FML', 3],
        ['positions', 'code', 'POS', 3],
        ['leave_types', 'code', 'LVE', 3],
        ['payroll_deductions', 'code', 'DED', 3],
        ['document_types', 'code', 'DOC', 3],
        ['employment_statuses', 'code', 'EMP', 3],
    ];

    public function up(): void
    {
        foreach (self::TARGETS as [$table, $column, $prefix, $width]) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
                continue;
            }

            DB::transaction(function () use ($table, $column, $prefix): void {
                $rows = DB::table($table)->select('id', $column)
                    ->where($column, 'like', $prefix.'-%')
                    ->lockForUpdate()->get();

                if ($rows->isEmpty()) {
                    return;
                }

                $pattern = '/^'.preg_quote($prefix, '/').'-(\d+)$/i';
                $changes = [];
                $leftovers = [];

                foreach ($rows as $row) {
                    $code = trim((string) $row->{$column});

                    if (preg_match($pattern, $code, $m) !== 1) {
                        $leftovers[] = $code;

                        continue;
                    }

                    // The significant digits: COP-00042 and COP-42 are both 42,
                    // which is exactly the pair the collision check must catch.
                    $number = ltrim($m[1], '0');
                    $changes[(string) $row->id] = $number === '' ? '0' : $number;
                }

                $this->refuseCollisions($table, $column, $prefix, $changes);

                foreach ($changes as $id => $number) {
                    DB::table($table)->where('id', $id)->update([$column => $number]);
                }

                $this->report($table, $column, $prefix, $leftovers);
            });
        }
    }

    public function down(): void
    {
        foreach (self::TARGETS as [$table, $column, $prefix, $width]) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
                continue;
            }

            DB::transaction(function () use ($table, $column, $prefix, $width): void {
                $rows = DB::table($table)->select('id', $column)->lockForUpdate()->get();

                foreach ($rows as $row) {
                    $code = trim((string) $row->{$column});

                    // Only what up() could have written. A leftover it refused
                    // to touch is still carrying its own prefix.
                    if (preg_match('/^\d+$/', $code) !== 1) {
                        continue;
                    }

                    DB::table($table)->where('id', $row->id)
                        ->update([$column => $prefix.'-'.str_pad($code, $width, '0', STR_PAD_LEFT)]);
                }
            });
        }
    }

    /**
     * Every value this migration will not convert, at `Log::error` — the only
     * level the host logs, and the operator's only window on a run they
     * cannot watch.
     *
     * TWO sources, and the second is the one a reader misses: `up()` reads
     * only `PREFIX-%` rows, so an `LR-EXP` reaches it as a leftover, but an
     * `N/A`, an `OLD-3` or a ` COP-00042` with a leading space is never read
     * at all. Those are found here with a second, driver-specific scan — the
     * one place this migration cannot stay driver-agnostic, because SQLite has
     * no REGEXP and MariaDB has no GLOB.
     *
     * The scan excludes only THIS column's prefix — the rows `up()` already
     * read — so a foreign `OLD-3` is still reported rather than waved past for
     * having a dash in it.
     *
     * @param  list<string>  $leftovers  values `up()` read and declined
     */
    private function report(string $table, string $column, string $prefix, array $leftovers): void
    {
        $sqlite = DB::connection()->getDriverName() === 'sqlite';

        // Written out rather than interpolated: whereRaw() takes a
        // literal-string, and four spelled-out expressions are also four fewer
        // ways for a column name to reach raw SQL.
        $notANumber = $column === 'employee_code'
            ? ($sqlite ? "employee_code GLOB '*[^0-9]*'" : "employee_code NOT REGEXP '^[0-9]+\$'")
            : ($sqlite ? "code GLOB '*[^0-9]*'" : "code NOT REGEXP '^[0-9]+\$'");

        $strays = DB::table($table)->whereNotNull($column)
            ->where($column, 'not like', $prefix.'-%')
            ->whereRaw($notANumber)
            ->limit(20)->pluck($column)->all();

        $all = array_merge($leftovers, array_map(static fn (mixed $v): string => (string) $v, $strays));

        if ($all === []) {
            return;
        }

        Log::error('Code conversion left '.count($all)." value(s) in {$table}.{$column} unchanged: "
            .Str::limit(implode(', ', $all), 500));
    }

    /**
     * Throws before a single row is written when the mapping is not injective,
     * or when it would land on a number the column already holds.
     *
     * @param  array<string, string>  $changes  row id => the number it would take
     */
    private function refuseCollisions(string $table, string $column, string $prefix, array $changes): void
    {
        $byNumber = [];
        foreach ($changes as $id => $number) {
            $byNumber[$number][] = $id;
        }

        $doubled = array_keys(array_filter($byNumber, static fn (array $ids): bool => count($ids) > 1));

        if ($doubled !== []) {
            throw new RuntimeException(
                "Two or more {$table}.{$column} rows would become the same id (".implode(', ', array_slice($doubled, 0, 5))
                .'). Give the duplicates distinct codes before migrating; nothing in this table was changed.'
            );
        }

        $wanted = array_values($changes);
        $taken = DB::table($table)->whereNotIn('id', array_keys($changes))
            ->whereIn($column, $wanted)->pluck($column)->all();

        if ($taken !== []) {
            throw new RuntimeException(
                "{$table}.{$column} already holds ".implode(', ', array_slice($taken, 0, 5))
                ." outside the {$prefix}- rows, so converting would collide. Resolve those rows first;"
                .' nothing in this table was changed.'
            );
        }
    }
};
