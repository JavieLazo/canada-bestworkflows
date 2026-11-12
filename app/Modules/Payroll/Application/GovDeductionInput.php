<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Application;

use App\Modules\Payroll\Domain\Deductions\GovDeductionSettings;
use App\Modules\Payroll\Domain\Tax\AnnualWithholding;
use App\Support\Money;

/**
 * One employee's Contributions & tax settings as the employee's pay page
 * types them, checked and turned into a version's figures
 * (GovDeductionSettings), and back into the form.
 *
 * THE FORM IS NOT THE FIGURES. The engine reads withholding off three
 * values with a precedence (a fixed tax wins over a percent, which wins
 * over the table, annualized or not), so a form that offered all three
 * would let a typed percent be silently ignored. The form offers ONE method
 * instead and writes the other two at their defaults. Each contribution
 * offers the two schedules the engine tells apart. A fixed contribution
 * above zero replaces the table's monthly figure, and blank or zero is the
 * table: zero cannot exempt anyone — the Deduct switch does that. A rate
 * of their own on a percentage Payroll Deduction is typed per row, blank
 * = the row's rate.
 */
final class GovDeductionInput
{
    /** The two schedules the engine tells apart, with their names on screen. */
    public const array MODES = [
        GovDeductionSettings::MODE_SEMI_MONTHLY => 'Half on each cut-off',
        GovDeductionSettings::MODE_MONTHLY => 'Whole month on the second cut-off',
        GovDeductionSettings::MODE_HALF_THEN_REST => 'Half on the first cut-off, the rest on the second',
        GovDeductionSettings::MODE_WHOLE_FIRST => 'Whole month on the first cut-off',
    ];

    public const string METHOD_TABLE = 'TABLE';

    public const string METHOD_ANNUALIZED = 'ANNUALIZED';

    public const string METHOD_FIXED = 'FIXED';

    public const string METHOD_PERCENT = 'PERCENT';

    /** The whole-year estimate (AnnualWithholding, divergence 20). */
    public const string METHOD_WHOLE_YEAR = 'WHOLE_YEAR';

    /** How withholding is worked out, with the names on screen. */
    public const array METHODS = [
        self::METHOD_TABLE => 'BIR semi-monthly table',
        self::METHOD_ANNUALIZED => 'Annualized per cut-off (×24)',
        self::METHOD_FIXED => 'Fixed amount per payslip',
        self::METHOD_PERCENT => 'Percent of taxable compensation',
        self::METHOD_WHOLE_YEAR => 'Projected annual tax with year-end adjustment',
    ];

    /** How the whole-year estimate's remainder is spread, with the names on screen. */
    public const array SPREADS = [
        AnnualWithholding::SPREAD_EQUAL => 'Spread equally over the payrolls left',
        AnnualWithholding::SPREAD_NEXT => 'All on the next payroll',
        AnnualWithholding::SPREAD_DECEMBER => 'All on the year\'s last payroll',
    ];

    private function __construct() {}

    /**
     * The form a page fills from settings.
     *
     * @param  list<string>  $percentRows  the percentage Payroll Deductions row ids the form offers a rate on
     * @return array<string, bool|string|array<string, string>>
     */
    public static function toForm(GovDeductionSettings $settings, array $percentRows = []): array
    {
        $form = [];
        foreach (array_keys(GovDeductionSettings::CONTRIBUTIONS) as $key) {
            $form["{$key}_deduct"] = (bool) $settings->get("allow_{$key}");
            $form["{$key}_fixed_ee"] = self::amountText($settings->get("fixed_{$key}_ee"));
            $form["{$key}_fixed_er"] = self::amountText($settings->get("fixed_{$key}_er"));
            $form["{$key}_mode"] = (string) $settings->get("{$key}_mode");
        }
        $method = self::methodOf((float) $settings->get('fixed_tax'), (float) $settings->get('percent_tax'), (bool) $settings->get('annualize_tax'), (bool) $settings->get('whole_year_tax'));
        $form['tax_deduct'] = (bool) $settings->get('allow_withholding_tax');
        $form['tax_method'] = $method;
        $form['tax_spread'] = (string) $settings->get('tax_spread');
        $form['tax_amount'] = match ($method) {
            self::METHOD_FIXED => self::amountText($settings->get('fixed_tax')),
            self::METHOD_PERCENT => self::amountText($settings->get('percent_tax')),
            default => '',
        };
        $percent = [];
        foreach ($percentRows as $rowId) {
            $rate = $settings->percentRate($rowId);
            $percent[$rowId] = $rate === null ? '' : self::amountText($rate, zero: true);
        }
        $form['percent'] = $percent;

        return $form;
    }

    /**
     * The typed form as a version's figures, or the first thing wrong with it.
     *
     * @param  array<string, mixed>  $form
     * @param  list<string>  $percentRows  the percentage rows a rate may be typed on; a rate on any other row is refused
     * @return array<string, mixed>|string
     */
    public static function figures(array $form, array $percentRows = []): array|string
    {
        $figures = [];
        foreach (GovDeductionSettings::CONTRIBUTIONS as $key => $name) {
            $figures["allow_{$key}"] = (bool) ($form["{$key}_deduct"] ?? false);
            foreach (['ee' => 'employee', 'er' => 'employer'] as $share => $whose) {
                $amount = self::amount($form["{$key}_fixed_{$share}"] ?? '');
                if ($amount === null) {
                    return "Fixed {$name} ({$whose} share): enter an amount up to ₱99,999,999.00, or leave it blank to use the table.";
                }
                $figures["fixed_{$key}_{$share}"] = $amount;
            }
            $mode = $form["{$key}_mode"] ?? null;
            if (! is_string($mode) || ! array_key_exists($mode, self::MODES)) {
                return "Choose when the {$name} contribution is deducted.";
            }
            $figures["{$key}_mode"] = $mode;
        }

        $method = $form['tax_method'] ?? null;
        if (! is_string($method) || ! array_key_exists($method, self::METHODS)) {
            return 'Choose how withholding tax is worked out.';
        }
        $deducted = (bool) ($form['tax_deduct'] ?? false);
        $taxAmount = 0.0;
        if ($method === self::METHOD_FIXED || $method === self::METHOD_PERCENT) {
            $taxAmount = self::amount($form['tax_amount'] ?? '');
            $invalid = $method === self::METHOD_FIXED
                ? ($taxAmount === null || $taxAmount <= 0.0)
                : ($taxAmount === null || $taxAmount <= 0.0 || $taxAmount > 100.0);
            if ($invalid && $deducted) {
                return $method === self::METHOD_FIXED
                    ? 'Enter the fixed tax per payslip, up to ₱99,999,999.00.'
                    : 'Enter the percent of taxable compensation, above 0 and at most 100.';
            }
            // With withholding off the form disables these fields, so a blank
            // or unreadable amount there falls back to the table instead of
            // refusing a save the officer cannot correct.
            if ($invalid) {
                $method = self::METHOD_TABLE;
                $taxAmount = 0.0;
            }
        }
        $spread = $form['tax_spread'] ?? AnnualWithholding::SPREAD_EQUAL;
        if (! is_string($spread) || ! array_key_exists($spread, self::SPREADS)) {
            return 'Choose how the remaining tax is spread.';
        }
        $figures['allow_withholding_tax'] = $deducted;
        $figures['annualize_tax'] = $method === self::METHOD_ANNUALIZED;
        $figures['whole_year_tax'] = $method === self::METHOD_WHOLE_YEAR;
        $figures['tax_spread'] = $spread;
        $figures['fixed_tax'] = $method === self::METHOD_FIXED ? (float) $taxAmount : 0.0;
        $figures['percent_tax'] = $method === self::METHOD_PERCENT ? (float) $taxAmount : 0.0;

        $rates = [];
        $typed = $form['percent'] ?? [];
        foreach (is_array($typed) ? $typed : [] as $rowId => $text) {
            if (! is_string($rowId) || ! in_array($rowId, $percentRows, true)) {
                return 'A rate names a percentage deduction that does not exist.';
            }
            if (! is_string($text) || trim($text) === '') {
                continue;
            }
            $rate = self::amount($text);
            if ($rate === null || $rate > 100.0) {
                return 'A rate of their own on a percentage deduction must be a percent between 0 and 100, or blank for the listed rate.';
            }
            $rates[$rowId] = $rate;
        }
        $figures['percent_rates'] = $rates;

        return $figures;
    }

    /** The withholding method the stored values amount to, in the engine's order of precedence. */
    public static function methodOf(float $fixedTax, float $percentTax, bool $annualize, bool $wholeYear = false): string
    {
        return match (true) {
            $fixedTax > 0.0 => self::METHOD_FIXED,
            $percentTax > 0.0 => self::METHOD_PERCENT,
            $annualize => self::METHOD_ANNUALIZED,
            $wholeYear => self::METHOD_WHOLE_YEAR,
            default => self::METHOD_TABLE,
        };
    }

    /** A typed peso figure: blank is 0, anything unreadable, negative or over the cap is null. */
    private static function amount(mixed $text): ?float
    {
        $text = is_string($text) ? str_replace(',', '', trim($text)) : $text;
        if ($text === '' || $text === null) {
            return 0.0;
        }
        if (! is_numeric($text) || ! is_finite((float) $text)) {
            return null;
        }
        $value = Money::round((float) $text);

        return $value < 0.0 || $value > GovDeductionSettings::AMOUNT_CAP ? null : $value;
    }

    /** A stored figure as the form shows it: blank for zero, unless zero is a value of its own. */
    private static function amountText(mixed $amount, bool $zero = false): string
    {
        $value = is_numeric($amount) ? (float) $amount : 0.0;
        if ($value <= 0.0) {
            return $zero ? '0' : '';
        }

        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }
}
