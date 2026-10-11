<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Application\Commands;

use App\Domain\Enums\AuditEvent;
use App\Domain\Enums\ModuleCode;
use App\Models\PayrollIncentiveType;
use App\Models\PayrollRunMemberIncentive;
use App\Support\Access;
use App\Support\AuditService;
use RuntimeException;

/**
 * Deletes one incentive or bonus type — refused while any payroll line
 * names it, draft or finalized, since removing it would take money out of
 * a draft; the `type_id` RESTRICT (2026_09_26_0008) is the backstop for a
 * delete that races a save. Posted payslips are unaffected either way:
 * they copied the type. RECORDS or PAYROLL all-data (D10); best-effort
 * audit like every catalog.
 */
final class DeleteIncentiveType
{
    /** The deleted type's name. Every refusal throws a RuntimeException carrying the sentence. */
    public function handle(Access $actor, string $id): string
    {
        if (! $actor->mayReadAllData(ModuleCode::RECORDS) && ! $actor->mayReadAllData(ModuleCode::PAYROLL)) {
            throw new RuntimeException('Your role cannot manage organization-wide data.');
        }

        $row = PayrollIncentiveType::query()->find($id);
        if ($row === null) {
            throw new RuntimeException('That incentive type no longer exists.');
        }
        if (PayrollRunMemberIncentive::query()->where('type_id', $id)->exists()) {
            throw new RuntimeException("{$row->name} is on a payroll, so it cannot be deleted. Rename it instead.");
        }

        $code = $row->code;
        $name = $row->name;
        $row->delete();
        AuditService::record($actor, AuditEvent::INCENTIVE_TYPE_DELETED, 'payroll_incentive_types', $id, "Incentive type {$code} \"{$name}\" deleted");

        return $name;
    }
}
