<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Domain\Deductions;

use App\Domain\Payroll\StatutoryLines;
use App\Modules\Payroll\Domain\Tax\AnnualWithholding;
use App\Support\Money;
use InvalidArgumentException;

/**
 * One employee's Contributions & tax settings as a version holds them and
 * as the engine reads them off the employee: per contribution whether it
 * is deducted (`allow_*`), a fixed employee or employer share in place of
 * the table's monthly figure (`fixed_*_ee` / `fixed_*_er`, zero = the
 * table), and when it is taken (`*_mode`: SEMI_MONTHLY halves the month's
 * figure on each cut-off, MONTHLY charges it whole on the second — the
 * two values the reference tells apart; a legacy QUARTERLY reads as
 * MONTHLY, which is how the engine always read it — and, since
 * divergence 19, HALF_THEN_REST and WHOLE_FIRST, which read what the
 * month's posted payslips already took); for withholding
 * whether it is deducted and ONE method — a fixed tax wins over a percent,
 * which wins over the table, annualized or not, so `fixed_tax`,
 * `percent_tax`, `annualize_tax` and `whole_year_tax` never hold more than
 * one (`whole_year_tax` is the whole-year estimate, divergence 20, with
 * `tax_spread` saying how its remainder is spread: AnnualWithholding); and
 * `percent_rates`, the employee share of their own on a percentage Payroll
 * Deduction (row id => percent; 0 = not a member), in place of the row's.
 *
 * ONE immutable value, read strictly from a version's or the evidence's
 * figures (`fromArray`) or leniently from the employee's profile columns
 * (`fromColumns`, the baseline of an employee with no approved version).
 * Pure: no clock, database or framework. Pesos through Money::round() only.
 */
final class GovDeductionSettings
{
    public const string MODE_SEMI_MONTHLY = 'SEMI_MONTHLY';

    /** Half on the first cut-off, the rest of the month's share on the second (StatutoryLines, divergence 19). */
    public const string MODE_HALF_THEN_REST = StatutoryLines::MODE_HALF_THEN_REST;

    /** The whole month's share on the first cut-off, the rest on the second (StatutoryLines, divergence 19). */
    public const string MODE_WHOLE_FIRST = StatutoryLines::MODE_WHOLE_FIRST;

    /** The four schedules a share may be taken on. */
    public const array MODES = [self::MODE_SEMI_MONTHLY, self::MODE_MONTHLY, self::MODE_HALF_THEN_REST, self::MODE_WHOLE_FIRST];

    public const string MODE_MONTHLY = 'MONTHLY';

    /** The three contributions, by the prefix their keys share. */
    public const array CONTRIBUTIONS = ['sss' => 'SSS', 'philhealth' => 'PhilHealth', 'pagibig' => 'Pag-IBIG'];

    /** The largest peso figure a setting takes (schemas.ts). */
    public const float AMOUNT_CAP = 99_999_999.0;

    /** The sixteen statutory settings at the standard computation: every switch on, no fixed figure, halved, the table's tax. */
    public const array STATUTORY = [
        'allow_sss' => true,
        'allow_philhealth' => true,
        'allow_pagibig' => true,
        'allow_withholding_tax' => true,
        'annualize_tax' => false,
        'fixed_tax' => 0.0,
        'percent_tax' => 0.0,
        'fixed_sss_ee' => 0.0,
        'fixed_sss_er' => 0.0,
        'fixed_philhealth_ee' => 0.0,
        'fixed_philhealth_er' => 0.0,
        'fixed_pagibig_ee' => 0.0,
        'fixed_pagibig_er' => 0.0,
        'sss_mode' => self::MODE_SEMI_MONTHLY,
        'philhealth_mode' => self::MODE_SEMI_MONTHLY,
        'pagibig_mode' => self::MODE_SEMI_MONTHLY,
    ];

    /**
     * The two whole-year keys (divergence 20), read as these defaults when
     * a version or the evidence predates them — the reference's methods —
     * so older figures restore unchanged.
     */
    public const array OPTIONAL = [
        'whole_year_tax' => false,
        'tax_spread' => AnnualWithholding::SPREAD_EQUAL,
    ];

    /** The standard computation as stored: the statutory settings and no rate of their own. */
    public const array STANDARD = [...self::STATUTORY, ...self::OPTIONAL, 'percent_rates' => []];

    /** Each setting as the audit row and the page name it. */
    public const array LABELS = [
        'allow_sss' => 'Deduct SSS',
        'allow_philhealth' => 'Deduct PhilHealth',
        'allow_pagibig' => 'Deduct Pag-IBIG',
        'allow_withholding_tax' => 'Deduct withholding tax',
        'annualize_tax' => 'Annualized tax',
        'fixed_tax' => 'Fixed tax',
        'percent_tax' => 'Percent tax',
        'fixed_sss_ee' => 'Fixed SSS (employee)',
        'fixed_sss_er' => 'Fixed SSS (employer)',
        'fixed_philhealth_ee' => 'Fixed PhilHealth (employee)',
        'fixed_philhealth_er' => 'Fixed PhilHealth (employer)',
        'fixed_pagibig_ee' => 'Fixed Pag-IBIG (employee)',
        'fixed_pagibig_er' => 'Fixed Pag-IBIG (employer)',
        'sss_mode' => 'SSS schedule',
        'philhealth_mode' => 'PhilHealth schedule',
        'pagibig_mode' => 'Pag-IBIG schedule',
        'whole_year_tax' => 'Whole-year tax',
        'tax_spread' => 'Tax spread',
        'percent_rates' => 'Own rates on percentage deductions',
    ];

    /**
     * @param  array<string, bool|float|string>  $values  the sixteen statutory settings, normalized
     * @param  array<string, float>  $percentRates  Payroll Deductions row id => the employee share percent of their own
     */
    private function __construct(
        private readonly array $values,
        public readonly array $percentRates,
    ) {}

    public static function standard(): self
    {
        return new self([...self::STATUTORY, ...self::OPTIONAL], []);
    }

    /**
     * Strictly from a version's or the evidence's figures: every key
     * present and readable, or an InvalidArgumentException.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $error = self::error($data);
        if ($error !== null) {
            throw new InvalidArgumentException($error);
        }
        $values = [];
        foreach (self::STATUTORY as $key => $default) {
            $values[$key] = match (true) {
                is_bool($default) => (bool) $data[$key],
                is_float($default) => Money::round($data[$key]),
                default => (string) $data[$key],
            };
        }
        $values['whole_year_tax'] = (bool) ($data['whole_year_tax'] ?? self::OPTIONAL['whole_year_tax']);
        $values['tax_spread'] = (string) ($data['tax_spread'] ?? self::OPTIONAL['tax_spread']);
        $rates = [];
        foreach ($data['percent_rates'] as $rowId => $rate) {
            $rates[(string) $rowId] = Money::round($rate);
        }
        ksort($rates, SORT_STRING);

        return new self($values, $rates);
    }

    /**
     * Leniently from the employee's profile columns (anything carrying the
     * sixteen names): the baseline an employee with no approved version is
     * priced by, read the way the engine reads it — a NULL or SEMI_MONTHLY
     * mode is the half, any other mode the whole month; junk collapses to
     * the standard value. No rate of their own.
     */
    public static function fromColumns(object $columns): self
    {
        $values = [];
        foreach (self::STATUTORY as $key => $default) {
            $value = $columns->{$key} ?? null;
            $values[$key] = match (true) {
                is_bool($default) => (bool) $value,
                is_float($default) => max(0.0, min(self::AMOUNT_CAP, Money::round($value))),
                default => self::modeOf(is_string($value) ? $value : null),
            };
        }
        $values['whole_year_tax'] = (bool) ($columns->whole_year_tax ?? false);
        $spread = $columns->tax_spread ?? null;
        $values['tax_spread'] = is_string($spread) && in_array($spread, AnnualWithholding::SPREADS, true) ? $spread : AnnualWithholding::SPREAD_EQUAL;

        return new self($values, []);
    }

    /** Why the figures cannot be used, or null. */
    public static function error(mixed $data): ?string
    {
        if (! is_array($data)) {
            return 'The contribution settings are missing.';
        }
        if (! array_key_exists('percent_rates', $data)) {
            return 'The contribution settings are incomplete (percent_rates).';
        }
        foreach (self::STATUTORY as $key => $default) {
            if (! array_key_exists($key, $data)) {
                return "The contribution settings are incomplete ({$key}).";
            }
            $value = $data[$key];
            if (is_bool($default) && ! is_bool($value)) {
                return self::LABELS[$key].': say whether it applies.';
            }
            if (is_float($default) && (! self::finite($value) || (float) $value < 0.0 || (float) $value > self::AMOUNT_CAP)) {
                return self::LABELS[$key].': enter an amount up to ₱99,999,999.00.';
            }
            if (is_string($default) && ! in_array($value, self::MODES, true)) {
                return self::LABELS[$key].': choose when it is deducted.';
            }
        }
        if ((float) $data['percent_tax'] > 100.0) {
            return 'Percent tax: enter a percent between 0 and 100.';
        }
        if (array_key_exists('whole_year_tax', $data) && ! is_bool($data['whole_year_tax'])) {
            return self::LABELS['whole_year_tax'].': say whether it applies.';
        }
        if (array_key_exists('tax_spread', $data) && (! is_string($data['tax_spread']) || ! in_array($data['tax_spread'], AnnualWithholding::SPREADS, true))) {
            return self::LABELS['tax_spread'].': choose how the remainder is spread.';
        }
        if (count(array_filter([(float) $data['fixed_tax'] > 0.0, (float) $data['percent_tax'] > 0.0, (bool) $data['annualize_tax'], (bool) ($data['whole_year_tax'] ?? false)])) > 1) {
            return 'Withholding tax is worked out one way: a fixed amount, a percent, the table, or the whole-year estimate.';
        }
        if (! is_array($data['percent_rates'])) {
            return 'The rates on percentage deductions are missing.';
        }
        foreach ($data['percent_rates'] as $rowId => $rate) {
            if (! is_string($rowId) || $rowId === '' || strlen($rowId) > 26) {
                return 'A rate names a percentage deduction that does not exist.';
            }
            if (! self::finite($rate) || (float) $rate < 0.0 || (float) $rate > 100.0) {
                return 'A rate on a percentage deduction must be a percent between 0 and 100.';
            }
        }

        return null;
    }

    /** @return array<string, mixed> the stored and evidence shape */
    public function toArray(): array
    {
        return [...$this->values, 'percent_rates' => $this->percentRates];
    }

    /** One of the sixteen statutory settings, as the engine reads it. */
    public function get(string $key): bool|float|string
    {
        return $this->values[$key];
    }

    /** @return array<string, bool|float|string> the sixteen statutory settings and the two whole-year keys */
    public function statutory(): array
    {
        return $this->values;
    }

    /**
     * The keys that differ from the standard computation; `percent_rates`
     * when any rate of their own is set.
     *
     * @return list<string>
     */
    public function customized(): array
    {
        return self::changedKeys(self::standard(), $this);
    }

    /**
     * The keys whose value differs between two settings.
     *
     * @return list<string>
     */
    public static function changedKeys(self $before, self $after): array
    {
        $keys = [];
        foreach ($before->values as $key => $value) {
            if ($value !== $after->values[$key]) {
                $keys[] = $key;
            }
        }
        if ($before->percentRates !== $after->percentRates) {
            $keys[] = 'percent_rates';
        }

        return $keys;
    }

    /** The employee share percent of their own on a percentage deduction row, or null for the row's. */
    public function percentRate(string $rowId): ?float
    {
        return $this->percentRates[$rowId] ?? null;
    }

    /** A stored mode as one of the four the engine tells apart: a blank is the half, any other text the whole month on the second cut-off. */
    public static function modeOf(?string $mode): string
    {
        if ($mode === null || trim($mode) === '') {
            return self::MODE_SEMI_MONTHLY;
        }

        return in_array($mode, self::MODES, true) ? $mode : self::MODE_MONTHLY;
    }

    private static function finite(mixed $value): bool
    {
        return (is_int($value) || is_float($value)) && is_finite((float) $value);
    }
}
