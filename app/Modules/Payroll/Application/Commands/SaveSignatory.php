<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Application\Commands;

use App\Domain\Enums\AuditEvent;
use App\Models\PayrollSignatory;
use App\Modules\Payroll\Application\PayrollSettingsAccess;
use App\Modules\Payroll\Application\Queries\SignatoryRecords;
use App\Modules\Payroll\Domain\Signatories\SignatorySlot;
use App\Support\Access;
use App\Support\AuditService;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Sets who signs one slot of the payroll documents: the name and designation
 * printed under "Prepared by", "Checked by" or "Certified correct by" on the
 * payslip and the register. Blank clears the slot, so the line prints empty.
 * A save that changes nothing writes nothing and leaves no audit row. The
 * documents print the slot as it stands when printed; the audit trail holds
 * the history.
 */
final class SaveSignatory
{
    /** @return bool whether anything changed */
    public function handle(Access $access, string $slot, string $name, string $designation): bool
    {
        if (! PayrollSettingsAccess::mayManage($access)) {
            throw new RuntimeException('Your role cannot manage organization-wide data.');
        }
        if (! SignatorySlot::isSlot($slot)) {
            throw new RuntimeException('Choose which signature this is.');
        }
        if (! SignatoryRecords::ready()) {
            throw new RuntimeException('The signatories can be set once this installation\'s upgrade finishes.');
        }
        $name = trim($name);
        $designation = trim($designation);
        if (mb_strlen($name) > 200 || mb_strlen($designation) > 200) {
            throw new RuntimeException('A name or designation must be at most 200 characters.');
        }
        $label = SignatorySlot::label($slot);

        $changed = DB::transaction(static function () use ($access, $slot, $name, $designation): bool {
            $row = PayrollSignatory::query()->where('slot', $slot)->lockForUpdate()->first();
            if ($row !== null && (string) ($row->name ?? '') === $name && (string) ($row->designation ?? '') === $designation) {
                return false;
            }
            if ($row === null && $name === '' && $designation === '') {
                return false;
            }
            $row ??= new PayrollSignatory;
            $row->fill(['slot' => $slot, 'name' => $name === '' ? null : $name, 'designation' => $designation === '' ? null : $designation]);
            $row->updated_by = $access->userId === null ? null : (int) $access->userId;
            $row->updated_by_name = mb_substr($access->fullName, 0, 200);
            $row->save();

            return true;
        });

        if ($changed) {
            $who = $name === '' && $designation === '' ? 'cleared' : trim($name.($designation === '' ? '' : ", {$designation}"));
            AuditService::record($access, AuditEvent::SIGNATORY_SET, 'payroll_signatories', $slot, "{$label}: {$who}");
        }

        return $changed;
    }
}
