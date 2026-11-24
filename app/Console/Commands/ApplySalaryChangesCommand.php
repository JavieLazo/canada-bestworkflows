<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Workforce\Application\SalaryRates;
use App\Support\LocalClock;
use Illuminate\Console\Command;

/**
 * `salary:apply-due` — copies every approved salary version that has taken
 * effect onto its employee's record (SalaryRates::applyDue), so a change
 * recorded for a later date becomes the record's rate on that day.
 * Idempotent: a record already at its version's rates is left alone, so a
 * cron that fires every half hour is safe. Non-interactive, no arguments,
 * the result on stdout.
 */
final class ApplySalaryChangesCommand extends Command
{
    protected $signature = 'salary:apply-due';

    protected $description = 'Apply the approved salary versions that have taken effect to the employee records';

    public function handle(): int
    {
        $applied = SalaryRates::applyDue(LocalClock::todayKey());
        $this->info(sprintf('Salary versions applied to %d employee records.', count($applied)));

        return self::SUCCESS;
    }
}
