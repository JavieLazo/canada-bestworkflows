<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Application\Commands;

use App\Domain\Enums\AuditEvent;
use App\Models\PayrollAdjustmentCategory;
use App\Modules\Payroll\Application\Adjustments;
use App\Modules\Payroll\Application\PayrollSettingsAccess;
use App\Platform\Audit\RequiredAudit;
use App\Support\Access;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Deletes an adjustment category no line was made under; a category with
 * lines stays on record. Whoever manages the Payroll Settings; RequiredAudit.
 */
final class DeleteAdjustmentCategory
{
    public function handle(Access $access, string $id): string
    {
        if (! PayrollSettingsAccess::mayManage($access)) {
            throw new RuntimeException('Your role cannot manage organization-wide data.');
        }
        if (! Adjustments::ready()) {
            throw new RuntimeException('Adjustment categories can be set up once this installation\'s upgrade finishes.');
        }

        return DB::transaction(static function () use ($access, $id): string {
            $row = PayrollAdjustmentCategory::query()->whereKey($id)->lockForUpdate()->first();
            if ($row === null) {
                throw new RuntimeException('That category no longer exists.');
            }
            $lines = $row->adjustments()->count();
            if ($lines > 0) {
                throw new RuntimeException($lines === 1 ? 'One adjustment was made under this category, so it stays on record.' : "{$lines} adjustments were made under this category, so it stays on record.");
            }
            $name = $row->name;
            if (! $row->delete()) {
                throw new RuntimeException('The category could not be deleted.');
            }
            RequiredAudit::record($access, AuditEvent::ADJUSTMENT_CATEGORY_DELETED, 'payroll_adjustment_categories', $id, "Adjustment category \"{$name}\" deleted");

            return "Category \"{$name}\" deleted.";
        });
    }
}
