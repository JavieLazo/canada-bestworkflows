<?php

declare(strict_types=1);

namespace App\Livewire\Records\Support;

use App\Domain\Payroll\PayRules;
use App\Livewire\Records\ReferenceListPage;
use App\Modules\Payroll\Application\PayrollPeriodText;
use App\Modules\Payroll\Application\Queries\IncentiveTypeRecords;
use App\Modules\Payroll\Contracts\Views\PayRuleView;
use App\Modules\Payroll\Domain\Benefits\BenefitRule;
use App\Modules\Payroll\Domain\Deductions\GovDeductionSettings;
use App\Modules\Payroll\Domain\Deductions\PercentDeduction;
use App\Modules\Workforce\Contracts\Views\EmployeePaySettingsView;
use App\Platform\Records\ReferenceCatalogs;
use App\Support\Access;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * What one employee's pay page shows beside their own settings, each with
 * where it comes from: the rates that price them (Rate Per Month and Rate
 * Per Day from the employee's record, the day and hour those make under the Pay
 * Basis & Rates in force), the scheduled Incentives & Bonuses they qualify
 * for, and the percentage Payroll Deductions that reach everyone.
 *
 * Read off the catalogs' own rules (BenefitRule::qualifies,
 * PercentDeduction::sharesFor) and the same service count payroll uses, so
 * the page names what the next payroll proposes; it moves no peso. A
 * figure that depends on the month (the month's working days) or on the
 * payslip (a share of basic or gross pay) is described, not priced.
 */
final class EmployeePayProfile
{
    public const string FROM_COMPANY = 'company';

    public const string FROM_STATUS = 'status';

    public const string FROM_OWN = 'own';

    /**
     * The rates at a glance, each with where it comes from.
     *
     * @param  array{source: string, version: ?PayRuleView, next: ?PayRuleView, drafts: int}  $rates  EmployeeRates::today()
     * @return array{monthly: ?float, daily: array{text: string, note: string, from: string}, hourly: array{text: string, note: string, from: string}, rules: array{text: string, note: string, from: string}}
     */
    public static function glance(EmployeePaySettingsView $settings, array $rates): array
    {
        $monthly = (float) ($settings->monthly_rate ?? 0.0);
        $rulesFrom = match ($rates['source']) {
            EmployeeRates::OWN => self::FROM_OWN,
            EmployeeRates::STATUS => self::FROM_STATUS,
            default => self::FROM_COMPANY,
        };
        $figures = $rates['version']->params ?? PayRules::fromFormulas()->toArray();
        $method = $figures['daily_rate']['method'] ?? PayRules::METHOD_MONTH;
        $divisor = (float) ($figures['daily_rate']['value'] ?? 0.0);

        if ((float) ($settings->daily_rate ?? 0.0) > 0.0) {
            $daily = ['text' => Hris::peso((float) $settings->daily_rate), 'note' => 'Rate Per Day, as entered', 'from' => self::FROM_OWN];
        } elseif ($monthly <= 0.0) {
            $daily = ['text' => '—', 'note' => 'No Rate Per Month or Rate Per Day: payroll pays nothing', 'from' => self::FROM_OWN];
        } elseif ($method === PayRules::METHOD_FACTOR && $divisor > 0.0) {
            $daily = ['text' => Hris::peso($monthly * 12 / $divisor), 'note' => 'Monthly rate × 12 ÷ '.Hris::moneyText($divisor), 'from' => $rulesFrom];
        } elseif ($method === PayRules::METHOD_DIVISOR && $divisor > 0.0) {
            $daily = ['text' => Hris::peso($monthly / $divisor), 'note' => 'Monthly rate ÷ '.Hris::moneyText($divisor), 'from' => $rulesFrom];
        } else {
            $daily = ['text' => 'Changes each month', 'note' => 'Monthly rate ÷ the month\'s working days on their schedule', 'from' => $rulesFrom];
        }

        $hourly = $settings->hourly_rate > 0.0
            ? ['text' => Hris::peso($settings->hourly_rate).' an hour', 'note' => 'In place of the daily rate ÷ the shift\'s hours', 'from' => self::FROM_OWN]
            : ['text' => 'From the shift', 'note' => 'The daily rate ÷ the shift\'s paid hours', 'from' => self::FROM_COMPANY];

        $rules = [
            'text' => EmployeeRates::summary($rates['version']?->params),
            'note' => $rates['version'] !== null ? '' : 'The built-in rules',
            'from' => $rulesFrom,
        ];

        return ['monthly' => $monthly > 0.0 ? $monthly : null, 'daily' => $daily, 'hourly' => $hourly, 'rules' => $rules];
    }

    /**
     * The scheduled benefits of the Incentives & Bonuses the employee's
     * status qualifies for, with when one still waiting on their service
     * starts (`waiting`). Typed benefits are left out: payroll proposes
     * nothing for them.
     *
     * @return list<array{name: string, amount: string, when: string, tax: string, note: string, waiting: bool}>
     */
    public static function benefits(Access $access, EmployeePaySettingsView $settings, string $today): array
    {
        $cutoffEnd = self::cutoffEnd($today);
        $hired = $settings->date_hired?->setTimezone('UTC')->startOfDay();
        $end = CarbonImmutable::parse($cutoffEnd, 'UTC')->addDay();
        // As payroll counts it: whole months from the hire date through the period's end.
        $serviceMonths = $hired === null || $hired > $end ? 0 : (int) floor($hired->diffInMonths($end));

        $rows = [];
        foreach (app(IncentiveTypeRecords::class)->list($access) as $type) {
            try {
                $rule = BenefitRule::fromArray([
                    'amount_type' => $type->amount_type, 'amount' => $type->amount, 'frequency' => $type->frequency, 'month' => $type->month,
                    'statuses' => $type->statuses, 'min_service_months' => $type->min_service_months, 'proration' => $type->proration,
                    'tax' => $type->tax, 'cap' => $type->cap, 'approval_reference' => $type->approval_reference, 'counts_toward_13th' => (bool) $type->counts_toward_13th,
                ]);
            } catch (InvalidArgumentException) {
                continue;
            }
            if (! $rule->isScheduled()) {
                continue;
            }
            $note = 'Everyone who qualifies';
            $waiting = ! $rule->qualifies($settings->employment_status_id, $serviceMonths);
            if ($waiting) {
                // Their status is not on it at all: it never reaches them.
                if (! $rule->qualifies($settings->employment_status_id, PHP_INT_MAX) || $hired === null) {
                    continue;
                }
                $note = 'From '.PayrollPeriodText::day($hired->addMonthsNoOverflow((int) $type->min_service_months)->format('Y-m-d')).', after '.(int) $type->min_service_months.' months of service';
            }
            $rows[] = ['name' => $type->name, 'amount' => BenefitText::amount($type), 'when' => BenefitText::frequency($type), 'tax' => BenefitText::TAXES[BenefitText::tax($type)], 'note' => $note, 'waiting' => $waiting];
        }

        return $rows;
    }

    /**
     * The percentage Payroll Deductions — they reach every employee — with
     * this cut-off's employee share where the base is the monthly rate,
     * at the employee's own rate when their Contributions & tax settings
     * carry one (`own`: that rate, null for the listed one).
     *
     * @return list<array{id: string, name: string, detail: string, amount: ?float, own: ?float}>
     */
    public static function percentDeductions(Access $access, EmployeePaySettingsView $settings, string $today, ?GovDeductionSettings $gov = null): array
    {
        $rows = [];
        $catalog = app(ReferenceCatalogs::class)->for(ReferenceListPage::PAYROLL_LIST);
        foreach ($catalog->page($access, '', 'name', 'asc', 1, 500)->items() as $row) {
            if (($row->calculation ?? null) !== null || ! is_array($row->percent ?? null)) {
                continue;
            }
            try {
                $rule = PercentDeduction::fromArray($row->percent);
            } catch (InvalidArgumentException) {
                continue;
            }
            $own = $gov?->percentRate((string) $row->id);
            if ($own !== null) {
                $rule = $rule->withEmployeeShare($own);
            }
            $amount = null;
            if ($rule->base === PercentDeduction::BASE_MONTHLY) {
                $amount = $rule->sharesFor((float) ($settings->monthly_rate ?? 0.0), self::cutoffStart($today))['employee'];
            }
            $rows[] = ['id' => (string) $row->id, 'name' => (string) $row->name, 'detail' => DeductionText::percent($row->percent), 'amount' => $amount, 'own' => $own];
        }

        return $rows;
    }

    /** The first day of the cut-off a day falls in: the 1st or the 16th. */
    public static function cutoffStart(string $day): string
    {
        return substr($day, 0, 8).((int) substr($day, 8, 2) <= 15 ? '01' : '16');
    }

    /** The last day of the cut-off a day falls in: the 15th or the month's end. */
    private static function cutoffEnd(string $day): string
    {
        return (int) substr($day, 8, 2) <= 15
            ? substr($day, 0, 8).'15'
            : CarbonImmutable::parse($day, 'UTC')->endOfMonth()->format('Y-m-d');
    }
}
