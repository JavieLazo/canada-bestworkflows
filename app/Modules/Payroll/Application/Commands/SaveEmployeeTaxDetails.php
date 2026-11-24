<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Application\Commands;

use App\Domain\Enums\AuditEvent;
use App\Domain\Enums\ModuleCode;
use App\Models\PayrollEmployeeTaxDetail;
use App\Modules\Payroll\Application\EmployeeTaxDetails;
use App\Modules\Payroll\Application\PayrollData;
use App\Modules\Payroll\Domain\Canada\DeductionRates;
use App\Modules\Payroll\Domain\Canada\TaxDetails;
use App\Modules\Workforce\Contracts\EmployeePayContexts;
use App\Platform\Audit\RequiredAudit;
use App\Support\Access;
use App\Support\Currency;
use App\Support\EmployeeName;
use App\Support\LocalClock;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Saves one employee's tax details: the province of employment, the claim
 * amounts from their personal tax credits returns, any extra tax each pay,
 * and whether the pension plan and the insurance are deducted.
 *
 * All-data PAYROLL, for any employee including the actor's own. One row per
 * employee, changed in place and used from the next payroll calculated: a
 * finalized payroll keeps the details it captured. A blank claim amount is
 * the basic amount. A save that changes nothing writes nothing and leaves no
 * audit row; a real change is audited on the same transaction.
 */
final class SaveEmployeeTaxDetails
{
    /**
     * @param  mixed  $federalClaim  an amount, or blank / null for the basic amount
     * @param  mixed  $provincialClaim  an amount, or blank / null for the basic amount
     * @param  mixed  $additionalTax  an amount, or blank / null for none
     * @return string|null what was saved, or null when nothing changed
     */
    public function handle(Access $access, string $employeeId, string $province, mixed $federalClaim, mixed $provincialClaim, mixed $additionalTax, bool $deductPension, bool $deductInsurance): ?string
    {
        if (! $access->mayReadAllData(ModuleCode::PAYROLL)) {
            throw new RuntimeException('Your role cannot manage employee pay settings.');
        }
        if (! EmployeeTaxDetails::ready()) {
            throw new RuntimeException('Tax details can be saved once this installation\'s upgrade finishes.');
        }

        $details = new TaxDetails(
            strtoupper(trim($province)),
            self::amount($federalClaim, 'Federal claim amount'),
            self::amount($provincialClaim, 'Provincial claim amount'),
            self::amount($additionalTax, 'Extra tax each pay') ?? 0.0,
            $deductPension,
            $deductInsurance,
        );
        $error = $details->error(DeductionRates::inForceOn(LocalClock::todayKey()));
        if ($error !== null) {
            throw new RuntimeException($error);
        }

        $employee = app(EmployeePayContexts::class)->get($access, [$employeeId])[0] ?? null;
        if ($employee === null) {
            throw new RuntimeException('That employee no longer exists.');
        }
        $name = EmployeeName::display($employee);

        return DB::transaction(static function () use ($access, $employeeId, $details, $name): ?string {
            // Payroll's own lock first, so a finalize in progress and this
            // save never interleave; then the row, read plainly because it
            // may not exist yet.
            PayrollData::lockMembership();
            $row = PayrollEmployeeTaxDetail::query()->where('employee_id', $employeeId)->first();
            $before = $row === null ? TaxDetails::starting() : EmployeeTaxDetails::fromRow($row);
            if ($before->toArray() === $details->toArray()) {
                return null;
            }

            $row ??= new PayrollEmployeeTaxDetail(['employee_id' => $employeeId]);
            $row->fill([
                'province' => $details->province,
                'federal_claim' => $details->federalClaim,
                'provincial_claim' => $details->provincialClaim,
                'additional_tax' => $details->additionalTax,
                'deduct_pension' => $details->deductPension,
                'deduct_insurance' => $details->deductInsurance,
            ]);
            $row->updated_by = $access->userId === null ? null : (int) $access->userId;
            $row->updated_by_name = mb_substr($access->fullName, 0, 200);
            if (! $row->save()) {
                throw new RuntimeException('The tax details could not be saved.');
            }

            RequiredAudit::record($access, AuditEvent::TAX_DETAILS_SAVED, 'payroll_employee_tax_details', $employeeId, "{$name} · ".self::describe($details));

            return "Tax details saved for {$name}. They are used from the next payroll.";
        });
    }

    /** The details in one audit sentence. */
    private static function describe(TaxDetails $details): string
    {
        $province = TaxDetails::PROVINCES[$details->province] ?? $details->province;
        $claim = static fn (?float $amount): string => $amount === null ? 'basic amount' : Currency::format($amount);

        return implode(', ', [
            "employed in {$province}",
            'federal claim '.$claim($details->federalClaim),
            "{$province} claim ".$claim($details->provincialClaim),
            $details->additionalTax > 0.0 ? 'extra tax '.Currency::format($details->additionalTax).' each pay' : 'no extra tax',
            $details->deductPension ? 'CPP deducted' : 'CPP not deducted',
            $details->deductInsurance ? 'EI deducted' : 'EI not deducted',
        ]);
    }

    /** A typed amount: blank is null, anything unreadable or below zero is refused. */
    private static function amount(mixed $raw, string $label): ?float
    {
        if (is_string($raw)) {
            $raw = trim(Currency::strip($raw));
        }
        if ($raw === null || $raw === '') {
            return null;
        }
        if (! is_numeric($raw) || ! is_finite((float) $raw) || (float) $raw < 0.0) {
            throw new RuntimeException("{$label}: enter an amount at or above zero, or leave it blank.");
        }

        return Money::round($raw);
    }
}
