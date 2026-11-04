<?php

declare(strict_types=1);

namespace App\Livewire\Records\Support;

use App\Modules\Payroll\Application\Queries\RecurringLineRecords;
use App\Modules\Payroll\Domain\Recurring\RecurringLine;

/** Recurring lines as Employee Pay Settings words them: which payrolls, and the status of each line today. */
final class RecurringLineText
{
    /** @var array<string, string> */
    public const array FREQUENCIES = [
        RecurringLine::FREQUENCY_EVERY => 'Every payroll',
        RecurringLine::FREQUENCY_FIRST => 'First cut-off of the month',
        RecurringLine::FREQUENCY_SECOND => 'Second cut-off of the month',
    ];

    /** @var array<string, string> */
    public const array STATUSES = [
        RecurringLineRecords::UPCOMING => 'Upcoming',
        RecurringLineRecords::ACTIVE => 'Active',
        RecurringLineRecords::PAID_OFF => 'Paid off',
        RecurringLineRecords::ENDED => 'Ended',
    ];

    /** @var array<string, string> */
    public const array STATUS_PILLS = [
        RecurringLineRecords::UPCOMING => 'pill pill-caution',
        RecurringLineRecords::ACTIVE => 'pill pill-success',
        RecurringLineRecords::PAID_OFF => 'pill pill-primary',
        RecurringLineRecords::ENDED => 'pill pill-muted',
    ];

    private function __construct() {}
}
