<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Domain\Adjustments;

use App\Support\Money;

/**
 * What an adjustment category says about the lines made under it, and what
 * an adjustment line must satisfy. A category is an EARNING (added to the
 * payslip: TAXABLE joins the taxable pay, NOT_TAXED joins gross after the
 * tax, like a benefit that is not income) or a DEDUCTION (taken after the
 * tax, like a named deduction). An adjustment's amount is a positive peso
 * figure up to the money maximum; its reason is required.
 *
 * Pure: a constants class with the checks the commands and the evidence
 * share. Pesos through Money::round() only.
 */
final class AdjustmentRule
{
    public const string KIND_EARNING = 'EARNING';

    public const string KIND_DEDUCTION = 'DEDUCTION';

    /** @var list<string> */
    public const array KINDS = [self::KIND_EARNING, self::KIND_DEDUCTION];

    public const string TAX_TAXABLE = 'TAXABLE';

    public const string TAX_NOT_TAXED = 'NOT_TAXED';

    /** @var list<string> */
    public const array TAXES = [self::TAX_TAXABLE, self::TAX_NOT_TAXED];

    public const string SOURCE_MANUAL = 'MANUAL';

    public const string PENDING = 'PENDING';

    public const string APPROVED = 'APPROVED';

    public const string REJECTED = 'REJECTED';

    public const string POSTED = 'POSTED';

    /** @var list<string> */
    public const array STATUSES = [self::PENDING, self::APPROVED, self::REJECTED, self::POSTED];

    public const float AMOUNT_CAP = 99_999_999.0;

    public const int REASON_MAX = 160;

    public const int REFERENCE_MAX = 120;

    private function __construct() {}

    /** Why a category's kind and tax cannot be used together, or null. */
    public static function categoryError(string $kind, ?string $tax): ?string
    {
        if (! in_array($kind, self::KINDS, true)) {
            return 'Choose whether the category adds to pay or deducts from it.';
        }
        if ($kind === self::KIND_EARNING && ! in_array($tax, self::TAXES, true)) {
            return 'Choose whether an earning under this category is taxed.';
        }
        if ($kind === self::KIND_DEDUCTION && $tax !== null) {
            return 'A deduction is taken after the tax; it has no tax category.';
        }

        return null;
    }

    /** The amount as the line carries it, or null when it is not a positive peso figure within the cap. */
    public static function amount(mixed $value): ?float
    {
        if (is_string($value)) {
            $value = str_replace(',', '', trim($value));
        }
        if (! is_numeric($value) || ! is_finite((float) $value)) {
            return null;
        }
        $amount = Money::round((float) $value);

        return $amount <= 0.0 || $amount > self::AMOUNT_CAP ? null : $amount;
    }

    /** "₱1,234.50", for an audit sentence. */
    public static function peso(float $amount): string
    {
        return '₱'.number_format(Money::round($amount), 2, '.', ',');
    }

    /** Why a line's evidence entry cannot be read, or null. */
    public static function lineError(mixed $line): ?string
    {
        if (! is_array($line)) {
            return 'The adjustment is missing.';
        }
        foreach (['id', 'category_id', 'code', 'name', 'kind', 'tax', 'amount', 'reason'] as $key) {
            if (! array_key_exists($key, $line)) {
                return "The adjustment is incomplete ({$key}).";
            }
        }
        if (! in_array($line['kind'], self::KINDS, true)) {
            return 'The adjustment names no kind.';
        }
        if (self::categoryError((string) $line['kind'], $line['tax']) !== null) {
            return 'The adjustment names no tax category.';
        }
        // A whole peso figure comes back from JSON as an int: readable, and exact at two decimals.
        $amount = $line['amount'];
        if ((! is_int($amount) && ! is_float($amount)) || self::amount($amount) === null || Money::round((float) $amount) !== (float) $amount) {
            return 'The adjustment carries no readable amount.';
        }

        return null;
    }
}
