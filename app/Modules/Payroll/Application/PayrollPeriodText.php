<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Application;

use Carbon\CarbonImmutable;

/** The one spelling of a payroll date in a sentence: 'd M Y', as the date fields show it. */
final class PayrollPeriodText
{
    private function __construct() {}

    public static function day(string $date): string
    {
        return CarbonImmutable::parse($date, 'UTC')->format('d M Y');
    }
}
