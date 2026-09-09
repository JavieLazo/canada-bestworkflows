<?php

declare(strict_types=1);

namespace App\Domain\Payroll;

/**
 * The numbers the engine runs on, taken from Records Management -> Payroll
 * Settings -> Formulas where they are set.
 *
 * A literal port of payrollRates() and PAYROLL_DEFAULTS in
 * copems/src/lib/payroll.ts:129-192.
 *
 * The shape of the incoming formulas is structural on purpose, so this class
 * stays independent of the seed:
 *
 *     [ ['key' => 'OT', 'params' => [ ['key' => 'percentage', 'value' => 1.25], ... ]], ... ]
 */
final class PayrollRates
{
    /**
     * Only a guard for a shift that describes no working day. The hours in a
     * day come from the roster, never from here.
     */
    public const DEFAULT_WORKING_HOURS = 8.0;

    public const DEFAULT_DAYS_PER_MONTH = 24.0;

    public const DEFAULT_OT_RATE = 1.25;

    public const DEFAULT_MAX_OT_HOURS = 8.0;

    /** Night differential: 10% of the hourly rate, for at most 8 hours a night. */
    public const DEFAULT_NSD_RATE = 0.1;

    public const DEFAULT_MAX_NSD_HOURS = 8.0;

    /** A rest day pays 130% of the hourly rate, for at most a full day of them. */
    public const DEFAULT_REST_DAY_RATE = 1.3;

    public const DEFAULT_MAX_REST_DAY_HOURS = 8.0;

    /**
     * Overtime worked on a premium day is paid at the day's own rate and then at
     * 130% of it, which is how the DOLE matrix reads:
     *
     *   ordinary working day        hourly x 125%
     *   special non-working day     hourly x 130% x 130%
     *   regular holiday             hourly x 200% x 130%
     */
    public const HOLIDAY_OT_PREMIUM = 1.3;

    private function __construct(
        public readonly float $otRate,
        public readonly float $maxOtHours,
        public readonly float $daysPerMonth,
        public readonly float $nsdRate,
        public readonly float $maxNsdHours,
        public readonly float $restDayRate,
        public readonly float $maxRestDayHours,
    ) {}

    /**
     * @param  list<mixed>  $formulas
     */
    public static function fromFormulas(array $formulas = []): self
    {
        $ot = self::valuesOf($formulas, 'OT');
        $sil = self::valuesOf($formulas, 'SIL');
        $nsd = self::valuesOf($formulas, 'NSD');
        $restDay = self::valuesOf($formulas, 'REST_DAY');

        return new self(
            otRate: self::rate($ot['percentage'] ?? null, self::DEFAULT_OT_RATE),
            maxOtHours: self::rate($ot['max_ot'] ?? null, self::DEFAULT_MAX_OT_HOURS),
            daysPerMonth: self::divisor($sil['days_per_month'] ?? null, self::DEFAULT_DAYS_PER_MONTH),
            nsdRate: self::rate($nsd['percentage'] ?? null, self::DEFAULT_NSD_RATE),
            maxNsdHours: self::rate($nsd['max_nsd'] ?? null, self::DEFAULT_MAX_NSD_HOURS),
            restDayRate: self::rate($restDay['percentage'] ?? null, self::DEFAULT_REST_DAY_RATE),
            maxRestDayHours: self::rate($restDay['max_hours'] ?? null, self::DEFAULT_MAX_REST_DAY_HOURS),
        );
    }

    /**
     * @param  list<mixed>  $formulas
     * @return array<string, float>
     */
    private static function valuesOf(array $formulas, string $key): array
    {
        foreach ($formulas as $formula) {
            $fk = is_array($formula) ? ($formula['key'] ?? null) : (is_object($formula) ? ($formula->key ?? null) : null);

            if ($fk !== $key) {
                continue;
            }

            $params = is_array($formula) ? ($formula['params'] ?? []) : ($formula->params ?? []);
            $out = [];

            foreach (is_iterable($params) ? $params : [] as $param) {
                $pk = is_array($param) ? ($param['key'] ?? null) : (is_object($param) ? ($param->key ?? null) : null);
                $pv = is_array($param) ? ($param['value'] ?? null) : (is_object($param) ? ($param->value ?? null) : null);

                if (is_string($pk)) {
                    // Number(v) in the reference: non-numeric becomes NaN, which
                    // then fails the is_finite guard below and falls back.
                    $out[$pk] = is_numeric($pv) ? (float) $pv : NAN;
                }
            }

            return $out;
        }

        return [];
    }

    /** Divisors must be POSITIVE — a zero would divide by zero. */
    private static function divisor(mixed $v, float $fallback): float
    {
        return is_float($v) && is_finite($v) && $v > 0 ? $v : $fallback;
    }

    /** Rates and caps may legitimately be ZERO — only reject non-finite and negative. */
    private static function rate(mixed $v, float $fallback): float
    {
        return is_float($v) && is_finite($v) && $v >= 0 ? $v : $fallback;
    }
}
