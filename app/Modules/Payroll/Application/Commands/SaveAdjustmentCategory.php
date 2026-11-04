<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Application\Commands;

use App\Domain\Enums\AuditEvent;
use App\Models\PayrollAdjustmentCategory;
use App\Modules\Payroll\Application\Adjustments;
use App\Modules\Payroll\Application\PayrollSettingsAccess;
use App\Modules\Payroll\Domain\Adjustments\AdjustmentRule;
use App\Platform\Audit\RequiredAudit;
use App\Support\Access;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Adds or renames an adjustment category. Its kind and tax are fixed once
 * a line exists under it: a posted line copied them, a pending one is
 * priced by them. Whoever manages the Payroll Settings; RequiredAudit.
 */
final class SaveAdjustmentCategory
{
    public const int NAME_MAX = 120;

    /** @return string the category's id */
    public function handle(Access $access, ?string $id, string $name, string $kind, ?string $tax): string
    {
        if (! PayrollSettingsAccess::mayManage($access)) {
            throw new RuntimeException('Your role cannot manage organization-wide data.');
        }
        if (! Adjustments::ready()) {
            throw new RuntimeException('Adjustment categories can be set up once this installation\'s upgrade finishes.');
        }
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > self::NAME_MAX) {
            throw new RuntimeException('Name the category, in 1 to '.self::NAME_MAX.' characters.');
        }
        $tax = $kind === AdjustmentRule::KIND_DEDUCTION ? null : $tax;
        $error = AdjustmentRule::categoryError($kind, $tax);
        if ($error !== null) {
            throw new RuntimeException($error);
        }

        return DB::transaction(static function () use ($access, $id, $name, $kind, $tax): string {
            $row = $id === null ? null : PayrollAdjustmentCategory::query()->whereKey($id)->lockForUpdate()->first();
            if ($id !== null && $row === null) {
                throw new RuntimeException('That category no longer exists.');
            }
            if (PayrollAdjustmentCategory::query()->where('name', $name)->when($row !== null, static fn ($q) => $q->whereKeyNot($row->id))->exists()) {
                throw new RuntimeException('A category with that name already exists.');
            }
            if ($row !== null && ($row->kind !== $kind || $row->tax !== $tax) && $row->adjustments()->exists()) {
                throw new RuntimeException('Lines already exist under this category, so its kind and tax stay. Add another category instead.');
            }
            $row ??= new PayrollAdjustmentCategory;
            $row->fill(['name' => $name, 'kind' => $kind, 'tax' => $tax]);
            if (! $row->exists) {
                $row->created_by = $access->userId === null ? null : (int) $access->userId;
                $row->created_by_name = mb_substr($access->fullName, 0, 200);
            }
            $row->save();
            RequiredAudit::record($access, AuditEvent::ADJUSTMENT_CATEGORY_SAVED, 'payroll_adjustment_categories', $row->id,
                "Adjustment category \"{$name}\" ".($id === null ? 'added' : 'changed').': '.($kind === AdjustmentRule::KIND_DEDUCTION ? 'a deduction after tax' : ($tax === AdjustmentRule::TAX_TAXABLE ? 'a taxable earning' : 'an earning not taxed')));

            return $row->id;
        });
    }
}
