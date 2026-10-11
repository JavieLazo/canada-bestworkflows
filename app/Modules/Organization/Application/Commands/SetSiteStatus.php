<?php

declare(strict_types=1);

namespace App\Modules\Organization\Application\Commands;

use App\Domain\Enums\AuditEvent;
use App\Domain\Enums\ModuleCode;
use App\Models\WorkSite;
use App\Support\Access;
use App\Support\AuditService;

/**
 * Makes a work site inactive, or active again. No TypeScript counterpart:
 * the reference can only delete.
 *
 * Schedule and attendance history keep every site they name, so a site that
 * is no longer used is retired rather than deleted. INACTIVE means one thing
 * only: the site is no longer offered for NEW assignments
 * (`OrganizationReferences::siteAssignable`). This command writes the flag
 * and nothing else — no employee's Assigned Worksite, weekly location,
 * temporary schedule, project or punch is touched, and punches at the site
 * are still judged against its geofence.
 *
 * Gate and audit as the other site writes: all_data + RECORDS, best-effort
 * `AuditService`; a call that changes nothing writes and audits nothing.
 */
final class SetSiteStatus
{
    public function handle(Access $access, string $id, bool $active): string
    {
        if (! $access->mayReadAllData(ModuleCode::RECORDS)) {
            throw new \RuntimeException('Your role cannot manage organization-wide data.');
        }
        $site = WorkSite::query()->find($id);
        if ($site === null) {
            throw new \RuntimeException('That work site no longer exists.');
        }
        if ($site->is_active === $active) {
            return $active ? "{$site->name} is already active." : "{$site->name} is already inactive.";
        }
        $site->is_active = $active;
        $site->save();
        AuditService::record(
            $access,
            $active ? AuditEvent::SITE_REACTIVATED : AuditEvent::SITE_DEACTIVATED,
            'work_sites',
            (string) $site->getKey(),
            $active ? "{$site->name} made active" : "{$site->name} made inactive · existing assignments kept",
        );

        return $active
            ? "{$site->name} is active again."
            : "{$site->name} is now inactive. Existing assignments keep it.";
    }
}
