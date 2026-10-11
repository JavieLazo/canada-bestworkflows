<?php

declare(strict_types=1);

namespace App\Events;

use App\Livewire\Concerns\WithChangeStamp;
use App\Support\Realtime;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

/**
 * "Something you are watching moved." The only event this app broadcasts.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * A SIGNAL, NOT DATA — AND THE CLASS IS BUILT SO IT CANNOT BECOME DATA
 * ─────────────────────────────────────────────────────────────────────────────
 * {@see broadcastWith()} returns an empty array and there is no property to put
 * a row in. That is the security design, not economy:
 *
 * Every screen in this app is scoped per viewer. The eDTR feed shows one
 * account everyone's punches and the next account only their own
 * (ScopesToActor); the bell shows one account's notifications and nobody
 * else's. A channel payload is delivered identically to every subscriber, so
 * the moment a punch row travels over the socket it is being handed to whoever
 * is on the channel, under whatever scoping applied when it was PUBLISHED
 * rather than the scoping of the person READING it. There is no way to
 * per-viewer that payload, so the payload does not exist.
 *
 * What arrives instead is a knock. The Livewire listener answers it by calling
 * `pollForChanges` — the same stamp read the poll already makes
 * ({@see WithChangeStamp}) — and the component re-reads
 * its own rows through its own scope, exactly as it does on every other render.
 * Two consequences worth stating plainly:
 *
 *  1. **An over-broad knock is harmless.** Announcing a punch to a viewer who
 *     may not see it costs them one index read and no render, because their
 *     stamp did not move. So the channels can be coarse.
 *  2. **A lost knock is not a lost update.** The polls stay on as a backstop
 *     (config/copems.php `realtime.poll_backstop`), so a dropped socket
 *     degrades to the poll alone.
 *
 * ShouldBroadcastNow, not ShouldBroadcast: the queued variant needs a worker,
 * and production is a cPanel account with no shell to run one — a queued event
 * would sit in the jobs table forever. The cost of sending inline is paid
 * AFTER the response is flushed; {@see Realtime} is the only thing
 * that should construct this, and it is what arranges that.
 */
final class RealtimeChange implements ShouldBroadcastNow
{
    /**
     * @param  list<string>  $channels  Private channel names, WITHOUT the
     *                                  `private-` prefix Pusher adds.
     */
    public function __construct(private readonly array $channels) {}

    /** @return list<PrivateChannel> */
    public function broadcastOn(): array
    {
        return array_map(
            static fn (string $channel): PrivateChannel => new PrivateChannel($channel),
            $this->channels,
        );
    }

    /**
     * The wire name. Short and explicit so the client listens for `.changed`
     * and never depends on Echo's `App.Events` namespace guessing, which would
     * silently stop matching if this class ever moved.
     */
    public function broadcastAs(): string
    {
        return 'changed';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return [];
    }
}
