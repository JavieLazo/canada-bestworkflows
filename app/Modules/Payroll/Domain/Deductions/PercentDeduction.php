<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Domain\Deductions;

use App\Support\Money;
use InvalidArgumentException;

/**
 * A Payroll Deductions row priced as a PERCENTAGE of salary: an employee
 * share and an employer share (reported, never deducted from the
 * employee's pay) of a base — the monthly rate, this payroll's basic pay or
 * its gross pay — taken every payroll (half of a monthly base each time)
 * or once a month on the second cut-off, under an optional monthly cap,
 * and either after the tax like every other named deduction or BEFORE it,
 * for an amount that is not part of taxable pay. ONE immutable value read
 * strictly from the row's figures and captured in the payroll evidence, so
 * a replay never reads the list.
 *
 * DELIBERATE DIVERGENCE: the reference has one typed Other line and no
 * percentage deduction. Pure: no clock, database or framework. Pesos
 * through Money::round() only.
 */
final class PercentDeduction
{
    public const string BASE_MONTHLY = 'MONTHLY';

    public const string BASE_BASIC = 'BASIC';

    public const string BASE_GROSS = 'GROSS';

    public const string FREQUENCY_EVERY = 'EVERY';

    public const string FREQUENCY_SECOND = 'SECOND';

    /** @var list<string> */
    public const array BASES = [self::BASE_MONTHLY, self::BASE_BASIC, self::BASE_GROSS];

    /** @var list<string> */
    public const array FREQUENCIES = [self::FREQUENCY_EVERY, self::FREQUENCY_SECOND];

    /** The figures' keys, the shape the row and the evidence hold. @var list<string> */
    public const array KEYS = ['employee', 'employer', 'base', 'frequency', 'cap', 'before_tax'];

    private function __construct(
        /** The employee share, a percent of the base. */
        public readonly float $employee,
        /** The employer share, a percent of the base; reported only. */
        public readonly float $employer,
        public readonly string $base,
        public readonly string $frequency,
        /** The most EITHER share comes to in a month (each share is capped on its own), or null. */
        public readonly ?float $cap,
        public readonly bool $beforeTax,
    ) {}

    /**
     * Strictly from a row's or the evidence's figures.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $error = self::error($data);
        if ($error !== null) {
            throw new InvalidArgumentException($error);
        }

        return new self(
            (float) $data['employee'], (float) $data['employer'], (string) $data['base'], (string) $data['frequency'],
            $data['cap'] === null ? null : (float) $data['cap'], (bool) $data['before_tax'],
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'employee' => $this->employee, 'employer' => $this->employer, 'base' => $this->base,
            'frequency' => $this->frequency, 'cap' => $this->cap, 'before_tax' => $this->beforeTax,
        ];
    }

    /** Why the figures cannot be used, or null. */
    public static function error(mixed $data): ?string
    {
        if (! is_array($data)) {
            return 'The percentage rule is missing.';
        }
        foreach (self::KEYS as $key) {
            if (! array_key_exists($key, $data)) {
                return "The percentage rule is incomplete ({$key}).";
            }
        }
        foreach (['employee' => 'Employee share', 'employer' => 'Employer share'] as $key => $label) {
            if (! self::finite($data[$key]) || (float) $data[$key] < 0.0 || (float) $data[$key] > 100.0) {
                return "{$label}: enter a percent between 0 and 100.";
            }
        }
        if ((float) $data['employee'] <= 0.0 && (float) $data['employer'] <= 0.0) {
            return 'Enter an employee share, an employer share or both.';
        }
        if (! in_array($data['base'], self::BASES, true)) {
            return 'Choose what the percentage is based on.';
        }
        if (! in_array($data['frequency'], self::FREQUENCIES, true)) {
            return 'Choose how often it is taken.';
        }
        if ($data['cap'] !== null && (! self::finite($data['cap']) || (float) $data['cap'] <= 0.0 || (float) $data['cap'] > 999_999_999.0)) {
            return 'Enter the monthly cap, or leave it blank.';
        }
        if (! is_bool($data['before_tax'])) {
            return 'Say whether it is deducted before tax.';
        }

        return null;
    }

    /**
     * The same rule with an employee's own share in place of the row's —
     * the rate they chose (0: not a member; the employer share stays the
     * row's). Read from the employee's Contributions & tax version.
     */
    public function withEmployeeShare(float $percent): self
    {
        return new self($percent, $this->employer, $this->base, $this->frequency, $this->cap, $this->beforeTax);
    }

    /** Whether a payroll period is one the deduction is taken on: every payroll, or the month's second cut-off (a period starting after the 15th). */
    public function dueOn(string $periodStart): bool
    {
        return $this->frequency === self::FREQUENCY_EVERY || (int) substr($periodStart, 8, 2) > 15;
    }

    /**
     * The shares this payroll takes from a base: half of a monthly base on
     * each of the month's two payrolls, the whole of a per-payroll base; on
     * the second cut-off only when so set. The cap is monthly, so it is
     * halved the same way. Both shares in pesos; 0.0 when nothing is due.
     *
     * @param  float  $base  the monthly rate, or this payroll's basic or gross pay, as `base` names
     * @return array{employee: float, employer: float}
     */
    public function sharesFor(float $base, string $periodStart): array
    {
        if (! $this->dueOn($periodStart) || $base <= 0.0) {
            return ['employee' => 0.0, 'employer' => 0.0];
        }
        $fraction = $this->frequency === self::FREQUENCY_EVERY ? 0.5 : 1.0;
        $amountOf = function (float $percent) use ($base, $fraction): float {
            // A per-payroll base is already this payroll's; a monthly base is split in two.
            $amount = $this->base === self::BASE_MONTHLY ? $base * ($percent / 100) * $fraction : $base * ($percent / 100);
            if ($this->cap !== null) {
                $amount = min($amount, $this->cap * $fraction);
            }

            return Money::round($amount);
        };

        return ['employee' => $amountOf($this->employee), 'employer' => $amountOf($this->employer)];
    }

    private static function finite(mixed $value): bool
    {
        return (is_int($value) || is_float($value)) && is_finite((float) $value);
    }
}
