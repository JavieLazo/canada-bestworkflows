<?php

declare(strict_types=1);

namespace App\Livewire\Records\Support;

use App\Modules\Payroll\Application\GovDeductionInput;
use App\Modules\Payroll\Domain\Deductions\GovDeductionSettings;
use App\Modules\Workforce\Contracts\Views\EmployeePaySettingsView;

/**
 * Employee Pay Settings as the list and the employee's page word them: one
 * short phrase per contribution, for withholding and for the hourly rate,
 * read straight off the settings the way the engine reads them (a fixed
 * figure above zero replaces the table; the MONTHLY schedule charges the
 * whole month on the month-ending cut-off).
 */
final class PaySettingsText
{
    private function __construct() {}

    /** SSS, PhilHealth or Pag-IBIG (`$key` is the column prefix): "Table", "Off", "₱500.00 fixed", with the schedule when it is not halved. */
    public static function contribution(GovDeductionSettings $settings, string $key): string
    {
        if (! (bool) $settings->get("allow_{$key}")) {
            return 'Off';
        }
        $employee = (float) $settings->get("fixed_{$key}_ee");
        $employer = (float) $settings->get("fixed_{$key}_er");
        $text = match (true) {
            $employee > 0.0 && $employer > 0.0 => Hris::peso($employee).' + '.Hris::peso($employer).' fixed',
            $employee > 0.0 => Hris::peso($employee).' fixed',
            $employer > 0.0 => 'Table, employer '.Hris::peso($employer).' fixed',
            default => 'Table',
        };

        return $text.match ($settings->get("{$key}_mode")) {
            GovDeductionSettings::MODE_MONTHLY => ', whole month on the second cut-off',
            GovDeductionSettings::MODE_HALF_THEN_REST => ', half then the rest',
            GovDeductionSettings::MODE_WHOLE_FIRST => ', whole month on the first cut-off',
            default => '',
        };
    }

    /** "Off", "BIR semi-monthly table", "Annualized per cut-off (×24)", "₱1,000.00 fixed" or "5% of taxable compensation". */
    public static function withholding(GovDeductionSettings $settings): string
    {
        if (! (bool) $settings->get('allow_withholding_tax')) {
            return 'Off';
        }
        $fixed = (float) $settings->get('fixed_tax');
        $percent = (float) $settings->get('percent_tax');

        return match (GovDeductionInput::methodOf($fixed, $percent, (bool) $settings->get('annualize_tax'), (bool) $settings->get('whole_year_tax'))) {
            GovDeductionInput::METHOD_FIXED => Hris::peso($fixed).' fixed',
            GovDeductionInput::METHOD_PERCENT => Hris::moneyText($percent).'% of taxable compensation',
            GovDeductionInput::METHOD_ANNUALIZED => 'Annualized per cut-off (×24)',
            GovDeductionInput::METHOD_WHOLE_YEAR => 'Projected annual tax with year-end adjustment, '.lcfirst((string) (GovDeductionInput::SPREADS[(string) $settings->get('tax_spread')] ?? 'spread equally')),
            default => 'BIR semi-monthly table',
        };
    }

    /**
     * The Employee Pay Settings list's one cell for contributions and tax:
     * "Standard", or only what differs, named ("SSS: Off · Withholding tax:
     * 5% of taxable compensation").
     *
     * @param  list<string>  $customized  the settings keys that differ from the standard ones
     */
    public static function summary(GovDeductionSettings $settings, array $customized): string
    {
        $parts = [];
        foreach (GovDeductionSettings::CONTRIBUTIONS as $key => $name) {
            if (array_intersect($customized, self::contributionKeys($key)) !== []) {
                $parts[] = $name.': '.self::contribution($settings, $key);
            }
        }
        if (array_intersect($customized, self::withholdingKeys()) !== []) {
            $parts[] = 'Withholding tax: '.self::withholding($settings);
        }
        if (in_array('percent_rates', $customized, true)) {
            $parts[] = 'Own rates on percentage deductions';
        }

        return $parts === [] ? 'Standard' : implode(' · ', $parts);
    }

    /**
     * Every settings key the summary rests on, for marking a summary that differs.
     *
     * @return list<string>
     */
    public static function summaryKeys(): array
    {
        $keys = [...self::withholdingKeys(), 'percent_rates'];
        foreach (array_keys(GovDeductionSettings::CONTRIBUTIONS) as $key) {
            $keys = [...$keys, ...self::contributionKeys($key)];
        }

        return $keys;
    }

    /** "From the shift", or the override per hour. */
    public static function hourly(EmployeePaySettingsView $settings): string
    {
        return (float) $settings->hourly_rate > 0.0 ? Hris::peso($settings->hourly_rate).' an hour' : 'From the shift';
    }

    /**
     * The settings keys a contribution's phrase rests on, for marking a
     * phrase that differs.
     *
     * @return list<string>
     */
    public static function contributionKeys(string $key): array
    {
        return ["allow_{$key}", "fixed_{$key}_ee", "fixed_{$key}_er", "{$key}_mode"];
    }

    /** @return list<string> */
    public static function withholdingKeys(): array
    {
        return ['allow_withholding_tax', 'annualize_tax', 'fixed_tax', 'percent_tax', 'whole_year_tax', 'tax_spread'];
    }
}
