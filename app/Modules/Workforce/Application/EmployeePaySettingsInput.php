<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Application;

use App\Support\Money;

/**
 * One employee's hourly rate override — the one pay setting that still
 * lives on `employee_profiles` and is edited in place, from Pay at a glance
 * on the employee's pay page — as the page types it, checked and turned
 * into the column value. The sixteen statutory settings the engine reads
 * off the same record are DATED VERSIONS now, prepared and approved under
 * Payroll (GovDeductionSettings, PayrollGovDeductionPolicy); their columns
 * stay as the baseline an employee with no approved version is still
 * priced by, and nothing writes them.
 *
 * Amounts are capped as the reference capped them (schemas.ts), inside
 * the DECIMAL(12,2) column.
 */
final class EmployeePaySettingsInput
{
    /** The column default: an employee holding it derives the hour from the day and the shift. */
    public const array DEFAULTS = [
        'hourly_rate' => 0.0,
    ];

    /** Each column as the audit row and the page name it. */
    public const array LABELS = [
        'hourly_rate' => 'Hourly rate override',
    ];

    /** The largest peso figure a setting takes (schemas.ts). */
    public const float AMOUNT_CAP = 99_999_999.0;

    public const int REASON_MAX = 160;

    private function __construct() {}

    /**
     * The form a settings page fills from stored values (an
     * EmployeePaySettingsView, or anything carrying the column).
     *
     * @return array<string, string>
     */
    public static function toForm(object $settings): array
    {
        return ['hourly_rate' => self::amountText(self::normalized($settings)['hourly_rate'])];
    }

    /**
     * The typed form as column values, or the first thing wrong with it.
     *
     * @param  array<string, mixed>  $form
     * @return array<string, float>|string
     */
    public static function columns(array $form): array|string
    {
        $hourly = self::amount($form['hourly_rate'] ?? '');
        if ($hourly === null) {
            return 'Hourly rate override: enter an amount up to ₱99,999,999.00, or leave it blank to use the shift.';
        }

        return ['hourly_rate' => $hourly];
    }

    /** Why a reason cannot be used, or null. */
    public static function reasonError(string $reason): ?string
    {
        $reason = trim($reason);
        if ($reason === '') {
            return 'Enter the reason for this change.';
        }

        return mb_strlen($reason) > self::REASON_MAX ? 'The reason must be at most 160 characters.' : null;
    }

    /**
     * The columns whose stored values differ from the standard computation.
     *
     * @return list<string>
     */
    public static function customized(object $settings): array
    {
        return self::changed(self::normalized($settings), self::DEFAULTS);
    }

    /**
     * The keys whose value differs between two column sets.
     *
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @return list<string>
     */
    public static function changed(array $before, array $after): array
    {
        $keys = [];
        foreach (array_keys(self::DEFAULTS) as $key) {
            if (Money::round($before[$key] ?? null) !== Money::round($after[$key] ?? null)) {
                $keys[] = $key;
            }
        }

        return $keys;
    }

    /**
     * Stored values in the shape `columns()` returns: rounded pesos.
     *
     * @return array<string, float>
     */
    public static function normalized(object $settings): array
    {
        $values = [];
        foreach (array_keys(self::DEFAULTS) as $key) {
            $values[$key] = Money::round($settings->{$key} ?? null);
        }

        return $values;
    }

    /** A typed peso figure: blank is 0, anything unreadable, negative or over the cap is null. */
    private static function amount(mixed $text): ?float
    {
        $text = is_string($text) ? str_replace(',', '', trim($text)) : $text;
        if ($text === '' || $text === null) {
            return 0.0;
        }
        if (! is_numeric($text) || ! is_finite((float) $text)) {
            return null;
        }
        $value = Money::round((float) $text);

        return $value < 0.0 || $value > self::AMOUNT_CAP ? null : $value;
    }

    /** A stored figure as the form shows it: blank for zero. */
    private static function amountText(mixed $amount): string
    {
        $value = is_numeric($amount) ? (float) $amount : 0.0;

        return $value > 0.0 ? rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.') : '';
    }
}
