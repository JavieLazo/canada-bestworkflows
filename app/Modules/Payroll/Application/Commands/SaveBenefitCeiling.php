<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Application\Commands;

use App\Domain\Enums\AuditEvent;
use App\Domain\Payroll\PayrollPeriod;
use App\Models\PayrollBenefitCeiling;
use App\Modules\Payroll\Application\BenefitCeilings;
use App\Modules\Payroll\Application\PayrollPeriodText;
use App\Modules\Payroll\Application\PayrollSettingsAccess;
use App\Platform\Audit\RequiredAudit;
use App\Support\Access;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Adds a dated version of the yearly tax-free ceiling on exempt benefits
 * (`payroll_benefit_ceilings`): whoever manages payroll (all-data PAYROLL,
 * PayrollSettingsAccess), a date after every version's, a figure at or
 * above zero and a reason. A version never changes once saved; a draft
 * payroll on or after its date reads it on its next preview and asks for
 * a fresh review (the evidence captures the version); a finalized payroll
 * keeps what it was split at. RequiredAudit on the same transaction.
 */
final class SaveBenefitCeiling
{
    public function handle(Access $access, string $effectiveFrom, mixed $ceiling, string $reason): string
    {
        if (! PayrollSettingsAccess::mayManage($access)) {
            throw new RuntimeException('Your role cannot manage payroll settings.');
        }
        if (! BenefitCeilings::ready()) {
            throw new RuntimeException('The ceiling can be set once this installation\'s upgrade finishes.');
        }
        if (! PayrollPeriod::isPayrollDate($effectiveFrom)) {
            throw new RuntimeException('Enter the date the ceiling takes effect.');
        }
        if (is_string($ceiling)) {
            $ceiling = trim(str_replace(',', '', $ceiling));
        }
        if (! is_numeric($ceiling) || (float) $ceiling < 0.0 || (float) $ceiling > 99_999_999.99) {
            throw new RuntimeException('Enter the ceiling as an amount at or above zero.');
        }
        $pesos = Money::round($ceiling);
        $reason = trim($reason);
        if ($reason === '' || mb_strlen($reason) > 160) {
            throw new RuntimeException('Enter the reason for this version, at most 160 characters.');
        }
        $day = PayrollPeriodText::day($effectiveFrom);

        return DB::transaction(static function () use ($access, $effectiveFrom, $pesos, $reason, $day): string {
            $latest = PayrollBenefitCeiling::query()->lockForUpdate()->orderByDesc('effective_from')->orderByDesc('id')->first();
            if ($latest !== null && $latest->effective_from->format('Y-m-d') >= $effectiveFrom) {
                throw new RuntimeException('A version already takes effect on '.PayrollPeriodText::day($latest->effective_from->format('Y-m-d')).'. Choose a later date.');
            }
            $row = new PayrollBenefitCeiling(['effective_from' => $effectiveFrom, 'ceiling' => $pesos, 'reason' => $reason]);
            $row->created_by = $access->userId === null ? null : (int) $access->userId;
            $row->created_by_name = mb_substr($access->fullName, 0, 200);
            if (! $row->save()) {
                throw new RuntimeException('The version could not be saved.');
            }
            RequiredAudit::record($access, AuditEvent::BENEFIT_CEILING_SAVED, 'payroll_benefit_ceilings', $row->id,
                "Tax-free ceiling from {$day}: ".number_format($pesos, 2)." ({$reason})");

            return 'Tax-free ceiling of '.number_format($pesos, 2)." takes effect on {$day}.";
        });
    }
}
