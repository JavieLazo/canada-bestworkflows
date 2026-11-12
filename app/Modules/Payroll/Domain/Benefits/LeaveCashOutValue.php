<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Domain\Benefits;

use App\Support\Money;

/**
 * What one converted day of leave credits pays, as the Incentives & Bonuses
 * type that pays cash conversions sets it: FACTOR — the monthly rate times
 * the days times the factor (0.0481927 to start) — or DAILY, the payroll
 * daily rate times the days. An employee with no monthly rate is paid at
 * the daily rate whichever is set.
 *
 * Terminal leave is priced the same way from the HIGHEST salary on record
 * instead of the salary in force when the payroll starts; the caller
 * passes those rates.
 *
 * DELIBERATE DIVERGENCE: the reference engine has no cash conversion of
 * leave credits, so nothing in it prices one; the golden vectors carry
 * none.
 *
 * Pure: no clock, database or framework. Pesos through Money::round() only,
 * once per line.
 */
final readonly class LeaveCashOutValue
{
    public const string FACTOR = 'FACTOR';

    public const string DAILY = 'DAILY';

    public const array METHODS = [self::FACTOR, self::DAILY];

    /** @var array<string, string> */
    public const array LABELS = [self::FACTOR => 'Monthly rate × factor', self::DAILY => 'Daily rate'];

    /** The factor every paying type starts with. */
    public const float DEFAULT_FACTOR = 0.0481927;

    /** The factor's stored places (DECIMAL(10,7)). */
    public const int FACTOR_DECIMALS = 7;

    public function __construct(
        public string $method,
        /** The FACTOR method's factor; null for DAILY. */
        public ?float $factor,
    ) {}

    /** The daily rate: also the value of evidence below schema 24 and of a catalog without the columns. */
    public static function daily(): self
    {
        return new self(self::DAILY, null);
    }

    /** What a paying type starts with. */
    public static function standard(): self
    {
        return new self(self::FACTOR, self::DEFAULT_FACTOR);
    }

    /** Why a typed value is refused, or null. The factor is read only for FACTOR. */
    public static function error(mixed $method, mixed $factor): ?string
    {
        if (! in_array($method, self::METHODS, true)) {
            return 'Choose how a converted day is valued: the monthly rate times a factor, or the daily rate.';
        }
        if ($method === self::FACTOR) {
            $text = is_string($factor) ? trim($factor) : (is_float($factor) || is_int($factor) ? (string) $factor : '');
            if (preg_match('/\A0?\.\d{1,'.self::FACTOR_DECIMALS.'}\z|\A1(\.0{1,'.self::FACTOR_DECIMALS.'})?\z/', $text) !== 1 || (float) $text <= 0.0) {
                return 'Enter the factor as a number above 0 and at most 1, with up to '.self::FACTOR_DECIMALS.' decimals (0.0481927).';
            }
        }

        return null;
    }

    /** The value a validated input stands for. */
    public static function fromInput(string $method, mixed $factor): self
    {
        return $method === self::FACTOR ? new self(self::FACTOR, (float) (is_string($factor) ? trim($factor) : $factor)) : self::daily();
    }

    /** The stored columns read back; anything out of shape is the standard value. */
    public static function fromColumns(mixed $method, mixed $factor): self
    {
        if ($method === self::DAILY) {
            return self::daily();
        }

        return is_numeric($factor) && (float) $factor > 0.0 && (float) $factor <= 1.0 ? new self(self::FACTOR, (float) $factor) : self::standard();
    }

    /**
     * The pesos `$days` pay: monthly × days × factor under FACTOR with a
     * monthly rate above zero, else days × the daily rate. Rounded once.
     */
    public function amount(float $days, float $monthly, float $daily): float
    {
        if ($this->method === self::FACTOR && $this->factor !== null && $monthly > 0.0) {
            return Money::round($monthly * $days * $this->factor);
        }

        return Money::round($days * $daily);
    }

    /** "Monthly rate × 0.0481927" / "Daily rate", for the settings and an audit sentence. */
    public function text(): string
    {
        return $this->method === self::FACTOR && $this->factor !== null
            ? 'Monthly rate × '.rtrim(rtrim(number_format($this->factor, self::FACTOR_DECIMALS, '.', ''), '0'), '.')
            : self::LABELS[self::DAILY];
    }
}
