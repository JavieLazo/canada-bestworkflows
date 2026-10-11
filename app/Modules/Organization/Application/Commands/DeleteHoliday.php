<?php

declare(strict_types=1);

namespace App\Modules\Organization\Application\Commands;

use App\Domain\Enums\AuditEvent;
use App\Domain\Enums\ModuleCode;
use App\Models\Holiday;
use App\Support\Access;
use App\Support\AuditService;

/** Reference deletion belongs to its owning module, under the catalog's retention policy. */
final class DeleteHoliday
{
    public function handle(Access $access, string $id): string
    {
        if (! $access->mayReadAllData(ModuleCode::RECORDS)) {
            throw new \RuntimeException('Your role cannot manage organization-wide data.');
        }
        $row = Holiday::query()->find($id);
        if ($row === null) {
            throw new \RuntimeException('That holiday no longer exists.');
        }
        $code = $row->code;
        $name = $row->name;
        $row->delete();
        AuditService::record($access, AuditEvent::HOLIDAY_DELETED, 'holidays', $id, "Holiday {$code} \"{$name}\" deleted");

        return $name;
    }
}
