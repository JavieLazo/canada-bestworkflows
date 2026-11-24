<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Domain\Canada;

use InvalidArgumentException;

/**
 * The figures the Canada Revenue Agency publishes for source deductions, as
 * they stand from one date: Canada Pension Plan, Employment Insurance, and
 * the federal and provincial income tax rates, thresholds and credits.
 *
 * The agency issues them in its payroll deductions formulas guide for each
 * 1 January, and again for 1 July when a government changes a rate mid-year,
 * so a set is always "in force from" a date and a payroll reads the set in
 * force on its pay date. The sets shipped here are the published ones;
 * nothing is derived or projected, and a date before the first set is
 * refused rather than priced on figures that were never in force for it.
 *
 * Quebec is not covered: it has its own pension plan, parental insurance and
 * tax authority.
 */
final class DeductionRates
{
    /**
     * Each set is keyed by the date it takes effect. `brackets` rows are
     * [income from, rate, constant]: the constant is the tax the higher rate
     * would overcharge on the income below its threshold.
     *
     * @var array<string, array<string, mixed>>
     */
    public const PUBLISHED = [
        '2026-01-01' => [
            'pension' => [
                'rate' => 0.0595, 'base_rate' => 0.0495, 'exemption' => 3500.00,
                'ceiling' => 74600.00, 'max_contribution' => 4230.45, 'max_base_contribution' => 3519.45,
                'second_rate' => 0.04, 'second_ceiling' => 85000.00, 'max_second_contribution' => 416.00,
            ],
            'insurance' => ['rate' => 0.0163, 'max_insurable' => 68900.00, 'max_premium' => 1123.07, 'employer_multiple' => 1.4],
            'federal' => [
                'brackets' => [[0.0, 0.14, 0.0], [58523.0, 0.205, 3804.0], [117045.0, 0.26, 10241.0], [181440.0, 0.29, 15685.0], [258482.0, 0.33, 26024.0]],
                'lowest_rate' => 0.14,
                'basic_amount' => ['max' => 16452.0, 'min' => 14829.0, 'reduce_from' => 181440.0, 'reduce_to' => 258482.0],
                'employment_amount' => 1501.0,
            ],
            'provinces' => [
                'AB' => [
                    'brackets' => [[0.0, 0.08, 0.0], [61200.0, 0.10, 1224.0], [154259.0, 0.12, 4309.0], [185111.0, 0.13, 6160.0], [246813.0, 0.14, 8628.0], [370220.0, 0.15, 12331.0]],
                    'lowest_rate' => 0.08,
                    'basic_amount' => 22769.0,
                    'supplemental_credit' => ['over' => 4896.00, 'rate' => 0.25],
                ],
            ],
        ],
        '2027-01-01' => [
            'pension' => [
                'rate' => 0.0575, 'base_rate' => 0.0475, 'exemption' => 3500.00,
                'ceiling' => 76700.00, 'max_contribution' => 4209.00, 'max_base_contribution' => 3477.00,
                'second_rate' => 0.04, 'second_ceiling' => 87400.00, 'max_second_contribution' => 428.00,
            ],
            'insurance' => ['rate' => 0.0164, 'max_insurable' => 70800.00, 'max_premium' => 1161.12, 'employer_multiple' => 1.4],
            'federal' => [
                'brackets' => [[0.0, 0.14, 0.0], [60044.0, 0.205, 3903.0], [120088.0, 0.26, 10508.0], [186157.0, 0.29, 16092.0], [265203.0, 0.33, 26701.0]],
                'lowest_rate' => 0.14,
                'basic_amount' => ['max' => 16879.0, 'min' => 15214.0, 'reduce_from' => 186157.0, 'reduce_to' => 265203.0],
                'employment_amount' => 1540.0,
            ],
            'provinces' => [
                'AB' => [
                    'brackets' => [[0.0, 0.08, 0.0], [62424.0, 0.10, 1248.0], [157344.0, 0.12, 4395.0], [188813.0, 0.13, 6283.0], [251750.0, 0.14, 8801.0], [377625.0, 0.15, 12577.0]],
                    'lowest_rate' => 0.08,
                    'basic_amount' => 23225.0,
                    'supplemental_credit' => ['over' => 4993.92, 'rate' => 0.25],
                ],
            ],
        ],
    ];

    /**
     * @param  array{rate: float, base_rate: float, exemption: float, ceiling: float, max_contribution: float, max_base_contribution: float, second_rate: float, second_ceiling: float, max_second_contribution: float}  $pension
     * @param  array{rate: float, max_insurable: float, max_premium: float, employer_multiple: float}  $insurance
     * @param  array{brackets: list<array{float, float, float}>, lowest_rate: float, basic_amount: array{max: float, min: float, reduce_from: float, reduce_to: float}, employment_amount: float}  $federal
     * @param  array<string, array{brackets: list<array{float, float, float}>, lowest_rate: float, basic_amount: float, supplemental_credit: array{over: float, rate: float}|null}>  $provinces
     */
    private function __construct(
        public readonly string $effectiveFrom,
        public readonly array $pension,
        public readonly array $insurance,
        public readonly array $federal,
        public readonly array $provinces,
    ) {}

    /**
     * The published set in force on a day, `Y-m-d`: the latest one that has
     * taken effect by then.
     */
    public static function inForceOn(string $date): self
    {
        $found = null;
        foreach (array_keys(self::PUBLISHED) as $from) {
            if ($from <= $date && ($found === null || $from > $found)) {
                $found = $from;
            }
        }

        if ($found === null) {
            throw new InvalidArgumentException("No deduction rates are in force on {$date}. The earliest set starts on ".min(array_keys(self::PUBLISHED)).'.');
        }

        return self::fromArray($found, self::PUBLISHED[$found]);
    }

    /**
     * Every published set, the earliest first.
     *
     * @return list<self>
     */
    public static function published(): array
    {
        $dates = array_keys(self::PUBLISHED);
        sort($dates, SORT_STRING);

        return array_map(static fn (string $from): self => self::fromArray($from, self::PUBLISHED[$from]), $dates);
    }

    /** The set that takes over from this one, or null while none is published. */
    public function next(): ?self
    {
        foreach (self::published() as $set) {
            if ($set->effectiveFrom > $this->effectiveFrom) {
                return $set;
            }
        }

        return null;
    }

    /**
     * A set from its stored figures, read strictly: a missing or non-numeric
     * figure is refused, because a payroll priced on a guessed rate is worse
     * than one that does not run.
     *
     * @param  array<string, mixed>  $figures
     */
    public static function fromArray(string $effectiveFrom, array $figures): self
    {
        $pension = self::numbers($figures['pension'] ?? null, ['rate', 'base_rate', 'exemption', 'ceiling', 'max_contribution', 'max_base_contribution', 'second_rate', 'second_ceiling', 'max_second_contribution'], 'pension');
        $insurance = self::numbers($figures['insurance'] ?? null, ['rate', 'max_insurable', 'max_premium', 'employer_multiple'], 'insurance');

        $federalRaw = $figures['federal'] ?? null;
        if (! is_array($federalRaw)) {
            throw new InvalidArgumentException('The federal tax figures are missing.');
        }
        $federal = [
            'brackets' => self::brackets($federalRaw['brackets'] ?? null, 'federal'),
            'lowest_rate' => self::number($federalRaw['lowest_rate'] ?? null, 'federal lowest rate'),
            'basic_amount' => self::numbers($federalRaw['basic_amount'] ?? null, ['max', 'min', 'reduce_from', 'reduce_to'], 'federal basic amount'),
            'employment_amount' => self::number($federalRaw['employment_amount'] ?? null, 'federal employment amount'),
        ];

        $provincesRaw = $figures['provinces'] ?? null;
        if (! is_array($provincesRaw) || $provincesRaw === []) {
            throw new InvalidArgumentException('The provincial tax figures are missing.');
        }
        $provinces = [];
        foreach ($provincesRaw as $code => $raw) {
            if (! is_string($code) || ! is_array($raw)) {
                throw new InvalidArgumentException('A provincial tax entry is not readable.');
            }
            $supplemental = $raw['supplemental_credit'] ?? null;
            $provinces[$code] = [
                'brackets' => self::brackets($raw['brackets'] ?? null, $code),
                'lowest_rate' => self::number($raw['lowest_rate'] ?? null, "{$code} lowest rate"),
                'basic_amount' => self::number($raw['basic_amount'] ?? null, "{$code} basic amount"),
                'supplemental_credit' => $supplemental === null ? null : self::numbers($supplemental, ['over', 'rate'], "{$code} supplemental credit"),
            ];
        }

        /** @var array{rate: float, base_rate: float, exemption: float, ceiling: float, max_contribution: float, max_base_contribution: float, second_rate: float, second_ceiling: float, max_second_contribution: float} $pension */
        /** @var array{rate: float, max_insurable: float, max_premium: float, employer_multiple: float} $insurance */
        /** @var array{brackets: list<array{float, float, float}>, lowest_rate: float, basic_amount: array{max: float, min: float, reduce_from: float, reduce_to: float}, employment_amount: float} $federal */
        /** @var array<string, array{brackets: list<array{float, float, float}>, lowest_rate: float, basic_amount: float, supplemental_credit: array{over: float, rate: float}|null}> $provinces */
        return new self($effectiveFrom, $pension, $insurance, $federal, $provinces);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['pension' => $this->pension, 'insurance' => $this->insurance, 'federal' => $this->federal, 'provinces' => $this->provinces];
    }

    /** Whether this set carries a province's tax figures. */
    public function covers(string $province): bool
    {
        return isset($this->provinces[$province]);
    }

    /**
     * A province's tax figures, or a refusal that names it.
     *
     * @return array{brackets: list<array{float, float, float}>, lowest_rate: float, basic_amount: float, supplemental_credit: array{over: float, rate: float}|null}
     */
    public function province(string $code): array
    {
        return $this->provinces[$code] ?? throw new InvalidArgumentException("No tax figures are set up for the province {$code}.");
    }

    /**
     * @param  list<string>  $keys
     * @return array<string, float>
     */
    private static function numbers(mixed $raw, array $keys, string $what): array
    {
        if (! is_array($raw)) {
            throw new InvalidArgumentException("The {$what} figures are missing.");
        }
        $out = [];
        foreach ($keys as $key) {
            $out[$key] = self::number($raw[$key] ?? null, "{$what} {$key}");
        }

        return $out;
    }

    private static function number(mixed $raw, string $what): float
    {
        if (! is_int($raw) && ! is_float($raw)) {
            throw new InvalidArgumentException("The {$what} figure is missing or not a number.");
        }
        $value = (float) $raw;
        if (! is_finite($value) || $value < 0.0) {
            throw new InvalidArgumentException("The {$what} figure must be zero or more.");
        }

        return $value;
    }

    /** @return list<array{float, float, float}> */
    private static function brackets(mixed $raw, string $what): array
    {
        if (! is_array($raw) || $raw === []) {
            throw new InvalidArgumentException("The {$what} tax brackets are missing.");
        }
        $out = [];
        $previous = -1.0;
        foreach ($raw as $row) {
            if (! is_array($row) || count($row) !== 3) {
                throw new InvalidArgumentException("A {$what} tax bracket is not readable.");
            }
            $bracket = [self::number($row[0] ?? null, "{$what} bracket threshold"), self::number($row[1] ?? null, "{$what} bracket rate"), self::number($row[2] ?? null, "{$what} bracket constant")];
            if ($bracket[0] <= $previous) {
                throw new InvalidArgumentException("The {$what} tax brackets must rise from the first to the last.");
            }
            $previous = $bracket[0];
            $out[] = $bracket;
        }
        if ($out[0][0] !== 0.0) {
            throw new InvalidArgumentException("The first {$what} tax bracket must start at zero.");
        }

        return $out;
    }
}
