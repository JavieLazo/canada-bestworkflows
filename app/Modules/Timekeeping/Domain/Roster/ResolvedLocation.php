<?php

declare(strict_types=1);

namespace App\Modules\Timekeeping\Domain\Roster;

/**
 * Where one employee works on one date, after the fallback has been applied:
 * a date exception, then the weekday's `roster_locations` row, else the
 * Assigned Worksite from Employee Records. Pure facts and origin flags.
 *
 * `siteId` is null on a WFH day — and ALSO on an assigned-worksite day for
 * an employee who has no worksite at all, which is the one case a punch
 * still refuses ("has no assigned worksite"). `fromRoster` tells the two
 * origins apart for the kiosk's mismatch sentence (RecordPunch) and for
 * LocationOnDateView::$from_roster. The correction dialog names the
 * fallback site without an "Assigned worksite" tag.
 * `fromDateOverride` keeps a one-date exception's message from claiming the
 * employee follows that place every week.
 */
final readonly class ResolvedLocation
{
    public function __construct(
        public string $kind,
        public ?string $siteId,
        public bool $fromRoster,
        public bool $fromDateOverride = false,
    ) {}

    /** A date or weekday place. WFH never carries a site, whatever was stored. */
    public static function roster(string $kind, ?string $siteId, bool $fromDateOverride = false): self
    {
        return new self($kind, $kind === LocationKind::WFH ? null : $siteId, true, $fromDateOverride);
    }

    /** The fallback: the Assigned Worksite, which may itself be unset. */
    public static function assigned(?string $workSiteId): self
    {
        return new self(LocationKind::ONSITE, $workSiteId, false);
    }

    public function isHome(): bool
    {
        return $this->kind === LocationKind::WFH;
    }
}
