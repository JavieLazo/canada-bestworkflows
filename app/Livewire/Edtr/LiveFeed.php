<?php

declare(strict_types=1);

namespace App\Livewire\Edtr;

use App\Domain\Enums\ModuleCode;
use App\Livewire\Concerns\WithChangeStamp;
use App\Livewire\Concerns\WithTableFilters;
use App\Models\AttendanceLog;
use App\Modules\Timekeeping\Application\Queries\PunchFeed;
use App\Support\Access;
use App\Support\Realtime;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Punches as they land — the port of copems/src/components/LiveFeed.tsx.
 * Sits beside the punch station on both eDTR and My Portal.
 *
 * Newest at the top, oldest at the bottom; two punches sharing a timestamp
 * are broken by id so the order never shuffles between renders.
 *
 * Realtime degrades to POLLING: wire:poll at COPEMS_POLL_FEED seconds
 * (default 15) — THE ONLY 15-second poll in the app; everything else polls at
 * 60. A punch recorded in this tab also refreshes immediately through the
 * `edtr-punch-recorded` event, the way the reference's cache update landed
 * without waiting for a refetch.
 *
 * WHAT IT DOES NOT SHOW: a punch typed into the DTR correction dialog
 * ({@see AttendanceLog::SOURCE_CORRECTION}). A correction is back-dated by
 * definition — somebody saying what happened on a day already gone — so it is
 * not news, and in a list ordered by `logged_at` it would not even arrive at
 * the top: it would appear silently somewhere down the page, next to punches
 * from that day. Source MANUAL is still shown on purpose. That is an admin
 * recording a punch at the station, which IS happening now.
 *
 * The poll goes through {@see WithChangeStamp}: a tick reads one scoped
 * COUNT/MAX over `attendance_logs` and re-renders ONLY when it moves. This is
 * the app's most expensive poll — 300 rows and two eager loads every 15
 * seconds per open tab — and on a quiet afternoon, without the stamp, every
 * one of those ticks would redraw an unchanged table.
 *
 * WHAT THE STAMP WATCHES: punches, and nothing else. A row renamed underneath
 * the feed — an employee's name, position or photo, a site's name — does not
 * move the stamp and lands on the next real render (a page load, a punch, a
 * pagination click). That is deliberate: those columns change by hand, months
 * apart, and watching them would cost a join on every tick to catch an edit
 * the editor is not looking at this screen to see.
 *
 * Scoping: rows come through ScopesToActor with the attendance modules
 * (EDTR, PAYROLL — api/attendance/route.ts:25), so a viewer without the
 * widened role sees only their own punches and an employee-less account sees
 * none, exactly as the parent's `visibleLogs` filter behaved. The stamp runs
 * through the SAME scope, so it never reports a change to a viewer who would
 * not be shown it.
 */
class LiveFeed extends Component
{
    use WithChangeStamp;
    use WithTableFilters;

    #[Locked]
    public string $title = 'Live Feed';

    /** A narrower self-service view that keeps the feed inside the portal card. */
    #[Locked]
    public bool $compact = false;

    /** The newest-N window the feed shows (useAttendance's default 300). */
    #[Locked]
    public int $limit = 300;

    /** A punch just recorded on this page: repaint without waiting for the poll. */
    #[On('edtr-punch-recorded')]
    public function punchRecorded(): void
    {
        // Re-render is the refresh.
    }

    public function render(): View
    {
        $this->markStampRendered();

        $actor = Access::forActor(auth()->user());

        $logs = app(PunchFeed::class)->list($actor, $this->limit);

        $pollSeconds = self::pollSeconds();

        return view('livewire.edtr.live-feed', [
            'total' => $logs->count(),
            'rows' => $this->paginateClamped($logs),
            'linksToDtr' => $actor->hasModule(ModuleCode::EDTR),
            'pollSeconds' => $pollSeconds,
        ]);
    }

    /**
     * How often the timer ticks. COPEMS_POLL_FEED (15s) while the poll is the
     * only way news arrives; `realtime.poll_backstop` (60s) once a punch
     * announces itself over the socket, where the timer is not the
     * messenger but the safety net under a socket that may have dropped.
     *
     * Shared with App\Livewire\Portal\MyPunches, which is the same feed.
     */
    public static function pollSeconds(): int
    {
        $seconds = config('copems.realtime.enabled') === true
            ? (int) config('copems.realtime.poll_backstop', 60)
            : (int) config('copems.poll_feed', 15);

        return $seconds > 0 ? $seconds : 15;
    }

    /**
     * The feed listens on the channel that matches how widely it is scoped:
     * everyone's punches for an organisation-wide viewer, their own employee's
     * for a self-only one. An account with no employee link draws an empty feed
     * forever and listens to nothing.
     *
     * The `match` is the same shape as ScopesToActor's, against the same
     * modules, so the channel can never be wider than the rows.
     */
    protected function realtimeChannel(): string
    {
        $actor = Access::forActor(auth()->user());

        return match ($actor->rowVisibility(ModuleCode::EDTR, ModuleCode::PAYROLL)) {
            Access::ROWS_ALL => Realtime::allPunches(),
            Access::ROWS_OWN => Realtime::employeePunches($actor->ownEmployeeId()),
            Access::ROWS_NONE => '',
        };
    }

    /**
     * COUNT, newest punch, newest row id — one aggregate over the scoped
     * punches.
     *
     * All three are load-bearing, and the third is the one that is easy to
     * talk yourself out of. `AttendanceLog` documents itself as append-only
     * at the station, which would make COUNT alone sufficient, but the day
     * correction (CorrectPunches::apply) DELETES the punches whose slot
     * changed and INSERTS their replacements, leaving the untouched ones in
     * place. Retype one slot and the count is unmoved; retype the middle of a
     * day and MAX(logged_at) is unmoved too. MAX(id) catches it, because the
     * replacement rows are minted NOW and a ULID sorts by the millisecond it
     * was minted in — and a correction that only deletes moves COUNT.
     *
     * The stamp is deliberately WIDER than the rows: it counts corrections,
     * which the feed does not show. Narrowing it would mean reading `source`,
     * which neither index carries, and so would turn an index-only aggregate
     * into a row lookup on every tick of every open tab — to notice an event
     * somebody performs by hand a few times a month. Over-triggering is safe
     * by design ({@see WithChangeStamp}): a correction costs one redraw of
     * rows that did not change. Under-triggering would be the bug.
     *
     * Every one of them is answered by an index the table already carries —
     * `attlog_logged_idx` and the primary key for an organisation-wide viewer,
     * `attlog_employee_logged_idx` for a self-only one — so the tick costs an
     * index read instead of 300 rows, two eager loads and a table.
     */
    protected function changeStamp(): string
    {
        return app(PunchFeed::class)->stamp(Access::forActor(auth()->user()));
    }
}
