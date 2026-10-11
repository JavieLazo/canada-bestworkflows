<?php

declare(strict_types=1);

namespace App\Platform\Notifications;

use App\Domain\Reminders\ReminderSweep;
use App\Models\Notification;
use App\Support\ManilaClock;
use Illuminate\Support\Facades\Log;
use Throwable;

/** Synchronous bounded orchestration with owner-checked scopes and stable deduplication keys. */
final class SweepReminders
{
    public function __construct(private ReminderCatalog $catalog, private NotificationRecipients $recipients) {}

    /** @return array{swept: int, attempted: int, written: int, duplicates: int, failed: int, errors: int} */
    public function handle(): array
    {
        $today = ManilaClock::todayKey();
        $result = ['swept' => 0, 'attempted' => 0, 'written' => 0, 'duplicates' => 0, 'failed' => 0, 'errors' => 0];
        foreach ($this->recipients->active() as $recipient) {
            $actor = $recipient->actor;
            if ($actor->modules === [] || $actor->userId === null) {
                continue;
            }
            $result['swept']++;
            try {
                $selected = [];
                foreach (ReminderCatalog::GROUPS as $group) {
                    foreach ($this->catalog->source($group)->candidates($actor, $today, $group) as $batch) {
                        if ($batch === []) {
                            continue;
                        }
                        $seen = array_fill_keys(Notification::query()->where('user_id', (int) $actor->userId)
                            ->whereIn('dedupe_key', array_column($batch, 'dedupeKey'))->pluck('dedupe_key')->all(), true);
                        foreach ($batch as $row) {
                            if (! isset($seen[$row['dedupeKey']])) {
                                $selected[$row['dedupeKey']] = $row;
                            }
                            if (count($selected) >= ReminderSweep::MAX_REMINDERS_PER_SWEEP) {
                                break 3;
                            }
                        }
                    }
                }
                $rows = array_values($selected);
                $result['attempted'] += count($rows);
                $outcome = Notifier::reminders($actor->userId, $rows);
                $result['written'] += $outcome['inserted'];
                foreach (['duplicates', 'failed', 'errors'] as $key) {
                    $result[$key] += $outcome[$key];
                }
            } catch (Throwable $error) {
                $result['errors']++;
                Log::error('[notify] reminder sweep failed', ['user_id' => $actor->userId, 'error' => $error->getMessage()]);
            }
        }

        return $result;
    }
}
