<?php

declare(strict_types=1);

namespace App\Livewire\Portal;

use App\Livewire\Concerns\WithChangeStamp;
use App\Livewire\Concerns\WithTableFilters;
use App\Livewire\Edtr\LiveFeed;
use App\Modules\Timekeeping\Application\Queries\PunchFeed;
use App\Support\Access;
use App\Support\Realtime;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * "My punches" — the portal's compact instance of LiveFeed
 * (portal-page.tsx:226: `<LiveFeed logs={myLogs} title="My punches" compact />`).
 *
 * A separate component rather than a reuse of the eDTR feed because the parent
 * there filtered the rows to the CALLER'S OWN before handing them over
 * (`myLogs`, portal-page.tsx:90-93) — the eDTR feed's scope alone would show
 * an all_data holder everyone's punches under a card titled "My punches".
 * The scope still runs first (an employee-less account yields zero rows, never
 * IS NULL rows), and the own-row filter only ever narrows it.
 *
 * The dated 500-row window is `useAttendance(500)` (portal-page.tsx:59).
 * Realtime degrades to the same wire:poll as the kiosk feed —
 * COPEMS_POLL_FEED seconds, 15 by default — and a punch recorded on this page
 * repaints immediately through the `edtr-punch-recorded` event the station
 * dispatches.
 *
 * The poll goes through {@see WithChangeStamp}, and here the narrowing pays
 * twice: the stamp carries the SAME own-employee filter as the rows, so a
 * kiosk busy with everyone else's punches never redraws this card. The eDTR
 * feed's note on what the stamp watches — punches, not the names and sites
 * drawn beside them — applies unchanged.
 */
class MyPunches extends Component
{
    use WithChangeStamp;
    use WithTableFilters;

    #[Locked]
    public string $title = 'My punches';

    /** The newest-N window the portal read (useAttendance's 500). */
    #[Locked]
    public int $limit = 500;

    /** A punch just recorded on this page: repaint without waiting for the poll. */
    #[On('edtr-punch-recorded')]
    public function punchRecorded(): void
    {
        // Re-render is the refresh.
    }

    public function render(): View
    {
        $this->markStampRendered();

        $actor = app(Access::class);

        $logs = app(PunchFeed::class)->list($actor, $this->limit, own: true);

        $pollSeconds = LiveFeed::pollSeconds();

        return view('livewire.portal.my-punches', [
            'total' => $logs->count(),
            'rows' => $this->paginateClamped($logs),
            'pollSeconds' => $pollSeconds,
        ]);
    }

    /**
     * Only this employee's punches are news here, so the card listens on the
     * one channel its own rows are announced on. An unlinked account draws
     * nothing and listens to nothing.
     */
    protected function realtimeChannel(): string
    {
        $actor = app(Access::class);

        return $actor->hasEmployeeLink()
            ? Realtime::employeePunches($actor->ownEmployeeId())
            : '';
    }

    /**
     * The same three-part stamp the eDTR feed reads — count, newest punch,
     * newest row id, the last of which is what notices a day correction that
     * replaced this employee's punches without changing how many there are —
     * behind the same two filters the rows are read behind: scope first,
     * own-employee second. An account with no employee link draws nothing and
     * stamps a constant, so its poll never renders again after the first tick.
     *
     * `attlog_employee_logged_idx` answers it from one range.
     */
    protected function changeStamp(): string
    {
        return app(PunchFeed::class)->stamp(app(Access::class), own: true);
    }
}
