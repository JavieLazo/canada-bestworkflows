<?php

declare(strict_types=1);

namespace App\Modules\Timekeeping\Infrastructure;

use App\Models\AttendanceLog;
use App\Models\RosterDayOverride;
use App\Models\RosterLocation;
use App\Platform\Retention\SiteReferenceSource;

/**
 * A site's punches are attendance history, and so is every weekly place or
 * temporary assignment that named it: schedule changes are kept, not
 * overwritten, so neither reference can be removed.
 */
final class SiteReferences implements SiteReferenceSource
{
    public function refusal(string $siteId): ?string
    {
        if (AttendanceLog::query()->where('site_id', $siteId)->exists()) {
            return 'This work site has recorded punches and must remain in attendance history. Make it inactive instead.';
        }

        // A schedule change adds a row instead of editing or deleting one,
        // so a site any weekly place or temporary assignment ever named
        // stays in that history — superseded and cancelled rows included —
        // and its RESTRICT key would refuse the delete anyway.
        return RosterLocation::query()->where('site_id', $siteId)->exists() || RosterDayOverride::query()->where('site_id', $siteId)->exists()
            ? 'This work site is in employee schedule history and must remain. Make it inactive instead.'
            : null;
    }
}
