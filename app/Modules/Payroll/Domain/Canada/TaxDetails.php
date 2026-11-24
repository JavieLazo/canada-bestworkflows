<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Domain\Canada;

/**
 * What one employee's source deductions depend on beside their pay: where
 * they are employed, what they claim on their personal tax credits returns,
 * and whether the pension plan and the insurance apply to them.
 *
 * A claim amount of null means the employee claims the basic personal amount
 * alone, which is what an employee who files no return gets; it follows each
 * year's published figure. A typed amount is the total the employee wrote on
 * their return and stays as typed until someone changes it.
 */
final class TaxDetails
{
    /** Every province and territory, by its two-letter code. */
    public const PROVINCES = [
        'AB' => 'Alberta', 'BC' => 'British Columbia', 'MB' => 'Manitoba', 'NB' => 'New Brunswick',
        'NL' => 'Newfoundland and Labrador', 'NS' => 'Nova Scotia', 'NT' => 'Northwest Territories', 'NU' => 'Nunavut',
        'ON' => 'Ontario', 'PE' => 'Prince Edward Island', 'QC' => 'Quebec', 'SK' => 'Saskatchewan', 'YT' => 'Yukon',
    ];

    /** The province a new employee starts in. */
    public const DEFAULT_PROVINCE = 'AB';

    /** The largest amount a claim or an extra-tax field takes, inside its DECIMAL(12,2) column. */
    public const AMOUNT_CAP = 9_999_999.99;

    public function __construct(
        public readonly string $province,
        public readonly ?float $federalClaim,
        public readonly ?float $provincialClaim,
        public readonly float $additionalTax,
        public readonly bool $deductPension,
        public readonly bool $deductInsurance,
    ) {}

    /** Where every new employee starts: the basic amounts, no extra tax, both deductions on. */
    public static function starting(): self
    {
        return new self(self::DEFAULT_PROVINCE, null, null, 0.0, true, true);
    }

    /**
     * From stored or captured figures, read leniently: a missing key is the
     * starting position's value, so evidence captured before a key existed
     * still replays.
     *
     * @param  array<string, mixed>  $row
     */
    public static function fromArray(array $row): self
    {
        $province = is_string($row['province'] ?? null) && isset(self::PROVINCES[$row['province']]) ? $row['province'] : self::DEFAULT_PROVINCE;

        return new self(
            $province,
            self::amountOrNull($row['federal_claim'] ?? null),
            self::amountOrNull($row['provincial_claim'] ?? null),
            self::amountOrNull($row['additional_tax'] ?? null) ?? 0.0,
            ($row['deduct_pension'] ?? true) !== false && ($row['deduct_pension'] ?? true) !== 0,
            ($row['deduct_insurance'] ?? true) !== false && ($row['deduct_insurance'] ?? true) !== 0,
        );
    }

    /** @return array{province: string, federal_claim: float|null, provincial_claim: float|null, additional_tax: float, deduct_pension: bool, deduct_insurance: bool} */
    public function toArray(): array
    {
        return [
            'province' => $this->province,
            'federal_claim' => $this->federalClaim,
            'provincial_claim' => $this->provincialClaim,
            'additional_tax' => $this->additionalTax,
            'deduct_pension' => $this->deductPension,
            'deduct_insurance' => $this->deductInsurance,
        ];
    }

    /** Whether these are exactly what a new employee starts with. */
    public function isStarting(): bool
    {
        return $this->toArray() === self::starting()->toArray();
    }

    /**
     * Why these details cannot be saved against a set of rates, or null.
     * The province must be one the rates carry tax figures for.
     */
    public function error(DeductionRates $rates): ?string
    {
        if (! isset(self::PROVINCES[$this->province])) {
            return 'Choose the province of employment.';
        }
        if (! $rates->covers($this->province)) {
            return self::PROVINCES[$this->province].' is not set up yet. Choose a province the system has tax tables for.';
        }
        foreach (['Federal claim amount' => $this->federalClaim, self::PROVINCES[$this->province].' claim amount' => $this->provincialClaim] as $label => $amount) {
            if ($amount !== null && ($amount < 0.0 || $amount > self::AMOUNT_CAP)) {
                return "{$label}: enter an amount from 0 to ".number_format(self::AMOUNT_CAP, 2).', or choose the basic amount.';
            }
        }
        if ($this->additionalTax < 0.0 || $this->additionalTax > self::AMOUNT_CAP) {
            return 'Extra tax each pay: enter an amount from 0 to '.number_format(self::AMOUNT_CAP, 2).', or leave it blank.';
        }

        return null;
    }

    private static function amountOrNull(mixed $raw): ?float
    {
        if ($raw === null || $raw === '') {
            return null;
        }

        return is_numeric($raw) && is_finite((float) $raw) ? (float) $raw : null;
    }
}
