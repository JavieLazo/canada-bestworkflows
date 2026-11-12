<?php

declare(strict_types=1);

namespace App\Livewire\Portal\Support;

use App\Support\HrisFormat;

/**
 * What the printed Application for Leave says about each type of leave: the
 * order section 6.A lists them in and the legal basis beside each name.
 *
 * The types themselves are HR's, from Leave Settings, so a type is matched
 * by what its name contains: "Forced Leave" and "Mandatory/Forced Leave" are
 * one line of the form. A name the form does not list keeps no citation and
 * follows the listed ones, in the order it came. Display only: nothing here
 * decides what may be filed.
 */
final class LeaveFormText
{
    /** @var list<array{0: list<string>, 1: string}> in the form's order: what the name contains, and its basis */
    private const array TYPES = [
        [['VACATION'], 'Sec. 51, Rule XVI, Omnibus Rules Implementing E.O. No. 292'],
        [['MANDATORY', 'FORCED'], 'Sec. 25, Rule XVI, Omnibus Rules Implementing E.O. No. 292'],
        [['SICK'], 'Sec. 43, Rule XVI, Omnibus Rules Implementing E.O. No. 292'],
        [['MATERNITY'], 'R.A. No. 11210 / IRR issued by CSC, DOLE and SSS'],
        [['PATERNITY'], 'R.A. No. 8187 / CSC MC No. 71, s. 1998, as amended'],
        [['SPECIAL_PRIVILEGE'], 'Sec. 21, Rule XVI, Omnibus Rules Implementing E.O. No. 292'],
        [['SOLO_PARENT'], 'RA No. 8972 / CSC MC No. 8, s. 2004'],
        [['STUDY'], 'Sec. 68, Rule XVI, Omnibus Rules Implementing E.O. No. 292'],
        [['VAWC', 'VIOLENCE'], 'RA No. 9262 / CSC MC No. 15, s. 2005'],
        [['REHABILITATION'], 'Sec. 55, Rule XVI, Omnibus Rules Implementing E.O. No. 292'],
        [['SPECIAL_LEAVE_BENEFIT'], 'RA No. 9710 / CSC MC No. 25, s. 2010'],
        [['CALAMITY', 'SPECIAL_EMERGENCY'], 'CSC MC No. 2, s. 2012, as amended'],
        [['ADOPTION'], 'R.A. No. 8552'],
    ];

    private function __construct() {}

    /** The legal basis the form prints beside this leave type's name; null for a type it does not list. */
    public static function citation(string $name): ?string
    {
        $line = self::line($name);

        return $line === null ? null : self::TYPES[$line][1];
    }

    /**
     * The rows in the form's order, by the leave type's name in `$field`.
     *
     * @template TRow of array<string, mixed>
     *
     * @param  list<TRow>  $rows
     * @return list<TRow>
     */
    public static function arrange(array $rows, string $field): array
    {
        usort($rows, static fn (array $a, array $b): int => (self::line((string) $a[$field]) ?? PHP_INT_MAX) <=> (self::line((string) $b[$field]) ?? PHP_INT_MAX));

        return $rows;
    }

    private static function line(string $name): ?int
    {
        $token = HrisFormat::normalizeRequestType($name);
        foreach (self::TYPES as $line => [$needles]) {
            foreach ($needles as $needle) {
                if (str_contains($token, $needle)) {
                    return $line;
                }
            }
        }

        return null;
    }
}
