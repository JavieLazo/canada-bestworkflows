<?php

declare(strict_types=1);

namespace App\Support;

use App\Platform\ReadModels\SqlText;

/**
 * How a minted id column of bare numbers orders.
 *
 * A zero pad is what lets {@see TextSort::compare} — `strcasecmp` — put
 * `TSK-002` before `TSK-010`. ELEVEN of the thirteen coded columns hold bare
 * numbers with no pad, and plain text ordering of bare numbers reads 1, 10,
 * 11, 2, 3. So those columns sort through here, not through TextSort, and the
 * SQL twin is {@see SqlText::orderNumeric}. The two must agree — a list
 * paginated in SQL and re-sorted in PHP is one list to the person reading it.
 *
 * NOT FOR PROJECTS AND TASKS. `projects.code` and `project_tasks.code` keep
 * `PRJ-001`/`TSK-014` and their padding, so they sort through TextSort and
 * `SqlText::order`. Pointing this comparator at them would file every one of
 * them after every bare number, because a prefixed code is a non-numeric
 * leftover here.
 *
 * NO `(int)` CAST. An imported id past PHP_INT_MAX would saturate and compare
 * equal to every other saturated one; the mint's own guard
 * ({@see CodeSequence}) skips such values rather than minting past them, so
 * they can exist without ever having been minted. Comparing digit COUNT first
 * and then the digits themselves is exact at any length.
 *
 * Values that are not all digits still have to order deterministically — a
 * legacy `COP-00042` the conversion could not touch, a hand-entered `N/A`.
 * They sort AFTER every number, among themselves by TextSort, so the numbered
 * rows a user is looking for stay at the top of an ascending page.
 */
final class CodeSort
{
    /**
     * @return int negative when $a sorts first, positive when $b does, 0 only
     *             for two values that are equal as numbers or as text
     */
    public static function compare(?string $a, ?string $b): int
    {
        $left = trim((string) $a);
        $right = trim((string) $b);

        // Blanks last on an ascending pass, exactly as TextSort does it, so a
        // screen that flips the array for descending keeps behaving.
        if ($left === '' && $right === '') {
            return 0;
        }
        if ($left === '') {
            return 1;
        }
        if ($right === '') {
            return -1;
        }

        $leftDigits = self::digits($left);
        $rightDigits = self::digits($right);

        if ($leftDigits === null || $rightDigits === null) {
            // One of them is not a number: numbers first, leftovers among
            // themselves by text.
            if ($leftDigits !== $rightDigits) {
                return $leftDigits === null ? 1 : -1;
            }

            return TextSort::compare($left, $right);
        }

        // Digit count, then the digits: '9' before '10' before '11', with no
        // integer cast anywhere.
        return [strlen($leftDigits), $leftDigits] <=> [strlen($rightDigits), $rightDigits];
    }

    /** The significant digits of an all-digit value, or null when it is not one. */
    private static function digits(string $value): ?string
    {
        if (preg_match('/^\d+$/', $value) !== 1) {
            return null;
        }

        $significant = ltrim($value, '0');

        // '0' and '000' are the same number, and ltrim leaves nothing behind.
        return $significant === '' ? '0' : $significant;
    }
}
