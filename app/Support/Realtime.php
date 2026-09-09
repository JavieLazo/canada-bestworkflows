<?php

declare(strict_types=1);

namespace App\Support;

use App\Domain\Notifications\Notifier;
use App\Events\RealtimeChange;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

use function Illuminate\Support\defer;

/**
 * The one place a change leaves this app for a browser.
 *
 * Every write that a screen is watching calls {@see announce()} with the
 * channels that care. What it does with them is the whole point of this class,
 * and each of the four steps is load-bearing:
 *
 *  1. **OFF UNLESS A BROADCASTER CAN REACH A BROWSER.** `realtime.enabled`
 *     (config/bestworkflows.php) is derived from BROADCAST_CONNECTION, so on a host
 *     with no keys this returns before doing anything at all. Nothing is
 *     dispatched, nothing is logged, and the `log` driver cannot turn a busy
 *     kiosk into a busy log file.
 *
 *  2. **AFTER THE TRANSACTION COMMITS.** `DB::afterCommit` runs the callback
 *     immediately when there is no transaction open and at commit when there
 *     is. The punch that announces itself is written inside
 *     `DB::transaction()`, and a knock sent from inside it would be a knock
 *     for a punch that could still roll back — every browser would then
 *     re-read and find nothing, or worse, find the row a moment before it
 *     vanished. This is the same stance {@see Notifier}
 *     takes on joining its caller's transaction, applied to the wire.
 *
 *  3. **AFTER THE RESPONSE IS FLUSHED.** `defer()` runs the callback on
 *     termination, so the outbound HTTPS call to the broadcaster is not in the
 *     path of the person who just punched in. It needs no queue worker, which
 *     matters: production has no shell to run one.
 *
 *  4. **IT CANNOT BREAK THE WRITE.** Every failure is swallowed and logged.
 *     A punch really was recorded; failing it because a socket vendor was
 *     unreachable would be a worse lie than a screen that waits for its poll —
 *     which is exactly what a swallowed failure degrades to, since the polls
 *     are still running underneath.
 *
 * The channels are names, not rows: see {@see RealtimeChange} for why nothing
 * about the change itself travels with it.
 */
final class Realtime
{
    /**
     * Pusher accepts at most 100 channels per trigger. A fan-out wider than
     * that is chunked rather than dropped or rejected — a 400 from the vendor
     * would silence the whole batch, including the 100 it could have taken.
     */
    private const int MAX_CHANNELS_PER_CALL = 100;

    /**
     * Knock on every listed channel once this request's write is safely
     * committed and its response is gone.
     *
     * @param  list<string>  $channels  Private channel names, no `private-`
     *                                  prefix. Empty entries are dropped; an
     *                                  empty list is a no-op, so a caller may
     *                                  pass the result of a fan-out without
     *                                  checking whether it found anybody.
     */
    public static function announce(array $channels): void
    {
        if (config('bestworkflows.realtime.enabled') !== true) {
            return;
        }

        $channels = array_values(array_unique(array_filter(
            $channels,
            static fn (string $channel): bool => $channel !== '',
        )));

        if ($channels === []) {
            return;
        }

        DB::afterCommit(static function () use ($channels): void {
            defer(static function () use ($channels): void {
                foreach (array_chunk($channels, self::MAX_CHANNELS_PER_CALL) as $chunk) {
                    try {
                        broadcast(new RealtimeChange($chunk));
                    } catch (Throwable $e) {
                        // The write stands; the screens fall back to their poll.
                        Log::warning('[realtime] announce failed', [
                            'channels' => count($chunk),
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            });
        });
    }

    /** The channel every punch for one employee is announced on. */
    public static function employeePunches(string $employeeId): string
    {
        return $employeeId === '' ? '' : 'attendance.employee.'.$employeeId;
    }

    /** The channel EVERY punch is announced on, for organisation-wide feeds. */
    public static function allPunches(): string
    {
        return 'attendance';
    }

    /** The channel one account's notifications are announced on. */
    public static function account(int|string|null $userId): string
    {
        return $userId === null || $userId === '' ? '' : 'user.'.(int) $userId;
    }
}
