<?php

declare(strict_types=1);

namespace App\Livewire\Concerns;

use App\Events\RealtimeChange;
use Livewire\Attributes\Locked;

/**
 * Poll a stamp, not a screen.
 *
 * `wire:poll` re-runs `render()` on every tick, which is the whole cost of the
 * poll: the eDTR feed re-reads 300 rows with two eager loads and re-renders the
 * table twenty times an hour per open tab whether or not a single punch landed,
 * and the notifications page re-reads 500. The host is a shared cPanel account
 * with a small entry-process budget, so those ticks are the app's largest
 * standing load and almost all of them draw exactly what is already on screen.
 *
 * This trait splits the tick in two. The poll calls {@see pollForChanges()},
 * which reads ONE cheap aggregate — the {@see changeStamp()} each component
 * defines — and compares it against the stamp the visible HTML was built from.
 * Unchanged, it calls `skipRender()`: the round trip still happens (Livewire
 * has no way to answer without one), but no rows are read, no Blade runs and no
 * DOM diff is sent. Changed, it stores the new stamp and falls through to a
 * normal render.
 *
 * THE STAMP MUST BE CHEAPER THAN THE RENDER, and it must be an aggregate the
 * table's indexes already answer — a stamp that costs a scan has moved the cost
 * rather than removed it.
 *
 * OVER-TRIGGERING IS SAFE; UNDER-TRIGGERING IS A BUG. A stamp that moves too
 * often merely renders as often as the old poll did. A stamp that misses a
 * write leaves the screen stale until the next real interaction, so each
 * implementation states plainly which writes it watches — and, where it is
 * narrower than everything the view draws, which ones it does not.
 *
 * `$changeStamp` is `#[Locked]`: it is server-derived state, and a client that
 * could write it could pin a screen to a stamp that never matches (a render on
 * every tick, back to the old cost) or one that always matches (a screen that
 * never updates again).
 *
 * USAGE — three lines per component:
 *
 *     class LiveFeed extends Component
 *     {
 *         use WithChangeStamp;
 *
 *         protected function changeStamp(): string { ...one aggregate... }
 *
 *         public function render(): View
 *         {
 *             $this->markStampRendered();   // FIRST line of render()
 *             ...
 *         }
 *     }
 *
 *     <div wire:poll.15s="pollForChanges">
 *
 * `markStampRendered()` belongs in `render()` rather than in a `rendered()`
 * hook so that EVERY path that draws the component — mount, pagination, a
 * filter, an event listener, the poll itself — leaves the stamp describing what
 * is actually on screen. Without it the first poll after a page load would
 * always re-render once against an empty stamp, which is the exact tick this
 * trait exists to avoid.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * THE SOCKET, WHEN THERE IS ONE
 * ─────────────────────────────────────────────────────────────────────────────
 * {@see realtimeChannel()} names the private channel a change to this screen is
 * announced on. When one is configured, {@see getListeners()} registers a
 * Laravel Echo listener that calls THE SAME `pollForChanges` the timer calls.
 *
 * That is the entire integration, and it is why the socket is safe to add: the
 * broadcast is a knock with no payload ({@see RealtimeChange}), the
 * stamp is what decides whether anything is redrawn, and the component still
 * re-reads its own rows through its own scope. A knock meant for someone else
 * costs one index read. A knock that never arrives costs nothing at all,
 * because the poll is still running underneath — at
 * `bestworkflows.realtime.poll_backstop` rather than its usual cadence, but running.
 *
 * The listener is registered ONLY when `bestworkflows.realtime.enabled` is true. On a
 * host with no broadcaster keys there is no listener in the payload at all,
 * rather than one that makes Livewire warn "Laravel Echo cannot be found" into
 * every console on every page.
 */
trait WithChangeStamp
{
    /**
     * The stamp the HTML currently on screen was built from. Empty until the
     * first render, which is why the first tick after a load can never skip.
     */
    #[Locked]
    public string $changeStamp = '';

    /**
     * Per-request memo. A tick that DOES re-render calls the stamp twice —
     * once here, once from `render()` — and must still pay for one query.
     * Private, so Livewire never serialises it and it cannot outlive the
     * request that filled it.
     */
    private ?string $freshChangeStamp = null;

    /**
     * The `wire:poll` target. Read-only: it reads one aggregate and either
     * renders or does not.
     */
    public function pollForChanges(): void
    {
        if ($this->currentChangeStamp() === $this->changeStamp) {
            $this->skipRender();

            return;
        }

        // Left to fall through: Livewire renders, and render() records the new
        // stamp through markStampRendered().
    }

    /** Record that the HTML about to be sent was built from the current stamp. */
    protected function markStampRendered(): void
    {
        $this->changeStamp = $this->currentChangeStamp();
    }

    /** The stamp for THIS request, computed at most once. */
    protected function currentChangeStamp(): string
    {
        return $this->freshChangeStamp ??= $this->changeStamp();
    }

    /**
     * One cheap aggregate describing everything this component's poll needs to
     * notice. Scope it exactly as the view is scoped: a stamp read wider than
     * the rows is a render this viewer did not need, and one read narrower is a
     * change they will never see.
     */
    abstract protected function changeStamp(): string;

    /**
     * The private channel a change to this screen is announced on, for THIS
     * viewer — '' when there is nothing for them to listen to (an account with
     * no employee link watching a feed that will always be empty, or nobody
     * signed in at all). An empty name registers no listener, which is the
     * right answer rather than a subscription that would only ever be refused
     * at /broadcasting/auth.
     *
     * It must name a channel this viewer is authorised for in
     * routes/channels.php. The two are written to mirror each other, and the
     * authorisation is the one that is binding — this is a hint about what to
     * subscribe to, not a grant.
     */
    protected function realtimeChannel(): string
    {
        return '';
    }

    /**
     * Adds the Echo listener to whatever the `#[On]` attributes already
     * declared. Livewire merges this with them (SupportEvents::
     * getComponentListeners), so the local `edtr-punch-recorded` listeners and
     * this one coexist.
     *
     * `.changed` — with the leading dot — is Echo's "this is the literal event
     * name" form, matching RealtimeChange::broadcastAs() and bypassing the
     * `App.Events` namespace convention.
     *
     * @return array<string, string>
     */
    protected function getListeners(): array
    {
        $listeners = parent::getListeners();

        if (config('bestworkflows.realtime.enabled') !== true) {
            return $listeners;
        }

        $channel = $this->realtimeChannel();

        if ($channel === '') {
            return $listeners;
        }

        $listeners['echo-private:'.$channel.',.changed'] = 'pollForChanges';

        return $listeners;
    }
}
