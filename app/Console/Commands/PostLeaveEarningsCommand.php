<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Timekeeping\Application\PostLeaveEarnings;
use App\Support\ManilaClock;
use Illuminate\Console\Command;

/**
 * `leave:post-earnings` — posts every employee's leave earning for the
 * months whose posting day has passed (PostLeaveEarnings), as the
 * schedule's "System (scheduled)" actor. Idempotent: the unique key on
 * (employee, month, leave type) makes a second run write nothing, so a cron
 * that fires every half hour is safe. Non-interactive, no arguments, the
 * result on stdout.
 */
final class PostLeaveEarningsCommand extends Command
{
    protected $signature = 'leave:post-earnings';

    protected $description = 'Post the leave credits each employee earned for the months that have ended, by their approved policy';

    public function handle(): int
    {
        $result = app(PostLeaveEarnings::class)->run(ManilaClock::todayKey());
        $this->info(sprintf('Leave earning posted for %d month-employees (%d employees).', $result['posted'], $result['employees']));
        foreach ($result['skipped'] as $line) {
            $this->line('Skipped '.$line);
        }

        return self::SUCCESS;
    }
}
