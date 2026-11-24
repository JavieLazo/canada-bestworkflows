<?php

declare(strict_types=1);

namespace App\Livewire\Records;

use App\Modules\Payroll\Application\PaySchedules;
use App\Modules\Payroll\Domain\Canada\DeductionRates;
use App\Modules\Payroll\Domain\Canada\SourceDeductions;
use App\Modules\Payroll\Domain\Canada\TaxDetails;
use App\Modules\Payroll\Domain\Canada\TaxInputs;
use App\Support\Currency;
use App\Support\LocalClock;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Contribution & Tax Tables — the Canada Pension Plan, Employment Insurance
 * and income tax figures payroll deducts by, under Records → Payroll
 * Settings.
 *
 * READ-ONLY, BY DECISION: the figures are set by law and published by the
 * Canada Revenue Agency, so nobody edits them here and the page has no
 * "not set" state. They ship with the system (`DeductionRates`), a set per
 * date they take effect, and a payroll reads the set in force on its pay
 * date. The page shows one set at a time — the one in use, or one that
 * starts later — and what it deducts from a sample pay, worked out by the
 * same calculator payroll uses.
 */
#[Layout('layouts.app')]
#[Title('Contribution & Tax Tables')]
class ContributionTablesPage extends Component
{
    public const TAB_CONTRIBUTIONS = 'contributions';

    public const TAB_TAX = 'tax';

    /** Which tables show: the pension plan and the insurance, or the income tax. A filter, not an id. */
    #[Url(as: 'tables', except: self::TAB_CONTRIBUTIONS)]
    public string $tab = self::TAB_CONTRIBUTIONS;

    /** The first day of the set shown, or blank for the one in use today. */
    #[Url(as: 'from', except: '')]
    public string $from = '';

    /** The sample pay typed into the calculator, as the money input holds it. */
    public string $samplePay = '2000';

    public function render(): View
    {
        $today = LocalClock::todayKey();
        $inUse = DeductionRates::inForceOn($today);
        $sets = DeductionRates::published();
        $shown = $inUse;
        foreach ($sets as $set) {
            if ($set->effectiveFrom === $this->from) {
                $shown = $set;
            }
        }
        $tab = $this->tab === self::TAB_TAX ? self::TAB_TAX : self::TAB_CONTRIBUTIONS;
        $province = TaxDetails::DEFAULT_PROVINCE;

        return view('livewire.records.contribution-tables-page', [
            'tab' => $tab,
            'sets' => $sets,
            'inUse' => $inUse,
            'shown' => $shown,
            'next' => $shown->next(),
            'today' => $today,
            'province' => $province,
            'provinceName' => TaxDetails::PROVINCES[$province],
            'provincial' => $shown->province($province),
            'sample' => $this->sample($shown, $province),
        ]);
    }

    /**
     * What the shown set deducts from the typed pay, for an employee who
     * claims the basic amounts, on the company's own pay schedule.
     *
     * @return array{pays: int, every: string, pay: float, counted: float, exemption: float, pension: float, employer_pension: float, insurance: float, employer_insurance: float, yearly: float, federal: float, provincial: float}
     */
    private function sample(DeductionRates $rates, string $province): array
    {
        $row = PaySchedules::default();
        $schedule = $row === null ? null : PaySchedules::schedule($row);
        $pays = $schedule?->usualPaysPerYear() ?? 26;
        $every = match ($pays) {
            52 => 'Weekly',
            26 => 'Bi-Weekly',
            24 => 'Twice-Monthly',
            12 => 'Monthly',
            default => 'Per-Period',
        };

        $typed = trim(Currency::strip($this->samplePay));
        $pay = is_numeric($typed) && (float) $typed >= 0.0 && (float) $typed <= 9_999_999.0 ? (float) $typed : 0.0;

        $deductions = new SourceDeductions($rates);
        $exemption = $deductions->pensionExemption($pays);
        $pension = $deductions->pension($pay, $pays);
        $insurance = $deductions->insurance($pay);
        $tax = $deductions->incomeTax(new TaxInputs($pays, $pay, $pension, 0.0, $insurance, $province));

        return [
            'pays' => $pays,
            'every' => $every,
            'pay' => $pay,
            'counted' => max(0.0, $pay - $exemption),
            'exemption' => $exemption,
            'pension' => $pension,
            'employer_pension' => $pension,
            'insurance' => $insurance,
            'employer_insurance' => $deductions->employerInsurance($insurance),
            'yearly' => $pay * $pays,
            'federal' => $tax->federal,
            'provincial' => $tax->provincial,
        ];
    }
}
