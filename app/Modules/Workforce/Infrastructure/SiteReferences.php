<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Infrastructure;

use App\Models\EmployeeProfile;
use App\Platform\Retention\SiteReferenceSource;

/** Employee assignments must be moved before their site can be removed. */
final class SiteReferences implements SiteReferenceSource
{
    public function refusal(string $siteId): ?string
    {
        $count = EmployeeProfile::query()->where('work_site_id', $siteId)->count();

        return $count === 0 ? null : sprintf('Reassign %d employee%s before deleting this work site.', $count, $count === 1 ? '' : 's');
    }
}
