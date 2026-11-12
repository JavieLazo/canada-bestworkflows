<?php

declare(strict_types=1);

namespace App\Modules\Timekeeping\Domain\Credits;

/**
 * The limits HR sets on converting one leave type's credits to cash, on
 * Leave Settings: the available credits an employee must hold before
 * converting any, the fewest days one request takes, the days that must
 * remain after it, the most days converted in a calendar year, the most
 * requests in a year, and the share of the available balance from which a
 * request needs a reason. A request that reaches that share is LARGE: it
 * must say why, and it may go past the yearly maximum (LeaveCashOutPolicy).
 *
 * Every limit is optional — null is "no limit", what every type starts
 * at — and every figure is HR's. Pure: the input keys are the
 * `leave_types` columns.
 */
final readonly class LeaveCashOutLimits
{
    /** @var list<string> the day limits, as the input and the columns name them */
    public const array DAY_KEYS = ['cash_out_min_balance', 'cash_out_min_days', 'cash_out_keep_days', 'cash_out_max_year'];

    public const int MAX_REQUESTS = 999;

    public function __construct(
        /** The available credits needed before converting any. */
        public ?float $minBalance = null,
        /** The fewest days one request takes. */
        public ?float $minDays = null,
        /** The days that must remain after a request. */
        public ?float $keepDays = null,
        /** The most days converted in a calendar year, a large request aside. */
        public ?float $maxPerYear = null,
        /** The most requests in a calendar year. */
        public ?int $requestsPerYear = null,
        /** The percent of the available balance (1–100) from which a request needs a reason. */
        public ?int $reasonShare = null,
    ) {}

    /** Whether no limit is set. */
    public function isNone(): bool
    {
        return $this->minBalance === null && $this->minDays === null && $this->keepDays === null
            && $this->maxPerYear === null && $this->requestsPerYear === null && $this->reasonShare === null;
    }

    /**
     * Why typed limits are refused, or null. A blank or absent field is no
     * limit.
     *
     * @param  array<string, mixed>  $input
     */
    public static function error(array $input): ?string
    {
        $labels = [
            'cash_out_min_balance' => 'Credits needed before converting',
            'cash_out_min_days' => 'Fewest days per request',
            'cash_out_keep_days' => 'Days that must remain',
            'cash_out_max_year' => 'Most days a year',
        ];
        foreach ($labels as $key => $label) {
            if (self::blank($input[$key] ?? null)) {
                continue;
            }
            $error = LeaveQuantity::error($input[$key]);
            if ($error !== null) {
                return "{$label}: {$error}";
            }
            if ((LeaveQuantity::parse($input[$key]) ?? 0.0) <= 0.0) {
                return "{$label}: enter a number of days above zero, or leave it blank for no limit.";
            }
        }
        if (! self::blank($input['cash_out_per_year'] ?? null) && self::whole($input['cash_out_per_year'], 1, self::MAX_REQUESTS) === null) {
            return 'Requests a year: enter a whole number from 1 to '.self::MAX_REQUESTS.', or leave it blank for no limit.';
        }
        if (! self::blank($input['cash_out_reason_share'] ?? null) && self::whole($input['cash_out_reason_share'], 1, 100) === null) {
            return 'Reason needed from: enter a whole percent from 1 to 100, or leave it blank to never ask.';
        }

        return null;
    }

    /**
     * The limits a validated input stands for.
     *
     * @param  array<string, mixed>  $input
     */
    public static function fromInput(array $input): self
    {
        $day = static fn (string $key): ?float => self::blank($input[$key] ?? null) ? null : LeaveQuantity::parse($input[$key]);

        return new self(
            $day('cash_out_min_balance'),
            $day('cash_out_min_days'),
            $day('cash_out_keep_days'),
            $day('cash_out_max_year'),
            self::blank($input['cash_out_per_year'] ?? null) ? null : self::whole($input['cash_out_per_year'], 1, self::MAX_REQUESTS),
            self::blank($input['cash_out_reason_share'] ?? null) ? null : self::whole($input['cash_out_reason_share'], 1, 100),
        );
    }

    /**
     * The limits as stored columns read back: a value out of range is no
     * limit.
     */
    public static function fromColumns(mixed $minBalance, mixed $minDays, mixed $keepDays, mixed $maxPerYear, mixed $requestsPerYear, mixed $reasonShare): self
    {
        $day = static fn (mixed $value): ?float => is_numeric($value) && (float) $value > 0.0 ? LeaveQuantity::normalize((float) $value) : null;

        return new self(
            $day($minBalance), $day($minDays), $day($keepDays), $day($maxPerYear),
            self::whole($requestsPerYear, 1, self::MAX_REQUESTS),
            self::whole($reasonShare, 1, 100),
        );
    }

    /**
     * The columns on `leave_types`.
     *
     * @return array<string, float|int|null>
     */
    public function toArray(): array
    {
        return [
            'cash_out_min_balance' => $this->minBalance,
            'cash_out_min_days' => $this->minDays,
            'cash_out_keep_days' => $this->keepDays,
            'cash_out_max_year' => $this->maxPerYear,
            'cash_out_per_year' => $this->requestsPerYear,
            'cash_out_reason_share' => $this->reasonShare,
        ];
    }

    /**
     * The form's strings: '' for no limit.
     *
     * @return array<string, string>
     */
    public function toForm(): array
    {
        return array_map(static fn (float|int|null $value): string => match (true) {
            $value === null => '',
            is_int($value) => (string) $value,
            default => LeaveQuantity::format($value),
        }, $this->toArray());
    }

    /**
     * The days from which a request out of `$available` needs a reason, or
     * null when no reason is ever asked.
     */
    public function reasonFrom(float $available): ?float
    {
        if ($this->reasonShare === null || $available <= 0.0) {
            return null;
        }

        return LeaveQuantity::normalize($available * $this->reasonShare / 100);
    }

    /**
     * The limits in plain sentences, one each, for the employee and the
     * settings list: "Hold at least 15 days of credits before converting".
     *
     * @return list<string>
     */
    public function lines(): array
    {
        $days = static fn (float $value): string => LeaveQuantity::format($value).' '.($value === 1.0 ? 'day' : 'days');
        $lines = [];
        if ($this->minBalance !== null) {
            $lines[] = 'Hold at least '.$days($this->minBalance).' of credits before converting';
        }
        if ($this->minDays !== null) {
            $lines[] = 'Convert at least '.$days($this->minDays).' at a time';
        }
        if ($this->keepDays !== null) {
            $lines[] = 'Keep at least '.$days($this->keepDays).' after converting';
        }
        if ($this->maxPerYear !== null) {
            $lines[] = 'Up to '.$days($this->maxPerYear).' a year';
        }
        if ($this->requestsPerYear !== null) {
            $lines[] = $this->requestsPerYear === 1 ? 'Once a year' : 'Up to '.$this->requestsPerYear.' requests a year';
        }
        if ($this->reasonShare !== null) {
            $lines[] = 'A reason for '.($this->reasonShare === 50 ? 'half' : $this->reasonShare.'%').' of the balance or more'
                .($this->maxPerYear === null ? '' : ', which may go past the yearly limit');
        }

        return $lines;
    }

    /** "at least 10 days a request, up to 30 days a year" for an audit sentence; '' when no limit is set. */
    public function describe(): string
    {
        return strtolower(implode(', ', $this->lines()));
    }

    private static function blank(mixed $value): bool
    {
        return $value === null || (is_string($value) && trim($value) === '');
    }

    /** A whole number within the bounds, or null. */
    private static function whole(mixed $value, int $min, int $max): ?int
    {
        if (is_int($value)) {
            $number = $value;
        } elseif (is_string($value) && preg_match('/\A\d{1,3}\z/', trim($value)) === 1) {
            $number = (int) trim($value);
        } elseif (is_float($value) && floor($value) === $value) {
            $number = (int) $value;
        } else {
            return null;
        }

        return $number >= $min && $number <= $max ? $number : null;
    }
}
