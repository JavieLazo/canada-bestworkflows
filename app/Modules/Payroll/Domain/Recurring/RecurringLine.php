<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Domain\Recurring;

use App\Domain\Payroll\PayrollPeriod;
use App\Support\Money;
use InvalidArgumentException;

/**
 * One employee's standing payroll line — a deduction such as a loan
 * amortization, or an incentive such as a fixed allowance — that every
 * Basic Payroll it is due on proposes on its own, so nobody retypes it.
 *
 * `amount` is what one payroll proposes; `frequency` which payrolls: EVERY
 * one, or only the month's FIRST or SECOND cut-off (a period starting on or
 * before the 15th is the first, as BenefitRule reads it). The line is live
 * from `startsOn`, a cut-off's first day, through `endsOn` (the last day a
 * payroll period may start on; null is open-ended). A deduction may carry a
 * `total` — a loan's balance: the payslips posted against it reduce what is
 * left, the last payroll proposes only what is left, and once nothing is
 * left the line is paid off and proposes nothing. Pure: what was already
 * paid is handed in.
 */
final readonly class RecurringLine
{
    public const string KIND_DEDUCTION = 'DEDUCTION';

    public const string KIND_INCENTIVE = 'INCENTIVE';

    /** @var list<string> */
    public const array KINDS = [self::KIND_DEDUCTION, self::KIND_INCENTIVE];

    public const string FREQUENCY_EVERY = 'EVERY';

    public const string FREQUENCY_FIRST = 'FIRST';

    public const string FREQUENCY_SECOND = 'SECOND';

    /** @var list<string> */
    public const array FREQUENCIES = [self::FREQUENCY_EVERY, self::FREQUENCY_FIRST, self::FREQUENCY_SECOND];

    /** The largest peso figure a line takes, inside its DECIMAL(12,2) columns. */
    public const float AMOUNT_CAP = 99_999_999.0;

    private function __construct(
        public string $kind,
        public float $amount,
        public ?float $total,
        public string $frequency,
        public string $startsOn,
        public ?string $endsOn,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $error = self::error($data);
        if ($error !== null) {
            throw new InvalidArgumentException($error);
        }

        return new self(
            (string) $data['kind'],
            Money::round($data['amount']),
            $data['total'] === null ? null : Money::round($data['total']),
            (string) $data['frequency'],
            (string) $data['starts_on'],
            $data['ends_on'] === null ? null : (string) $data['ends_on'],
        );
    }

    /** @return array{kind: string, amount: float, total: ?float, frequency: string, starts_on: string, ends_on: ?string} */
    public function toArray(): array
    {
        return ['kind' => $this->kind, 'amount' => $this->amount, 'total' => $this->total, 'frequency' => $this->frequency, 'starts_on' => $this->startsOn, 'ends_on' => $this->endsOn];
    }

    /** Why a line's figures cannot be used, or null. */
    public static function error(mixed $data): ?string
    {
        if (! is_array($data)) {
            return 'The recurring line is missing.';
        }
        foreach (['kind', 'amount', 'total', 'frequency', 'starts_on', 'ends_on'] as $key) {
            if (! array_key_exists($key, $data)) {
                return "The recurring line is incomplete ({$key}).";
            }
        }
        if (! in_array($data['kind'], self::KINDS, true)) {
            return 'Choose whether it is a deduction or an incentive.';
        }
        if (! self::finite($data['amount']) || Money::round($data['amount']) <= 0.0 || (float) $data['amount'] > self::AMOUNT_CAP) {
            return 'Enter the amount per payroll, above $0.00 and up to $99,999,999.00.';
        }
        if ($data['total'] !== null) {
            if ($data['kind'] !== self::KIND_DEDUCTION) {
                return 'Only a deduction has a total to pay off; end an incentive with its last date instead.';
            }
            if (! self::finite($data['total']) || (float) $data['total'] > self::AMOUNT_CAP || Money::round($data['total']) < Money::round($data['amount'])) {
                return 'The total to pay off must be at least the amount per payroll, and up to $99,999,999.00.';
            }
        }
        if (! in_array($data['frequency'], self::FREQUENCIES, true)) {
            return 'Choose which payrolls it is on.';
        }
        if (! is_string($data['starts_on']) || ! PayrollPeriod::isPayrollDate($data['starts_on']) || ! PayrollPeriod::isCutoffStart($data['starts_on'])) {
            return 'Choose the cut-off it starts on: the 1st or the 16th of a month.';
        }
        if ($data['ends_on'] !== null && (! is_string($data['ends_on']) || ! PayrollPeriod::isPayrollDate($data['ends_on']))) {
            return 'Enter the last date it applies, or leave it open.';
        }
        if ($data['ends_on'] !== null && $data['ends_on'] < $data['starts_on']) {
            return 'The last date cannot be before the start.';
        }

        return null;
    }

    /** Whether a payroll period starting on this day is inside the line's dates. */
    public function liveOn(string $periodStart): bool
    {
        return $this->startsOn <= $periodStart && ($this->endsOn === null || $periodStart <= $this->endsOn);
    }

    /** Whether a payroll starting on this day is one it is due on. */
    public function dueOn(string $periodStart): bool
    {
        $day = (int) substr($periodStart, 8, 2);

        return match ($this->frequency) {
            self::FREQUENCY_FIRST => $day <= 15,
            self::FREQUENCY_SECOND => $day > 15,
            default => true,
        };
    }

    /** What is left of the total after what was paid, or null for an open-ended line. */
    public function left(float $paid): ?float
    {
        return $this->total === null ? null : Money::round(max(0.0, $this->total - $paid));
    }

    /** Whether nothing is left of the total. */
    public function paidOff(float $paid): bool
    {
        $left = $this->left($paid);

        return $left !== null && $left <= 0.0;
    }

    /** What one payroll proposes after what was paid: the amount, or what is left when that is less. */
    public function proposed(float $paid): float
    {
        $left = $this->left($paid);

        return $left === null ? $this->amount : Money::round(min($this->amount, $left));
    }

    private static function finite(mixed $value): bool
    {
        return (is_int($value) || is_float($value)) && is_finite((float) $value);
    }
}
