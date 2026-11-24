<?php

declare(strict_types=1);

namespace App\Livewire\Records\Support;

use App\Modules\Workforce\Contracts\Views\EmployeePaySettingsView;

/**
 * Employee Pay Settings as the list words them: one short phrase for the
 * employee's rates on the percentage deductions and one for the hourly rate.
 */
final class PaySettingsText
{
    private function __construct() {}

    /**
     * The Employee Pay Settings list's one cell for the percentage
     * deductions: "Listed rates", or "Own rates" when the employee has a
     * rate of their own on any of them.
     *
     * @param  list<string>  $customized  the settings keys that differ from the standard ones
     */
    public static function summary(array $customized): string
    {
        return in_array('percent_rates', $customized, true) ? 'Own rates' : 'Listed rates';
    }

    /**
     * Every settings key the summary rests on, for marking a summary that differs.
     *
     * @return list<string>
     */
    public static function summaryKeys(): array
    {
        return ['percent_rates'];
    }

    /** "From the shift", or the override per hour. */
    public static function hourly(EmployeePaySettingsView $settings): string
    {
        return (float) $settings->hourly_rate > 0.0 ? Hris::money($settings->hourly_rate).' an hour' : 'From the shift';
    }
}
