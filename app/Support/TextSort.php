<?php

declare(strict_types=1);

namespace App\Support;

/**
 * The one text comparator behind every sorted list — the port of
 * `compareText` in `src/hooks/useSort.ts`, which every table in the reference
 * shared through the useSort hook.
 *
 * It was ported eight times, once per module, and two of the copies drifted:
 * My Tasks broke a case-only tie between "Dela Cruz" and "dela cruz" while the
 * other seven returned 0 and left the pair in visit order; and Employee
 * Records reached for ICU `Collator('en')` whenever ext-intl happened to be
 * loaded, so the same employee list sorted "Álvarez" before "Bautista" on one
 * host and after "zamora" on another. Nothing pinned any ordering, so the
 * suite stayed green either way. The canonical order below is deterministic
 * and host-independent; `tests/Unit/TextSortTest.php` pins it and asserts that
 * every module wrapper still forwards here.
 *
 * The contract, in order:
 *
 *  1. Both sides are trimmed. Text that is blank after trimming sorts LAST on
 *     an ascending pass (the screens flip the whole array for descending, as
 *     useSort.ts:18-24 did, so blanks land first on desc). Two blanks are
 *     equal, which keeps a stable sort's input order.
 *  2. Case-insensitive comparison of the bytes (`strcasecmp`), so "abad" comes
 *     before "Bautista".
 *  3. On a fold-tie only, byte order (`strcmp`) breaks it — uppercase first,
 *     because its bytes are smaller. Without this, a case-only pair had no
 *     defined order.
 *
 * Bytes above 0x7F sort after every ASCII letter. That is a deliberate choice
 * over a locale-aware collation: a sort that changes with a PHP extension on
 * a shared host the company does not control is worse than one that puts
 * "Álvarez" after "zamora" everywhere. Records that need a human-friendly
 * order for accented names should store a sort key, not depend on ext-intl.
 *
 * The eight module `compareText` methods remain as one-line wrappers so that
 * no PHP call site had to change (no Blade view calls compareText).
 */
final class TextSort
{
    /**
     * @return int negative when $a sorts first, positive when $b does, 0 only
     *             for identical trimmed text or two blanks
     */
    public static function compare(?string $a, ?string $b): int
    {
        $left = trim((string) $a);
        $right = trim((string) $b);

        if ($left === '' && $right === '') {
            return 0;
        }
        if ($left === '') {
            return 1;
        }
        if ($right === '') {
            return -1;
        }

        $folded = strcasecmp($left, $right);

        return $folded !== 0 ? $folded : strcmp($left, $right);
    }
}
