<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Application;

use App\Models\PayrollBenefitCeiling;
use App\Modules\Payroll\Domain\Benefits\ExemptBenefitCeiling;
use Illuminate\Support\Facades\Schema;

/**
 * The yearly tax-free ceiling as payroll reads it: the dated version in
 * force on a day (`payroll_benefit_ceilings`), captured in the evidence
 * as `benefit_ceiling` and handed to `ExemptBenefitCeiling`. Before the
 * table exists (the window before the cron migrates 2026_11_02_0010), and
 * for evidence captured before it, the constant the engine carried.
 */
final class BenefitCeilings
{
    private function __construct() {}

    public static function ready(): bool
    {
        return once(static fn (): bool => Schema::hasTable('payroll_benefit_ceilings'));
    }

    /** The version in force on a day, or null when none has started. */
    public static function inForce(string $date): ?PayrollBenefitCeiling
    {
        if (! self::ready()) {
            return null;
        }

        return PayrollBenefitCeiling::query()->whereDate('effective_from', '<=', $date)->orderByDesc('effective_from')->orderByDesc('id')->first();
    }

    /**
     * The version's entry as the evidence carries it; null for the constant.
     *
     * @return array{version_id: string, effective_from: string, ceiling: float}|null
     */
    public static function entry(string $date): ?array
    {
        $row = self::inForce($date);

        return $row === null ? null : ['version_id' => (string) $row->id, 'effective_from' => $row->effective_from->format('Y-m-d'), 'ceiling' => $row->ceiling];
    }

    /**
     * The pesos a captured entry means.
     *
     * @param  array{version_id: string, effective_from: string, ceiling: float}|null  $entry
     */
    public static function ceilingOf(?array $entry): float
    {
        return $entry === null ? ExemptBenefitCeiling::CEILING : $entry['ceiling'];
    }
}
