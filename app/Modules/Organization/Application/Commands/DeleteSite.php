<?php

declare(strict_types=1);

namespace App\Modules\Organization\Application\Commands;

use App\Domain\Enums\AuditEvent;
use App\Domain\Enums\ModuleCode;
use App\Models\WorkSite;
use App\Modules\Organization\Application\SiteRetention;
use App\Support\Access;
use App\Support\AuditService;

/** Reference deletion belongs to its owning module, under the catalog's retention policy. */
final class DeleteSite
{
    public function handle(Access $access, string $id): string
    {
        if (! $access->mayReadAllData(ModuleCode::RECORDS)) {
            throw new \RuntimeException('Your role cannot manage organization-wide data.');
        }
        $site = WorkSite::query()->find($id);
        if ($site === null) {
            throw new \RuntimeException('That work site no longer exists.');
        }
        $reason = app(SiteRetention::class)->refusal($id);
        if ($reason !== null) {
            throw new \RuntimeException($reason);
        }
        $name = $site->name;
        $site->delete();
        AuditService::record($access, AuditEvent::SITE_DELETED, 'work_sites', $id, "{$name} deleted");

        return $name;
    }
}
