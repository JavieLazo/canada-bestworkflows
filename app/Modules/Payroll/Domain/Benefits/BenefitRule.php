<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Domain\Benefits;

use App\Support\Money;
use InvalidArgumentException;

/**
 * How one incentive or bonus of the catalog is paid: its amount, how often
 * it is due, who qualifies, how it is prorated and how it is taxed — ONE
 * immutable value read strictly from the catalog row's figures, and
 * captured in the payroll evidence so a replay never reads the catalog.
 *
 * DELIBERATE DIVERGENCE: the reference pays no bonus at all. A scheduled
 * benefit (an amount rule other than TYPED and a frequency other than ONCE)
 * is proposed on every draft payroll it is due on, for every member who
 * qualifies, as an automatic line the officer may type over; a TYPED or
 * ONCE benefit is only ever typed.
 *
 *  - amount: FIXED pesos; PERCENT of the monthly rate; MONTH, one month's
 *    basic salary (the monthly rate); DAILY pesos per day worked; TYPED.
 *  - frequency: EVERY payroll; FIRST, the month's first cut-off (a period
 *    starting on or before the 15th); SECOND, the month's second cut-off;
 *    YEARLY, the first cut-off of `month`; ONCE, never scheduled.
 *  - who qualifies: `statuses`, the Employment list ids (null = everyone),
 *    and `minServiceMonths` of service by the period's end.
 *  - proration: NONE; MONTHS, by the months served in the calendar year
 *    (twelfths); DAYS, by the paid days over the period's scheduled days.
 *  - tax: TAXABLE with the pay; EXEMPT under the yearly ceiling
 *    (ExemptBenefitCeiling); DE_MINIMIS, exempt up to the benefit's own
 *    `cap` per line, the rest joining the ceiling's pool; NOT_INCOME, never
 *    taxed and never counted.
 *  - `countsToward13th`: whether the line is basic pay for the 13th month.
 *
 * Pure: no clock, database or framework. Pesos through Money::round() only.
 */
final class BenefitRule
{
    public const string AMOUNT_FIXED = 'FIXED';

    public const string AMOUNT_PERCENT = 'PERCENT';

    public const string AMOUNT_MONTH = 'MONTH';

    public const string AMOUNT_DAILY = 'DAILY';

    public const string AMOUNT_TYPED = 'TYPED';

    public const string FREQUENCY_EVERY = 'EVERY';

    public const string FREQUENCY_FIRST = 'FIRST';

    public const string FREQUENCY_SECOND = 'SECOND';

    public const string FREQUENCY_YEARLY = 'YEARLY';

    public const string FREQUENCY_ONCE = 'ONCE';

    public const string PRORATION_NONE = 'NONE';

    public const string PRORATION_MONTHS = 'MONTHS';

    public const string PRORATION_DAYS = 'DAYS';

    public const string TAX_TAXABLE = 'TAXABLE';

    public const string TAX_EXEMPT = 'EXEMPT';

    public const string TAX_DE_MINIMIS = 'DE_MINIMIS';

    public const string TAX_NOT_INCOME = 'NOT_INCOME';

    /** @var list<string> */
    public const array AMOUNT_TYPES = [self::AMOUNT_FIXED, self::AMOUNT_PERCENT, self::AMOUNT_MONTH, self::AMOUNT_DAILY, self::AMOUNT_TYPED];

    /** @var list<string> */
    public const array FREQUENCIES = [self::FREQUENCY_EVERY, self::FREQUENCY_FIRST, self::FREQUENCY_SECOND, self::FREQUENCY_YEARLY, self::FREQUENCY_ONCE];

    /** @var list<string> */
    public const array PRORATIONS = [self::PRORATION_NONE, self::PRORATION_MONTHS, self::PRORATION_DAYS];

    /** @var list<string> */
    public const array TAXES = [self::TAX_TAXABLE, self::TAX_EXEMPT, self::TAX_DE_MINIMIS, self::TAX_NOT_INCOME];

    /** The figures' keys, the shape a catalog row and the evidence hold. @var list<string> */
    public const array KEYS = ['amount_type', 'amount', 'frequency', 'month', 'statuses', 'min_service_months', 'proration', 'tax', 'cap', 'approval_reference', 'counts_toward_13th'];

    /**
     * @param  list<string>|null  $statuses
     */
    private function __construct(
        public readonly string $amountType,
        public readonly ?float $amount,
        public readonly string $frequency,
        public readonly ?int $month,
        public readonly ?array $statuses,
        public readonly int $minServiceMonths,
        public readonly string $proration,
        public readonly string $tax,
        public readonly ?float $cap,
        public readonly ?string $approvalReference,
        public readonly bool $countsToward13th,
    ) {}

    /** The catalog's former shape: an amount typed per payroll, taxed or under the ceiling. */
    public static function typed(bool $taxable): self
    {
        return new self(self::AMOUNT_TYPED, null, self::FREQUENCY_ONCE, null, null, 0, self::PRORATION_NONE, $taxable ? self::TAX_TAXABLE : self::TAX_EXEMPT, null, null, false);
    }

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
        $statuses = $data['statuses'];

        return new self(
            (string) $data['amount_type'],
            $data['amount'] === null ? null : (float) $data['amount'],
            (string) $data['frequency'],
            $data['month'] === null ? null : (int) $data['month'],
            $statuses === null ? null : array_values(array_map(static fn (mixed $id): string => (string) $id, $statuses)),
            (int) $data['min_service_months'],
            (string) $data['proration'],
            (string) $data['tax'],
            $data['cap'] === null ? null : (float) $data['cap'],
            $data['approval_reference'] === null ? null : (string) $data['approval_reference'],
            (bool) $data['counts_toward_13th'],
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'amount_type' => $this->amountType,
            'amount' => $this->amount,
            'frequency' => $this->frequency,
            'month' => $this->month,
            'statuses' => $this->statuses,
            'min_service_months' => $this->minServiceMonths,
            'proration' => $this->proration,
            'tax' => $this->tax,
            'cap' => $this->cap,
            'approval_reference' => $this->approvalReference,
            'counts_toward_13th' => $this->countsToward13th,
        ];
    }

    /** Why the figures cannot be used, or null. */
    public static function error(mixed $data): ?string
    {
        if (! is_array($data)) {
            return 'The benefit rule is missing.';
        }
        foreach (self::KEYS as $key) {
            if (! array_key_exists($key, $data)) {
                return "The benefit rule is incomplete ({$key}).";
            }
        }
        if (! in_array($data['amount_type'], self::AMOUNT_TYPES, true)) {
            return 'Choose how the amount is set.';
        }
        $amount = $data['amount'];
        if ($data['amount_type'] === self::AMOUNT_TYPED || $data['amount_type'] === self::AMOUNT_MONTH) {
            if ($amount !== null) {
                return 'This amount rule takes no figure.';
            }
        } elseif (! self::finite($amount) || (float) $amount <= 0.0 || (float) $amount > 999_999_999.0) {
            return $data['amount_type'] === self::AMOUNT_PERCENT ? 'Enter the percent of the monthly salary.' : 'Enter the amount.';
        }
        if ($data['amount_type'] === self::AMOUNT_PERCENT && (float) $amount > 1000.0) {
            return 'Enter a percent between 0 and 1,000.';
        }
        if (! in_array($data['frequency'], self::FREQUENCIES, true)) {
            return 'Choose how often it is paid.';
        }
        if ($data['frequency'] === self::FREQUENCY_YEARLY) {
            if (! is_int($data['month']) || $data['month'] < 1 || $data['month'] > 12) {
                return 'Choose the month it is paid in.';
            }
        } elseif ($data['month'] !== null) {
            return 'Only a yearly benefit has a month.';
        }
        if ($data['amount_type'] === self::AMOUNT_TYPED && $data['frequency'] !== self::FREQUENCY_ONCE) {
            return 'An amount typed per payroll cannot be scheduled.';
        }
        $statuses = $data['statuses'];
        if ($statuses !== null) {
            if (! is_array($statuses) || ! array_is_list($statuses) || $statuses === []) {
                return 'Choose who qualifies.';
            }
            foreach ($statuses as $id) {
                if (! is_string($id) || trim($id) === '') {
                    return 'Choose who qualifies.';
                }
            }
        }
        if (! is_int($data['min_service_months']) || $data['min_service_months'] < 0 || $data['min_service_months'] > 600) {
            return 'Enter the minimum months of service, 0 for none.';
        }
        if (! in_array($data['proration'], self::PRORATIONS, true)) {
            return 'Choose the proration.';
        }
        if (! in_array($data['tax'], self::TAXES, true)) {
            return 'Choose how it is taxed.';
        }
        $cap = $data['cap'];
        if ($data['tax'] === self::TAX_DE_MINIMIS) {
            if (! self::finite($cap) || (float) $cap <= 0.0 || (float) $cap > 999_999_999.0) {
                return 'Enter the cap up to which it is tax-free.';
            }
        } elseif ($cap !== null) {
            return 'Only a de minimis benefit has a cap.';
        }
        $reference = $data['approval_reference'];
        if ($reference !== null && (! is_string($reference) || mb_strlen($reference) > 160)) {
            return 'Keep the approval reference to 160 characters.';
        }
        if (! is_bool($data['counts_toward_13th'])) {
            return 'Say whether it counts toward the 13th month.';
        }

        return null;
    }

    /** Taxed with the pay, as the posted line's flag records it. */
    public function isTaxable(): bool
    {
        return $this->tax === self::TAX_TAXABLE;
    }

    /** Whether payroll proposes it on its own: an amount it can work out, on a schedule. */
    public function isScheduled(): bool
    {
        return $this->amountType !== self::AMOUNT_TYPED && $this->frequency !== self::FREQUENCY_ONCE;
    }

    /**
     * Whether a period is one the benefit is due on. A month's first
     * cut-off is a period starting on or before the 15th; the second, one
     * starting after it. A yearly benefit falls on the first cut-off of its
     * month. Dates are 'Y-m-d'.
     */
    public function scheduledFor(string $periodStart, string $periodEnd): bool
    {
        if (! $this->isScheduled()) {
            return false;
        }
        $startDay = (int) substr($periodStart, 8, 2);
        $startMonth = (int) substr($periodStart, 5, 2);

        return match ($this->frequency) {
            self::FREQUENCY_EVERY => true,
            self::FREQUENCY_FIRST => $startDay <= 15,
            self::FREQUENCY_SECOND => $startDay > 15,
            self::FREQUENCY_YEARLY => $startDay <= 15 && $startMonth === $this->month,
            default => false,
        };
    }

    /** Whether an employee of this status with this much service is paid it. */
    public function qualifies(?string $statusId, int $serviceMonths): bool
    {
        if ($serviceMonths < $this->minServiceMonths) {
            return false;
        }

        return $this->statuses === null || ($statusId !== null && in_array($statusId, $this->statuses, true));
    }

    /**
     * The pesos due, before any typed override: the amount rule, then the
     * proration. 0.0 when nothing is due (no monthly rate for a salary
     * rule, no day worked for a daily one, a zero proration).
     *
     * @param  float  $monthlyRate  the employee's monthly rate, 0 when none
     * @param  float  $workDays  the days worked in the period
     * @param  float  $paidDays  the days paid (worked and on paid leave)
     * @param  int  $scheduledDays  the period's scheduled working days
     * @param  int  $monthsServedInYear  the months of service inside the calendar year, through the period's end
     */
    public function amountFor(float $monthlyRate, float $workDays, float $paidDays, int $scheduledDays, int $monthsServedInYear): float
    {
        $base = match ($this->amountType) {
            self::AMOUNT_FIXED => (float) $this->amount,
            self::AMOUNT_PERCENT => $monthlyRate * ((float) $this->amount) / 100,
            self::AMOUNT_MONTH => $monthlyRate,
            self::AMOUNT_DAILY => ((float) $this->amount) * $workDays,
            default => 0.0,
        };
        $factor = match ($this->proration) {
            self::PRORATION_MONTHS => max(0, min(12, $monthsServedInYear)) / 12,
            self::PRORATION_DAYS => $scheduledDays > 0 ? min(1.0, max(0.0, $paidDays) / $scheduledDays) : 0.0,
            default => 1.0,
        };

        return Money::round($base * $factor);
    }

    private static function finite(mixed $value): bool
    {
        return (is_int($value) || is_float($value)) && is_finite((float) $value);
    }
}
