<?php

declare(strict_types=1);

namespace App\Livewire\Leaves;

use App\Domain\Enums\AuditEvent;
use App\Domain\Enums\ModuleCode;
use App\Livewire\Concerns\WithSorting;
use App\Livewire\Concerns\WithTableFilters;
use App\Models\LeaveTravelRequest;
use App\Models\LeaveType;
use App\Models\Notification;
use App\Models\User;
use App\Support\Access;
use App\Support\AuditService;
use App\Support\EmployeeName;
use Carbon\CarbonImmutable;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use Throwable;

/**
 * Leave Management — the port of leaves-page.tsx + leave-detail-dialog.tsx.
 *
 * One component owns both the table and the detail dialog, exactly as the
 * source page owned the dialog it rendered. Filtering, sorting and pagination
 * run over the full scoped list in PHP, mirroring the source's client-side
 * `useMemo` pipeline — including its quirks: `sort()` then `reverse()` for
 * descending (which puts blanks first on desc), and a search needle matched
 * against "code + employee name + description" as one string.
 *
 * Review is the port of POST /api/leave-requests/[id]/review — the actor
 * checks, the self-review refusal and the status-guarded UPDATE all live in
 * {@see self::review()}. Filing and withdrawing belong to My Portal, not here.
 */
#[Layout('layouts.app')]
#[Title('Leave Management')]
class Index extends Component
{
    use WithSorting;
    use WithTableFilters;

    /**
     * The status filter's rows (leaves-page.tsx:28-33), in order.
     *
     * @var array<string, string>
     */
    public const array STATUSES = [
        'PENDING' => 'Pending',
        'APPROVED' => 'Approved',
        'REJECTED' => 'Rejected',
        'WITHDRAWN' => 'Withdrawn',
    ];

    /** Status filter. Plain filter state, deliberately unlocked. */
    public string $status = 'ALL';

    /** Description filter — matched against the resolved description text. */
    public string $description = 'ALL';

    /** Day-type filter: ALL, WHOLE_DAY or HALF_DAY. */
    public string $dayType = 'ALL';

    /**
     * The open request's id. Id-bearing → #[Locked]; it is only ever set by
     * {@see self::show()}, which re-checks the id against the actor's row
     * visibility first.
     */
    #[Locked]
    public ?string $openId = null;

    /**
     * Whether the detail modal is showing. Unlocked on purpose: flipping it
     * reveals nothing — the modal renders only the already-validated $openId.
     */
    public bool $detailOpen = false;

    public function mount(): void
    {
        // useSort<SortKey>("id") — leaves-page.tsx:58.
        $this->sortKey = 'id';
    }

    /** @return list<string> */
    protected function sortableKeys(): array
    {
        return ['id', 'employee', 'date', 'description', 'dayType'];
    }

    // The three selects reset to page 1 (leaves-page.tsx:194, 211, 228);
    // the search box does not — the clamp handles a shrunken list.
    public function updatedStatus(): void
    {
        $this->resetPage();
    }

    public function updatedDescription(): void
    {
        $this->resetPage();
    }

    public function updatedDayType(): void
    {
        $this->resetPage();
    }

    /** Closing the modal (esc, backdrop, close button) forgets the open row. */
    public function updatedDetailOpen(mixed $value): void
    {
        if (! $value) {
            $this->openId = null;
        }
    }

    /** Open a request's detail — the row click of leaves-page.tsx:280. */
    public function show(string $id): void
    {
        $visible = LeaveTravelRequest::query()
            ->toEmployee(app(Access::class), 'employee_id', ModuleCode::LEAVES)
            ->whereKey($id)
            ->exists();

        if (! $visible) {
            return;
        }

        $this->openId = $id;
        $this->detailOpen = true;
    }

    /**
     * Approve or reject the open request — the port of
     * POST /api/leave-requests/[id]/review.
     *
     * Approving records whether the day is paid; rejecting leaves it null.
     * Official business is approved with `is_paid = null` — the three-state
     * boolean is load-bearing (see the LeaveTravelRequest docblock).
     */
    public function review(string $status, ?bool $isPaid = null): void
    {
        $actor = app(Access::class);

        // requireAllDataModule("LEAVES") (review/route.ts:31): route middleware
        // is not enough — this action is reachable without the page load.
        if (! $actor->mayReadAllData(ModuleCode::LEAVES)) {
            Flux::toast(text: 'Your role cannot manage organization-wide data.', variant: 'danger');

            return;
        }

        // The schema's z.enum (review/route.ts:23): anything else is refused.
        if (! in_array($status, ['APPROVED', 'REJECTED'], true) || $this->openId === null) {
            return;
        }

        $existing = LeaveTravelRequest::query()->whereKey($this->openId)->first();

        if ($existing === null) {
            Flux::toast(text: 'That request no longer exists.', variant: 'danger');

            return;
        }

        // The reviewer must not be the person who filed it (review/route.ts:39-41).
        if ($actor->employeeId !== null && $existing->employee_id === $actor->employeeId) {
            Flux::toast(text: 'You cannot review your own request.', variant: 'danger');

            return;
        }

        if ($existing->status !== 'PENDING') {
            Flux::toast(text: 'That request has already been reviewed.', variant: 'danger');

            return;
        }

        $isOb = $existing->request_type === LeaveLabels::OFFICIAL_BUSINESS;

        if ($status === 'APPROVED' && ! $isOb && $isPaid === null) {
            Flux::toast(text: 'Choose whether approved leave is with or without pay.', variant: 'danger');

            return;
        }

        $normalizedPaid = ($status === 'REJECTED' || $isOb) ? null : $isPaid;

        // `status = 'PENDING'` in the WHERE is the real guard (review/route.ts:53-56):
        // two reviewers racing both pass the read above, but only one UPDATE
        // matches — the loser gets the same message instead of silently
        // overwriting the first decision. The reference's demo store had the
        // check-then-update race; its API route fixed it exactly this way, and
        // this is the fixed behaviour, ported.
        $won = LeaveTravelRequest::query()
            ->whereKey($existing->id)
            ->where('status', 'PENDING')
            ->update([
                'status' => $status,
                'is_paid' => $normalizedPaid,
                // Actor column: from the session-derived Access, never the request.
                'reviewed_by' => (int) $actor->userId,
                // UTC, at the DATETIME(3) precision the column declares — a raw
                // builder update bypasses the model's $dateFormat.
                'reviewed_at' => CarbonImmutable::now('UTC')->format('Y-m-d H:i:s.v'),
            ]);

        if ($won === 0) {
            Flux::toast(text: 'That request has already been reviewed.', variant: 'danger');

            return;
        }

        AuditService::record(
            $actor,
            $status === 'APPROVED' ? AuditEvent::REQUEST_APPROVED : AuditEvent::REQUEST_REJECTED,
            'leave_travel_requests',
            $existing->id,
            $existing->code.' '.strtolower($status),
        );

        // notifyEmployee (review/route.ts:75-85): the addressee is derived from
        // the record, never sent by the client.
        $this->notifyEmployee($existing->employee_id, [
            'kind' => 'LEAVE_REVIEWED',
            'title' => $existing->code.' '.strtolower($status),
            'body' => $status !== 'APPROVED' || $isOb
                ? null
                : ($normalizedPaid ? 'Approved with pay' : 'Approved without pay'),
            'href' => '/portal',
        ]);

        // The page's outcome sentence (leaves-page.tsx:131-139, 162).
        $outcome = $status === 'REJECTED'
            ? 'rejected'
            : ($isOb
                ? 'approved as official business'
                : ($normalizedPaid ? 'approved with pay' : 'approved without pay'));

        Flux::toast(text: "Request {$outcome}.", variant: 'success');

        $this->detailOpen = false;
        $this->openId = null;
    }

    public function render(): View
    {
        $actor = app(Access::class);

        /** Descriptions come from Records Management → Leave Settings. */
        $typeNames = array_values(LeaveType::query()->orderBy('name')->pluck('name')->map(static fn ($n): string => (string) $n)->all());

        // GET /api/leave-requests: scoped to the caller unless their role sees
        // all (route.ts:60-70). `reviewer` only to name whoever reviewed.
        $rows = LeaveTravelRequest::query()
            ->toEmployee($actor, 'employee_id', ModuleCode::LEAVES)
            ->with(['employee', 'reviewer'])
            ->orderByDesc('created_at')
            ->get();

        /** @var array<string, string> $described row id => resolved description */
        $described = [];

        foreach ($rows as $row) {
            $described[$row->id] = LeaveLabels::describe($row->request_type, $typeNames);
        }

        // Every description in use, plus the ones HR has configured
        // (leaves-page.tsx:70-77). Business travel is filed as official
        // business, so its Leave Settings row would be an option nothing
        // ever matches.
        $names = ['Official Business' => true];

        foreach ($typeNames as $name) {
            if (preg_match('/business|travel/i', $name) !== 1) {
                $names[$name] = true;
            }
        }

        foreach ($described as $label) {
            $names[$label] = true;
        }

        $descriptions = array_keys($names);
        usort($descriptions, static fn (string $a, string $b): int => strcasecmp($a, $b));

        // The filter pipeline (leaves-page.tsx:79-88).
        $needle = mb_strtolower(trim($this->search));

        $visible = $rows->filter(function (LeaveTravelRequest $r) use ($described, $needle): bool {
            if ($this->status !== 'ALL' && $r->status !== $this->status) {
                return false;
            }

            if ($this->description !== 'ALL' && $described[$r->id] !== $this->description) {
                return false;
            }

            if ($this->dayType !== 'ALL' && ($r->day_type ?? 'WHOLE_DAY') !== $this->dayType) {
                return false;
            }

            if ($needle === '') {
                return true;
            }

            $employee = $r->employee;
            $name = EmployeeName::display($employee);

            return str_contains(mb_strtolower("{$r->code} {$name} {$described[$r->id]}"), $needle);
        })->values();

        // The sort (leaves-page.tsx:90-107) — comparator then a whole-list
        // reverse() for descending, exactly as the source did it.
        $sorted = $visible->sort(function (LeaveTravelRequest $a, LeaveTravelRequest $b) use ($described): int {
            return match ($this->sortKey) {
                'employee' => LeaveLabels::compareText($a->employee?->last_name, $b->employee?->last_name),
                'date' => LeaveLabels::compareText($a->start_date->format('Y-m-d'), $b->start_date->format('Y-m-d'))
                    ?: LeaveLabels::compareText($a->end_date->format('Y-m-d'), $b->end_date->format('Y-m-d')),
                'description' => LeaveLabels::compareText($described[$a->id], $described[$b->id]),
                'dayType' => LeaveLabels::compareText(
                    LeaveLabels::dayTypeLabel($a->day_type),
                    LeaveLabels::dayTypeLabel($b->day_type),
                ),
                default => LeaveLabels::compareText($a->code, $b->code),
            };
        })->values();

        if ($this->sortDir === 'desc') {
            $sorted = $sorted->reverse()->values();
        }

        $paginator = $this->paginateClamped($sorted);

        // The open row comes from the SCOPED list, like `rows.find` (page:111).
        $open = $this->openId !== null ? $rows->firstWhere('id', $this->openId) : null;

        return view('livewire.leaves.index', [
            'rows' => $paginator,
            'described' => $described,
            'descriptions' => $descriptions,
            'open' => $open,
            'openDescription' => $open !== null
                ? ($described[$open->id] ?? LeaveLabels::describe($open->request_type, $typeNames))
                : '',
            'reviewerName' => $open?->reviewer?->name,
        ]);
    }

    /**
     * Port of `notifyEmployee` (src/lib/api/notify.ts:44-57): tells whoever
     * holds the login attached to an employee. An employee with no login is
     * simply not told. Fire-and-forget — the review really did happen, and
     * failing it because the bell did not ring would be the worse lie.
     *
     * @param  array{kind: string, title: string, body: ?string, href: string}  $entry
     */
    private function notifyEmployee(string $employeeId, array $entry): void
    {
        try {
            $accountIds = User::query()
                ->where('employee_id', $employeeId)
                ->where('is_active', true)
                ->pluck('id');

            foreach ($accountIds as $accountId) {
                $notification = new Notification;
                $notification->fill([
                    'kind' => $entry['kind'],
                    'title' => $entry['title'],
                    'body' => $entry['body'],
                    'href' => $entry['href'],
                ]);
                // The addressee is server-assigned, never mass-assigned.
                $notification->user_id = (int) $accountId;
                $notification->save();
            }
        } catch (Throwable $e) {
            Log::error('[notify] write failed', ['error' => $e->getMessage()]);
        }
    }
}
