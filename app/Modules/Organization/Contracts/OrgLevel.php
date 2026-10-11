<?php

declare(strict_types=1);

namespace App\Modules\Organization\Contracts;

/**
 * The two levels a position is placed in, as other modules name them. The
 * values are what `org_nodes.kind` stores; the nouns are what a screen or an
 * audit sentence calls them. Workforce reads a placement through these
 * instead of Organization's model constants, which it may not import.
 */
final class OrgLevel
{
    public const string DEPARTMENT = 'DEPARTMENT';

    public const string UNIT = 'UNIT';

    /** "Department" or "Unit"; an unknown kind reads as itself. */
    public static function noun(string $kind): string
    {
        return match ($kind) {
            self::DEPARTMENT => 'Department',
            self::UNIT => 'Unit',
            default => $kind,
        };
    }
}
