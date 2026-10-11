<?php

declare(strict_types=1);

namespace App\Livewire\Records\Support;

use App\Modules\Payroll\Domain\DeductionCalculation;
use App\Modules\Payroll\Domain\Deductions\PercentDeduction;

/**
 * How the Payroll Deductions list and the payroll grid describe where a
 * row's figure comes from: a table, the Daily Time Record, typed per
 * employee, or a percentage of salary. Presentation only.
 */
final class DeductionText
{
    /** The base choices, value => label. */
    public const array BASES = [
        PercentDeduction::BASE_MONTHLY => 'Monthly rate',
        PercentDeduction::BASE_BASIC => 'Basic pay this payroll',
        PercentDeduction::BASE_GROSS => 'Gross pay this payroll',
    ];

    /** The frequency choices. */
    public const array FREQUENCIES = [
        PercentDeduction::FREQUENCY_EVERY => 'Every payroll',
        PercentDeduction::FREQUENCY_SECOND => 'Once a month · 2nd cut-off',
    ];

    /**
     * The rest of a percentage rule, for the Computation column's second
     * line: "2nd cut-off · cap ₱200.00 a month · before tax" — empty when
     * the rule is the plain one.
     *
     * @param  array<string, mixed>  $percent
     */
    public static function details(array $percent): string
    {
        $parts = [];
        if (($percent['frequency'] ?? null) === PercentDeduction::FREQUENCY_SECOND) {
            $parts[] = '2nd cut-off only';
        }
        if (is_numeric($percent['cap'] ?? null)) {
            $parts[] = 'cap '.Hris::peso((float) $percent['cap']).' a month per share';
        }
        if (($percent['before_tax'] ?? false) === true) {
            $parts[] = 'before tax';
        }

        return implode(' · ', $parts);
    }

    /** The Computation column: what a row's figure comes from. */
    public static function computation(object $row): string
    {
        $calculation = $row->calculation ?? null;
        if (is_string($calculation)) {
            return $calculation === DeductionCalculation::LATE_UNDERTIME ? 'Daily Time Record' : DeductionCalculation::defaultLabel($calculation).' table';
        }
        $percent = $row->percent ?? null;

        return is_array($percent) ? self::percent($percent) : 'Typed per employee';
    }

    /** The pill tier of a row's computation. */
    public static function pill(object $row): string
    {
        if (is_string($row->calculation ?? null)) {
            return 'pill pill-primary';
        }

        return is_array($row->percent ?? null) ? 'pill pill-success' : 'pill pill-muted';
    }

    /**
     * A percentage rule in words: "3% of monthly rate + 3% employer", "5% of gross pay".
     *
     * @param  array<string, mixed>  $percent
     */
    public static function percent(array $percent): string
    {
        $employee = is_numeric($percent['employee'] ?? null) ? (float) $percent['employee'] : 0.0;
        $employer = is_numeric($percent['employer'] ?? null) ? (float) $percent['employer'] : 0.0;
        $base = match ($percent['base'] ?? null) {
            PercentDeduction::BASE_BASIC => 'basic pay',
            PercentDeduction::BASE_GROSS => 'gross pay',
            default => 'monthly rate',
        };
        $text = $employee > 0.0 ? Hris::moneyText($employee).'% of '.$base : 'Employer only';
        if ($employer > 0.0) {
            $text .= $employee > 0.0 ? ' + '.Hris::moneyText($employer).'% employer' : ': '.Hris::moneyText($employer).'% of '.$base;
        }

        return $text;
    }
}
