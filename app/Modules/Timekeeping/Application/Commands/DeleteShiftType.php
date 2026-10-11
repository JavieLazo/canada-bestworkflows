<?php

declare(strict_types=1);

namespace App\Modules\Timekeeping\Application\Commands;

use App\Domain\Enums\AuditEvent;
use App\Domain\Enums\ModuleCode;
use App\Models\ShiftType;
use App\Support\Access;
use App\Support\AuditService;

/** Reference deletion belongs to the owning module and follows the retention policy. */
final class DeleteShiftType
{
    public function handle(Access $access, string $id): string
    {
        if (! $access->mayReadAllData(ModuleCode::RECORDS)) {
            throw new \RuntimeException('Your role cannot manage organization-wide data.');
        }
        $row = ShiftType::query()->find($id);
        if ($row === null) {
            throw new \RuntimeException('That shift pattern no longer exists.');
        }
        $code = $row->code;
        $name = $row->name;
        $row->delete();
        AuditService::record($access, AuditEvent::SHIFT_TYPE_DELETED, 'shift_types', $id, "Shift pattern {$code} \"{$name}\" deleted");

        return $name;
    }
}
