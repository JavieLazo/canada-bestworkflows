<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Platform\Notifications\SweepReminders;
use Illuminate\Console\Command;

/**
 * The server-side reminder sweep (GAP-07 in the port plan).
 *
 * The reference ran this in the browser (useReminders.ts) on page load, with a
 * documented bug: the hook gated the whole sweep on /api/tasks AND
 * /api/projects both succeeding, so the HR-aimed rules (LEAVE_PENDING,
 * DOCUMENT_EXPIRING, PROBATION_ENDING) silently never fired for an HR Officer
 * without PROJECTS/TASKS. Running the sweep from cron removes both the bug and
 * the browser dependency: reminders run in the scheduled window without opening the
 * app. Module owners now authorize each fact before the pure rules receive it,
 * including own-record scope and permission to review a pending request.
 *
 * Idempotent by construction: build() emits deterministic dedupe keys,
 * bounded candidate queries pre-filter keys already recorded, and the
 * (user_id, dedupe_key) unique index makes a concurrent second sweep a no-op.
 * Safe on a host whose cron minimum is coarser or finer than the schedule.
 */
final class RemindersSweepCommand extends Command
{
    protected $signature = 'reminders:sweep';

    protected $description = 'Build and record reminder notifications for every active account';

    public function handle(): int
    {
        $result = app(SweepReminders::class)->handle();
        $this->info("Swept {$result['swept']} accounts, wrote {$result['written']} reminders.");
        $this->line("Attempted {$result['attempted']}; duplicates {$result['duplicates']}; failed {$result['failed']}; errors {$result['errors']}.");

        return $result['failed'] === 0 && $result['errors'] === 0 ? self::SUCCESS : self::FAILURE;
    }
}
