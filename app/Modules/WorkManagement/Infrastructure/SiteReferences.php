<?php

declare(strict_types=1);

namespace App\Modules\WorkManagement\Infrastructure;

use App\Models\Project;
use App\Platform\Retention\SiteReferenceSource;

/** Project history retains its work-site reference after assignment ends. */
final class SiteReferences implements SiteReferenceSource
{
    public function refusal(string $siteId): ?string
    {
        $count = Project::query()->where('site_id', $siteId)->count();

        return $count === 0 ? null : sprintf('This work site belongs to %d project%s and must remain in project history.', $count, $count === 1 ? '' : 's');
    }
}
