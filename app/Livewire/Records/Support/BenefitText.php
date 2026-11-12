<?php

declare(strict_types=1);

namespace App\Livewire\Records\Support;

use App\Modules\Payroll\Domain\Benefits\BenefitRule;

/**
 * How the Incentives & Bonuses table, its CSV and the payroll workspace's
 * incentives dialog describe a benefit's rule: the amount, how often it is
 * due, the tax category and who qualifies, as short phrases. Presentation
 * only; the figures come from the catalog row as the view carries them.
 */
final class BenefitText
{
    /** The amount-rule choices, value => label, as the dialog and the CSV name them. */
    public const array AMOUNT_TYPES = [
        BenefitRule::AMOUNT_FIXED => 'Fixed amount',
        BenefitRule::AMOUNT_PERCENT => 'Percent of monthly salary',
        BenefitRule::AMOUNT_MONTH => "One month's basic salary",
        BenefitRule::AMOUNT_DAILY => 'Amount per day worked',
        BenefitRule::AMOUNT_TYPED => 'Typed per payroll',
    ];

    /** The frequency choices. */
    public const array FREQUENCIES = [
        BenefitRule::FREQUENCY_EVERY => 'Every payroll',
        BenefitRule::FREQUENCY_FIRST => 'Monthly · 1st cut-off',
        BenefitRule::FREQUENCY_SECOND => 'Monthly · 2nd cut-off',
        BenefitRule::FREQUENCY_YEARLY => 'Yearly, in a month',
        BenefitRule::FREQUENCY_ONCE => 'One-time',
    ];

    /** The proration choices. */
    public const array PRORATIONS = [
        BenefitRule::PRORATION_NONE => 'None',
        BenefitRule::PRORATION_MONTHS => 'By months of service in the year',
        BenefitRule::PRORATION_DAYS => 'By paid days',
    ];

    /** The tax categories, as the screens name them; the CSV keeps its own words (ImportIncentiveTypes::TAX). */
    public const array TAXES = [
        BenefitRule::TAX_TAXABLE => 'Taxable',
        BenefitRule::TAX_EXEMPT => 'Tax-exempt',
        BenefitRule::TAX_DE_MINIMIS => 'De minimis',
        BenefitRule::TAX_NOT_INCOME => 'Not compensation',
    ];

    /** The pill tier of each category. */
    public const array TAX_PILLS = [
        BenefitRule::TAX_TAXABLE => 'pill pill-caution',
        BenefitRule::TAX_EXEMPT => 'pill pill-muted',
        BenefitRule::TAX_DE_MINIMIS => 'pill pill-primary',
        BenefitRule::TAX_NOT_INCOME => 'pill pill-success',
    ];

    public const array MONTHS = [1 => 'January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];

    /** The amount rule in words: "₱2,000.00", "3% of salary", "One month's basic", "₱150.00 per day", "Typed per payroll". */
    public static function amount(object $type): string
    {
        $amount = is_numeric($type->amount ?? null) ? (float) $type->amount : null;

        return match ($type->amount_type ?? BenefitRule::AMOUNT_TYPED) {
            BenefitRule::AMOUNT_FIXED => $amount === null ? '—' : Hris::peso($amount),
            BenefitRule::AMOUNT_PERCENT => $amount === null ? '—' : Hris::moneyText($amount).'% of salary',
            BenefitRule::AMOUNT_MONTH => "One month's basic",
            BenefitRule::AMOUNT_DAILY => $amount === null ? '—' : Hris::peso($amount).' per day',
            default => 'Typed per payroll',
        };
    }

    /**
     * How often, in words: "Every payroll", "Monthly · 1st cut-off",
     * "Yearly · May", "One-time". An amount typed per payroll has no schedule
     * of its own — it is paid on whichever payroll it is typed on — so it
     * reads "When typed", never "One-time".
     */
    public static function frequency(object $type): string
    {
        $frequency = $type->frequency ?? BenefitRule::FREQUENCY_ONCE;
        if ($frequency === BenefitRule::FREQUENCY_ONCE && ($type->amount_type ?? BenefitRule::AMOUNT_TYPED) === BenefitRule::AMOUNT_TYPED) {
            return 'When typed';
        }
        if ($frequency === BenefitRule::FREQUENCY_YEARLY) {
            return 'Yearly · '.(self::MONTHS[(int) ($type->month ?? 0)] ?? '—');
        }

        return self::FREQUENCIES[$frequency] ?? 'One-time';
    }

    /** The category of a row, the flag standing in where the category is absent. */
    public static function tax(object $type): string
    {
        $tax = $type->tax ?? null;
        if (is_string($tax) && isset(self::TAXES[$tax])) {
            return $tax;
        }

        return ($type->is_taxable ?? true) ? BenefitRule::TAX_TAXABLE : BenefitRule::TAX_EXEMPT;
    }

    /**
     * Who qualifies, in words: "All employees", "Regular, Probationary",
     * "… · 4 months of service".
     *
     * @param  array<string, string>  $statusNames  Employment list id => name
     */
    public static function who(object $type, array $statusNames): string
    {
        $statuses = $type->statuses ?? null;
        if (is_array($statuses) && $statuses !== []) {
            $names = array_map(static fn (mixed $id): string => $statusNames[(string) $id] ?? 'a removed status', $statuses);
            $text = implode(', ', $names);
        } else {
            $text = 'All employees';
        }
        $months = (int) ($type->min_service_months ?? 0);
        if ($months > 0) {
            $text .= ' · '.$months.($months === 1 ? ' month' : ' months').' of service';
        }

        return $text;
    }
}
