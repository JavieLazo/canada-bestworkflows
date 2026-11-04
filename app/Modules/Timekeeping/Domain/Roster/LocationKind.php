<?php

declare(strict_types=1);

namespace App\Modules\Timekeeping\Domain\Roster;

/**
 * The two places a punch can be made from, as `roster_locations.kind` and
 * `attendance_logs.location_kind` spell them, plus the one word the roster
 * dialog uses for the Assigned Worksite: DEFAULT, which is never stored (a
 * weekday going back to it is a `roster_locations` row with NULL `kind`).
 *
 * A constants class rather than an enum, like ModuleCode and
 * PayrollRunStatus: the columns stay plain strings and the CHECKs
 * (`rloc_kind_chk`, `attlog_location_kind_chk`) carry the literal list.
 *
 * `label()` is the ONE spelling of a punch's place for the live feed, My
 * punches and the correction dialog, so "Work from home" cannot drift
 * between them. Pure.
 */
final class LocationKind
{
    public const ONSITE = 'ONSITE';

    public const WFH = 'WFH';

    /** Save-payload only: "the Assigned Worksite for this weekday" — no row, or a row with NULL kind. */
    public const DEFAULT = 'DEFAULT';

    public const WFH_LABEL = 'Work from home';

    private function __construct() {}

    /**
     * How a feed names where a punch was made: "Work from home", the site's
     * name, or an em dash when an ONSITE row names no site (a correction for
     * an employee with no worksite, or a site since deleted).
     */
    public static function label(?string $kind, ?string $siteName): string
    {
        if ($kind === self::WFH) {
            return self::WFH_LABEL;
        }

        return $siteName === null || $siteName === '' ? "\u{2014}" : $siteName;
    }
}
