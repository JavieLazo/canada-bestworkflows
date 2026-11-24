<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Application\Commands;

use App\Domain\Enums\AuditEvent;
use App\Models\PayrollBenefitCeiling;
use App\Modules\Payroll\Application\BenefitCeilings;
use App\Modules\Payroll\Application\PayrollData;
use App\Modules\Payroll\Application\PayrollSettingsAccess;
use App\Platform\Audit\RequiredAudit;
use App\Support\Access;
use App\Support\LocalClock;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Sets the yearly tax-free ceiling on exempt benefits. THERE IS ONE CEILING,
 * NOT A LIST OF DATED VERSIONS: the figure replaces the one in use, takes
 * effect at once for every draft payroll (which reads it on its next preview
 * and asks for a fresh review), and no date, reason or approval is asked. A
 * finalized payroll keeps the ceiling its evidence captured, so nothing
 * already paid moves. What the ceiling was is kept in the audit trail alone:
 * the row the change is written on stays, any other row of the table is
 * removed, and the kept row covers every date they covered.
 *
 * Whoever manages payroll (all-data PAYROLL, PayrollSettingsAccess);
 * RequiredAudit on the same transaction, after payroll's membership lock.
 */
final class ReplaceBenefitCeiling
{
    public function handle(Access $access, mixed $ceiling): string
    {
        if (! PayrollSettingsAccess::mayManage($access)) {
            throw new RuntimeException('Your role cannot manage payroll settings.');
        }
        if (! BenefitCeilings::ready()) {
            throw new RuntimeException('The ceiling can be set once this installation\'s upgrade finishes.');
        }
        if (is_string($ceiling)) {
            $ceiling = trim(str_replace(',', '', $ceiling));
        }
        if (! is_numeric($ceiling) || (float) $ceiling < 0.0 || (float) $ceiling > 99_999_999.99) {
            throw new RuntimeException('Enter the ceiling as an amount at or above zero.');
        }
        $pesos = Money::round($ceiling);
        $today = LocalClock::todayKey();

        return DB::transaction(static function () use ($access, $pesos, $today): string {
            PayrollData::lockMembership();
            $rows = PayrollBenefitCeiling::query()->lockForUpdate()->orderBy('effective_from')->orderBy('id')->get();
            // The row in use today, else the earliest: the one the change is written on.
            $kept = $rows->last(static fn (PayrollBenefitCeiling $row): bool => $row->effective_from->format('Y-m-d') <= $today) ?? $rows->first();
            $before = $kept?->ceiling;

            if ($kept === null) {
                $kept = new PayrollBenefitCeiling(['effective_from' => $today, 'reason' => 'Set in Payroll Settings']);
            } else {
                // One row covers every date the list covered.
                $kept->effective_from = $rows->first()->effective_from;
                PayrollBenefitCeiling::query()->whereKeyNot($kept->id)->delete();
            }
            $kept->ceiling = $pesos;
            $kept->created_by = $access->userId === null ? null : (int) $access->userId;
            $kept->created_by_name = mb_substr($access->fullName, 0, 200);
            if (! $kept->save()) {
                throw new RuntimeException('The ceiling could not be saved.');
            }
            RequiredAudit::record($access, AuditEvent::BENEFIT_CEILING_SAVED, 'payroll_benefit_ceilings', $kept->id,
                'Tax-free ceiling set to '.number_format($pesos, 2).($before === null ? '' : ' (was '.number_format((float) $before, 2).')'));

            return 'Tax-exempt ceiling set to '.number_format($pesos, 2).'.';
        });
    }
}
