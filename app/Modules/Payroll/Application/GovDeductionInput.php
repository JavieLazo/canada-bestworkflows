<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Application;

use App\Modules\Payroll\Domain\Deductions\GovDeductionSettings;
use App\Support\Money;

/**
 * One employee's own rates on the percentage Payroll Deductions as the
 * employee's pay page types them, checked and turned into a version's
 * figures (GovDeductionSettings), and back into the form.
 *
 * THE FORM IS THE RATES ALONE: one percent per percentage row, blank = the
 * row's listed rate, 0 = not a member. A version's other keys are stored
 * shape the page never offers; `figures()` carries them over from the
 * settings handed in, so saving a rate changes nothing else on the version.
 */
final class GovDeductionInput
{
    private function __construct() {}

    /**
     * The form a page fills from settings.
     *
     * @param  list<string>  $percentRows  the percentage Payroll Deductions row ids the form offers a rate on
     * @return array{percent: array<string, string>}
     */
    public static function toForm(GovDeductionSettings $settings, array $percentRows = []): array
    {
        $percent = [];
        foreach ($percentRows as $rowId) {
            $rate = $settings->percentRate($rowId);
            $percent[$rowId] = $rate === null ? '' : self::rateText($rate);
        }

        return ['percent' => $percent];
    }

    /**
     * The typed form as a version's figures, or the first thing wrong with it.
     *
     * @param  array<string, mixed>  $form
     * @param  list<string>  $percentRows  the percentage rows a rate may be typed on; a rate on any other row is refused
     * @param  GovDeductionSettings|null  $current  the settings the version's other keys are carried over from; the standard ones when null
     * @return array<string, mixed>|string
     */
    public static function figures(array $form, array $percentRows = [], ?GovDeductionSettings $current = null): array|string
    {
        $rates = [];
        $typed = $form['percent'] ?? [];
        foreach (is_array($typed) ? $typed : [] as $rowId => $text) {
            if (! is_string($rowId) || ! in_array($rowId, $percentRows, true)) {
                return 'A rate names a percentage deduction that does not exist.';
            }
            if (! is_string($text) || trim($text) === '') {
                continue;
            }
            $rate = self::rate($text);
            if ($rate === null) {
                return 'A rate of their own on a percentage deduction must be a percent between 0 and 100, or blank for the listed rate.';
            }
            $rates[$rowId] = $rate;
        }

        return [...($current ?? GovDeductionSettings::standard())->toArray(), 'percent_rates' => $rates];
    }

    /** A typed percent: anything unreadable, negative or over 100 is null. */
    private static function rate(string $text): ?float
    {
        $text = str_replace(',', '', trim($text));
        if (! is_numeric($text) || ! is_finite((float) $text)) {
            return null;
        }
        $value = Money::round((float) $text);

        return $value < 0.0 || $value > 100.0 ? null : $value;
    }

    /** A stored rate as the form shows it: at most two decimals, a zero as "0". */
    private static function rateText(float $rate): string
    {
        return $rate <= 0.0 ? '0' : rtrim(rtrim(number_format($rate, 2, '.', ''), '0'), '.');
    }
}
