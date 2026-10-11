<?php

declare(strict_types=1);

namespace App\Domain\Payroll;

use InvalidArgumentException;

/**
 * The figures the four statutory tables compute from — SSS, PhilHealth,
 * Pag-IBIG and the BIR withholding brackets — as ONE immutable value the
 * engine is handed, instead of constants written into Statutory.
 *
 * Every figure keeps the exact shape of the operation that reads it, so a
 * schedule built from today's values performs the same float operations
 * as the constants did and the golden statutory vectors stay bit for bit
 * (GoldenVectorTest runs them through `builtIn()`, and the migration-seeded
 * rows are replayed through `fromArrays()` by StatutoryTablesTest):
 *
 *  - SSS: the salary credit is `floor((salary + offset) / step) * step`,
 *    clamped to [msc_min, msc_max]; the employee pays `ee_rate` of it and
 *    the employer `er_rate` of it plus the work-injury fee, `ec_low` while
 *    the credit is at or under `ec_threshold`, else `ec_high`.
 *  - PhilHealth: each side pays its own rate of the salary clamped to
 *    [floor, cap]. The rates are stored PER SIDE (0.025), never as a
 *    premium halved, because `base * 0.025` and `base * 0.05 / 2` are not
 *    the same double.
 *  - Pag-IBIG: the fund salary is the salary capped at `cap`; the employee
 *    pays `ee_low_rate` of it when the salary is at or under `low_line`,
 *    else `ee_rate`; the employer pays `er_rate` of it.
 *  - Withholding: brackets `[over, base, rate]`, ascending, the first
 *    `over` being the amount below which nothing is withheld; the tax on an
 *    amount is `base + (amount - over) * rate` of the highest bracket it
 *    exceeds. The semi-monthly brackets price one cut-off; the annual ones
 *    price a whole year (and the annualized path, ×24 then ÷24).
 *
 * `fromArrays()` is strict: a missing, extra, coerced or out-of-range figure
 * is refused, never defaulted, so evidence and table rows are read exactly
 * as written. Pure: no clock, database or framework.
 */
final class StatutorySchedule
{
    public const string SSS = 'SSS';

    public const string PHILHEALTH = 'PHILHEALTH';

    public const string PAGIBIG = 'PAGIBIG';

    public const string WITHHOLDING_TAX = 'WITHHOLDING_TAX';

    /** @var list<string> */
    public const array KINDS = [self::SSS, self::PHILHEALTH, self::PAGIBIG, self::WITHHOLDING_TAX];

    /** @var list<string> */
    public const array SSS_KEYS = ['msc_min', 'msc_max', 'step', 'offset', 'ee_rate', 'er_rate', 'ec_threshold', 'ec_low', 'ec_high'];

    /** @var list<string> */
    public const array PHILHEALTH_KEYS = ['floor', 'cap', 'ee_rate', 'er_rate'];

    /** @var list<string> */
    public const array PAGIBIG_KEYS = ['cap', 'low_line', 'ee_low_rate', 'ee_rate', 'er_rate'];

    /** @var list<string> */
    public const array WITHHOLDING_KEYS = ['semi_monthly', 'annual'];

    /**
     * The figures the engine carried as constants: SSS Circular 2024-006's
     * 2025 schedule, PhilHealth's 5% shared equally on PHP 10,000–100,000,
     * Pag-IBIG Circular 460, and the BIR tables in force since 2023.
     *
     * @var array<string, array<string, mixed>>
     */
    public const array BUILT_IN = [
        self::SSS => ['msc_min' => 5000.0, 'msc_max' => 35000.0, 'step' => 500.0, 'offset' => 250.0, 'ee_rate' => 0.05, 'er_rate' => 0.1, 'ec_threshold' => 14500.0, 'ec_low' => 10.0, 'ec_high' => 30.0],
        self::PHILHEALTH => ['floor' => 10000.0, 'cap' => 100000.0, 'ee_rate' => 0.025, 'er_rate' => 0.025],
        self::PAGIBIG => ['cap' => 10000.0, 'low_line' => 1500.0, 'ee_low_rate' => 0.01, 'ee_rate' => 0.02, 'er_rate' => 0.02],
        self::WITHHOLDING_TAX => [
            'semi_monthly' => [[10417.0, 0.0, 0.15], [16667.0, 937.5, 0.2], [33333.0, 4270.7, 0.25], [83333.0, 16770.7, 0.3], [333333.0, 91770.7, 0.35]],
            'annual' => [[250000.0, 0.0, 0.15], [400000.0, 22500.0, 0.2], [800000.0, 102500.0, 0.25], [2000000.0, 402500.0, 0.3], [8000000.0, 2202500.0, 0.35]],
        ],
    ];

    /**
     * @param  array<string, float>  $sss
     * @param  array<string, float>  $philhealth
     * @param  array<string, float>  $pagibig
     * @param  array{semi_monthly: list<array{0: float, 1: float, 2: float}>, annual: list<array{0: float, 1: float, 2: float}>}  $withholding
     */
    private function __construct(
        public readonly array $sss,
        public readonly array $philhealth,
        public readonly array $pagibig,
        public readonly array $withholding,
    ) {}

    public static function builtIn(): self
    {
        return self::fromArrays(self::BUILT_IN);
    }

    /**
     * Strictly from the four kinds' figures, keyed by kind.
     *
     * @param  array<string, mixed>  $tables
     */
    public static function fromArrays(array $tables): self
    {
        foreach (self::KINDS as $kind) {
            if (! array_key_exists($kind, $tables)) {
                throw new InvalidArgumentException("The {$kind} table is missing.");
            }
            $error = self::error($kind, $tables[$kind]);
            if ($error !== null) {
                throw new InvalidArgumentException($error);
            }
        }
        if (count($tables) !== count(self::KINDS)) {
            throw new InvalidArgumentException('Unknown statutory table.');
        }

        return new self(
            self::figures($tables[self::SSS], self::SSS_KEYS),
            self::figures($tables[self::PHILHEALTH], self::PHILHEALTH_KEYS),
            self::figures($tables[self::PAGIBIG], self::PAGIBIG_KEYS),
            [
                'semi_monthly' => self::brackets($tables[self::WITHHOLDING_TAX]['semi_monthly']),
                'annual' => self::brackets($tables[self::WITHHOLDING_TAX]['annual']),
            ],
        );
    }

    /**
     * The schedule with ONE kind's figures replaced — what a settings page
     * previews with, the other three kinds staying as given.
     *
     * @param  array<string, mixed>  $figures
     */
    public function with(string $kind, array $figures): self
    {
        $tables = $this->toArray();
        $tables[$kind] = $figures;

        return self::fromArrays($tables);
    }

    /**
     * The four kinds' figures, exactly as `fromArrays()` accepts them.
     *
     * @return array<string, array<string, mixed>>
     */
    public function toArray(): array
    {
        return [
            self::SSS => $this->sss,
            self::PHILHEALTH => $this->philhealth,
            self::PAGIBIG => $this->pagibig,
            self::WITHHOLDING_TAX => $this->withholding,
        ];
    }

    /**
     * Why one kind's figures cannot be used, or null. Each figure must be a
     * finite number (int or float, never a string); a rate lies in [0, 1];
     * a floor stays under its cap; brackets ascend. The validation a settings
     * page shows and the evidence restore refuses on are this one rule.
     *
     * @return list<string>
     */
    public static function keys(string $kind): array
    {
        return match ($kind) {
            self::SSS => self::SSS_KEYS,
            self::PHILHEALTH => self::PHILHEALTH_KEYS,
            self::PAGIBIG => self::PAGIBIG_KEYS,
            self::WITHHOLDING_TAX => self::WITHHOLDING_KEYS,
            default => throw new InvalidArgumentException('Unknown statutory table.'),
        };
    }

    public static function isKind(string $kind): bool
    {
        return in_array($kind, self::KINDS, true);
    }

    public static function error(string $kind, mixed $figures): ?string
    {
        if (! self::isKind($kind)) {
            return 'Unknown statutory table.';
        }
        if (! is_array($figures)) {
            return "The {$kind} figures are missing.";
        }
        $keys = self::keys($kind);
        $given = array_map('strval', array_keys($figures));
        if (array_diff($keys, $given) !== [] || array_diff($given, $keys) !== []) {
            return "The {$kind} figures are incomplete.";
        }

        if ($kind === self::WITHHOLDING_TAX) {
            foreach (self::WITHHOLDING_KEYS as $key) {
                $error = self::bracketsError($figures[$key]);
                if ($error !== null) {
                    return $error;
                }
            }

            return null;
        }

        foreach ($keys as $key) {
            $value = $figures[$key];
            if (! self::finite($value)) {
                return "Enter a number for {$key}.";
            }
            if (str_ends_with($key, '_rate') && ((float) $value < 0.0 || (float) $value > 1.0)) {
                return "The {$key} must be between 0% and 100%.";
            }
            if (! str_ends_with($key, '_rate') && (float) $value < 0.0) {
                return "The {$key} cannot be negative.";
            }
        }

        return match ($kind) {
            self::SSS => match (true) {
                (float) $figures['step'] <= 0.0 => 'The salary credit step must be above zero.',
                (float) $figures['msc_min'] > (float) $figures['msc_max'] => 'The lowest salary credit must not exceed the highest.',
                default => null,
            },
            self::PHILHEALTH => (float) $figures['floor'] > (float) $figures['cap'] ? 'The lowest salary must not exceed the highest.' : null,
            default => null,
        };
    }

    /**
     * The semi-monthly or annual tax on an amount, from one bracket list —
     * the body of the reference's if/elseif ladder, bracket by bracket.
     *
     * @param  list<array{0: float, 1: float, 2: float}>  $brackets
     */
    public static function bracketTax(array $brackets, float $amount): float
    {
        $tax = 0.0;
        foreach ($brackets as [$over, $base, $rate]) {
            if ($amount <= $over) {
                return $tax;
            }
            $tax = $base + ($amount - $over) * $rate;
        }

        return $tax;
    }

    /**
     * @param  array<string, mixed>  $figures
     * @param  list<string>  $keys
     * @return array<string, float>
     */
    private static function figures(array $figures, array $keys): array
    {
        $out = [];
        foreach ($keys as $key) {
            $out[$key] = (float) $figures[$key];
        }

        return $out;
    }

    /**
     * @return list<array{0: float, 1: float, 2: float}>
     */
    private static function brackets(mixed $rows): array
    {
        $out = [];
        foreach ($rows as [$over, $base, $rate]) {
            $out[] = [(float) $over, (float) $base, (float) $rate];
        }

        return $out;
    }

    private static function bracketsError(mixed $rows): ?string
    {
        if (! is_array($rows) || ! array_is_list($rows) || $rows === []) {
            return 'Enter at least one tax bracket.';
        }
        $previousOver = null;
        foreach ($rows as $row) {
            if (! is_array($row) || ! array_is_list($row) || count($row) !== 3 || ! self::finite($row[0]) || ! self::finite($row[1]) || ! self::finite($row[2])) {
                return 'Each tax bracket needs an amount, a fixed tax and a rate.';
            }
            [$over, $base, $rate] = $row;
            if ((float) $over < 0.0 || (float) $base < 0.0) {
                return 'A tax bracket cannot be negative.';
            }
            if ((float) $rate < 0.0 || (float) $rate > 1.0) {
                return 'A tax rate must be between 0% and 100%.';
            }
            if ($previousOver !== null && (float) $over <= $previousOver) {
                return 'Tax brackets must rise from one to the next.';
            }
            $previousOver = (float) $over;
        }

        return null;
    }

    private static function finite(mixed $value): bool
    {
        return (is_int($value) || is_float($value)) && is_finite((float) $value);
    }
}
