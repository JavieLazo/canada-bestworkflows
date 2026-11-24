<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Application\Queries;

use App\Domain\Enums\ModuleCode;
use App\Models\Payslip;
use App\Modules\Payroll\Application\EmployeeTaxDetails;
use App\Modules\Payroll\Application\SourceDeductionFacts;
use App\Modules\Payroll\Domain\Canada\DeductionRates;
use App\Modules\Payroll\Domain\Canada\PayDeductions;
use App\Modules\Payroll\Domain\Canada\TaxDetails;
use App\Support\Access;
use App\Support\Money;

/**
 * One employee's tax details as the pay page shows them: what is saved for
 * them, the basic amounts a blank claim stands for, what the year's posted
 * payslips have deducted so far against each yearly maximum, and a sample
 * pay worked out by the calculator payroll uses. All-data PAYROLL only.
 */
final class EmployeeTaxDetailRecords
{
    /** The saved details, or the starting position of an employee who has none. */
    public function details(Access $actor, string $employeeId): TaxDetails
    {
        abort_unless($actor->mayReadAllData(ModuleCode::PAYROLL), 403);

        return EmployeeTaxDetails::forEmployee($employeeId);
    }

    /**
     * The basic amounts in force on a day: what a claim left blank stands
     * for. The provincial one is null for a province with no tax figures.
     *
     * @return array{federal: float, provincial: ?float}
     */
    public function basicAmounts(Access $actor, string $province, string $day): array
    {
        abort_unless($actor->mayReadAllData(ModuleCode::PAYROLL), 403);
        $rates = DeductionRates::inForceOn($day);

        return [
            'federal' => (float) $rates->federal['basic_amount']['max'],
            'provincial' => $rates->covers($province) ? (float) $rates->province($province)['basic_amount'] : null,
        ];
    }

    /** How many pay dates the year has on the company's pay schedule. */
    public function paysInYear(Access $actor, int $year): int
    {
        abort_unless($actor->mayReadAllData(ModuleCode::PAYROLL), 403);

        return SourceDeductionFacts::periods(null, $year);
    }

    /**
     * What the year's posted payslips have deducted from an employee, with
     * the most each contribution takes in a year on the figures in force on
     * the day given.
     *
     * @return array{year: int, pays: int, gross: float, pension: float, pension_max: float, second_pension: float, second_pension_max: float, insurance: float, insurance_max: float, federal: float, provincial: float}
     */
    public function yearSoFar(Access $actor, string $employeeId, int $year, string $day): array
    {
        abort_unless($actor->mayReadAllData(ModuleCode::PAYROLL), 403);
        $rates = DeductionRates::inForceOn($day);
        $sums = ['gross' => 0.0, 'pension' => 0.0, 'second_pension' => 0.0, 'insurance' => 0.0, 'federal' => 0.0, 'provincial' => 0.0];
        $pays = 0;
        if (SourceDeductionFacts::ready()) {
            $rows = SourceDeductionFacts::inYear(Payslip::query()->where('employee_id', $employeeId), $year)
                ->get(['gross_pay', 'cpp', 'cpp2', 'ei', 'federal_tax', 'provincial_tax']);
            foreach ($rows as $row) {
                $pays++;
                $sums['gross'] += (float) $row->gross_pay;
                $sums['pension'] += (float) $row->cpp;
                $sums['second_pension'] += (float) $row->cpp2;
                $sums['insurance'] += (float) $row->ei;
                $sums['federal'] += (float) $row->federal_tax;
                $sums['provincial'] += (float) $row->provincial_tax;
            }
        }

        return [
            'year' => $year,
            'pays' => $pays,
            'gross' => Money::round($sums['gross']),
            'pension' => Money::round($sums['pension']),
            'pension_max' => (float) $rates->pension['max_contribution'],
            'second_pension' => Money::round($sums['second_pension']),
            'second_pension_max' => (float) $rates->pension['max_second_contribution'],
            'insurance' => Money::round($sums['insurance']),
            'insurance_max' => (float) $rates->insurance['max_premium'],
            'federal' => Money::round($sums['federal']),
            'provincial' => Money::round($sums['provincial']),
        ];
    }

    /**
     * What a set of details deducts from one pay at the start of a year, on
     * the figures in force on the day given; null for details the figures
     * cannot price (a province with no tax tables).
     *
     * @return array{federal: float, provincial: float, pension: float, insurance: float}|null
     */
    public function sample(Access $actor, TaxDetails $details, float $pay, int $pays, string $day): ?array
    {
        abort_unless($actor->mayReadAllData(ModuleCode::PAYROLL), 403);
        $rates = DeductionRates::inForceOn($day);
        if ($details->error($rates) !== null || $pays < 1) {
            return null;
        }
        $lines = PayDeductions::forPay($rates, $details, $pays, $pay, 0.0, ['pensionable' => 0.0, 'pension' => 0.0, 'second_pension' => 0.0, 'insurance' => 0.0]);

        return [
            'federal' => $lines['federal_tax'],
            'provincial' => $lines['provincial_tax'],
            'pension' => Money::round($lines['cpp'] + $lines['cpp2']),
            'insurance' => $lines['ei'],
        ];
    }
}
