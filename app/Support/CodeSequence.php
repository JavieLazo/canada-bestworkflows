<?php

declare(strict_types=1);

namespace App\Support;

use App\Platform\ReadModels\SqlText;

/**
 * The one id mint, shared by every coded table: decimal numbers, 1 up — bare
 * on eleven columns, `PRJ-001`/`TSK-014` on the two Project Management ones.
 *
 * ELEVEN of the thirteen coded columns hold bare numbers, because that is what
 * people say out loud and write on paper; the migration
 * `2026_09_19_0002_convert_codes_to_plain_numbers_tables` converts stored
 * `PREFIX-NNN` codes (`COP-00042`, `PR-001`) to that shape.
 *
 * PROJECT MANAGEMENT IS THE DELIBERATE EXCEPTION: `projects.code` and
 * `project_tasks.code` keep `PRJ-001` and `TSK-014`, zero-padded to three,
 * and the migration's allowlist leaves both tables alone. They mint through
 * {@see self::nextPrefixed()}; everything else mints through {@see self::next()}.
 * The two share one scan, so there is ONE mint here, not two.
 *
 * THE PREFIX ARGUMENT IS KEPT ON PURPOSE. It is the LEGACY prefix of the
 * column being minted into, and it exists so a half-converted table still
 * mints above everything in it: the conversion runs from a temporary cron on a
 * host with no shell, and a row inserted between two of its statements must
 * not be handed a number an unconverted `COP-00042` already holds. Pass the
 * column's legacy prefix; drop the argument only once no database anywhere
 * can still hold a prefixed code, which is not a thing this codebase can know.
 *
 * NO PADDING on a bare number. A padded number is a string that happens to
 * sort correctly; bare numbers do not sort as text — '10' precedes '2' — so
 * ordering is explicit for those columns: {@see CodeSort::compare} in PHP and
 * {@see SqlText::orderNumeric} in SQL. On `next()` the `$width` argument is
 * kept only because one caller's bytes are hashed; see that method.
 *
 * `nextPrefixed()` DOES pad, and that is the whole reason the project and task
 * lists need neither of those comparators: `PRJ-002` before `PRJ-010` is plain
 * `strcasecmp`, so those two columns sort through `TextSort`/`SqlText::order`.
 * Keep the padding and that stays true.
 *
 * This only computes the next number. Reading the codes under lock and
 * inserting in the same transaction is the caller's job (codes-and-locks
 * guide): call it with the WHOLE table's codes, never a parent's subset —
 * sequences are global per table. Deletes are hard, so deleting the highest
 * row hands its number out again; the `unique` index on every code column is
 * the backstop.
 */
final class CodeSequence
{
    /**
     * `Number.MAX_SAFE_INTEGER`. The reference minted in float64, where a
     * suffix past this value plus one is itself, so the leave route handler
     * dropped such codes before minting (api/leave-requests/route.ts:46-57)
     * to keep one absurd imported LR code from wedging every later filing.
     * PHP wedges differently — `(int)` saturates at PHP_INT_MAX and `+ 1`
     * overflows to a float — with the same outcome, so the guard applies to
     * every column here, not only the leave one.
     */
    private const float MAX_SAFE_INTEGER = 9007199254740991.0;

    private function __construct() {}

    /**
     * One past the highest number in use, as a decimal string.
     *
     * Codes are trimmed and matched case-insensitively. BOTH shapes count: a
     * bare `42` and a legacy `COP-42` are the same number forty-two, so a
     * column the conversion has not reached yet still yields the right answer.
     * Anything else — `PRJ-x`, `TSK-001-2`, an empty string, null — is ignored.
     *
     * `$width` IS IGNORED, and it cannot be removed. It is a zero-pad width, and
     * bare numbers have none — but `App\Modules\Payroll\Application\PayrollData`
     * forwards it, and THAT FILE'S RAW BYTES are part of
     * `CalculatorVersion::current()`. Editing it to drop one dead argument
     * would change every future snapshot's `calculator_version` and make every
     * payroll evidence record captured before the deploy permanently
     * unreplayable, for a parameter nobody reads. So it stays, accepted and
     * discarded, until something else forces that file to change anyway.
     *
     * @param  iterable<array-key, string|null>  $codes
     */
    public static function next(string $legacyPrefix, iterable $codes, int $width = 3): string
    {
        return (string) (self::highest($legacyPrefix, $codes) + 1);
    }

    /**
     * One past the highest number in use, spelled the way Project Management
     * spells it: the prefix, a dash, and the number zero-padded to `$width` —
     * `PRJ-003`, `TSK-015`.
     *
     * `projects.code` and `project_tasks.code` are the only two columns that
     * keep a prefix (see the class docblock), so this is the only mint that
     * pads. THE PADDING IS LOAD-BEARING: it is what lets those two lists keep
     * sorting as plain text, and `PRJ-10` files between `PRJ-1` and `PRJ-2`
     * the moment it goes.
     *
     * Reads exactly the shapes `next()` reads, so a bare `3` that somehow
     * reached the column still counts as three and the next code clears it.
     *
     * @param  iterable<array-key, string|null>  $codes
     */
    public static function nextPrefixed(string $prefix, iterable $codes, int $width = 3): string
    {
        return $prefix.'-'.str_pad((string) (self::highest($prefix, $codes) + 1), $width, '0', STR_PAD_LEFT);
    }

    /**
     * The highest number the column's codes resolve to, or 0 when none does.
     *
     * The single scan both mints share — the "ONE mint" the codes-and-locks
     * guide insists on is this method, not the two spellings above it.
     *
     * @param  iterable<array-key, string|null>  $codes
     */
    private static function highest(string $prefix, iterable $codes): int
    {
        $highest = 0;
        $pattern = '/^(?:'.preg_quote($prefix, '/').'-)?(\d+)$/i';

        foreach ($codes as $code) {
            if ($code === null || preg_match($pattern, trim($code), $m) !== 1) {
                continue;
            }

            // Compared as a float on purpose: the bound is exact in float64,
            // and an `(int)` cast would already have saturated.
            if ((float) $m[1] > self::MAX_SAFE_INTEGER) {
                continue;
            }

            $highest = max($highest, (int) $m[1]);
        }

        return $highest;
    }
}
