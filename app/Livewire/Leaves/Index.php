<?php

declare(strict_types=1);

namespace App\Livewire\Leaves;

use App\Domain\Enums\ModuleCode;
use App\Livewire\Concerns\WithSorting;
use App\Livewire\Concerns\WithTableFilters;
use App\Livewire\Leaves\Support\LeaveRows;
use App\Modules\Timekeeping\Application\Commands\CancelAbsence;
use App\Modules\Timekeeping\Application\Commands\FileAbsence;
use App\Modules\Timekeeping\Application\Commands\ReviewAbsence;
use App\Modules\Timekeeping\Application\LeaveBalances;
use App\Modules\Timekeeping\Application\LeaveHalves;
use App\Modules\Timekeeping\Application\Queries\AbsenceRecords;
use App\Modules\Timekeeping\Application\Queries\LeaveCreditRecords;
use App\Modules\Timekeeping\Application\Queries\ReferenceData;
use App\Modules\Timekeeping\Contracts\Views\LeaveBalanceView;
use App\Modules\Timekeeping\Contracts\Views\LeaveTravelRequestView as LeaveTravelRequest;
use App\Modules\Timekeeping\Contracts\Views\LeaveTypeView;
use App\Modules\Timekeeping\Domain\Absence\AbsencePolicy;
use App\Modules\Timekeeping\Domain\Absence\LeaveCreditPolicy;
use App\Modules\Workforce\Contracts\OperationalDirectory;
use App\Support\Access;
use App\Support\HrisFormat;
use App\Support\ManilaClock;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Database\QueryException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use RuntimeException;

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
 * Review delegates to Timekeeping's ReviewAbsence operation, which owns
 * authorization, self-review refusal and the status-guarded UPDATE. Portal
 * presents the filing and withdrawal operations owned by the same module.
 *
 * Requests, Leave Credits and Leave Ledger have separate tab state. The
 * credit and ledger children keep their own filters and paginator and are
 * mounted only while their tab shows. The review dialog reads the
 * open request's credit standing (LeaveCreditRecords) so the reviewer sees
 * what is left before approving with pay; it is a line of information, never
 * a refusal — balances are displayed, not enforced.
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
        'CANCELLED' => 'Cancelled',
    ];

    /** Which tab is showing. Plain UI state; anything unknown collapses back. */
    public string $tab = 'requests';

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

    /**
     * Selected dates are drafts; CancelAbsence revalidates every date under lock.
     *
     * @var list<string>
     */
    public array $cancelDates = [];

    public string $cancelReason = '';

    public string $cancelError = '';

    /** The "File for an employee" dialog. Plain UI state. */
    public bool $fileOpen = false;

    /** The employee the manager files for, set only by the picker through employeePicked(). Id-bearing → #[Locked]. */
    #[Locked]
    public ?string $fileEmployeeId = null;

    /**
     * The request as typed: type, day type, half, dates and reason. Free
     * text and picks — no id in it, so not #[Locked]; FileAbsence
     * re-validates every field against Leave Settings and the credits.
     *
     * @var array<string, string>
     */
    public array $fileForm = ['type' => '', 'day_type' => 'WHOLE_DAY', 'half' => '', 'start' => '', 'end' => '', 'reason' => ''];

    public ?string $fileError = null;

    public function mount(): void
    {
        // useSort<SortKey>("id") — leaves-page.tsx:58.
        $this->sortKey = 'id';

        // Credit, ledger and policy deep links mount their child; unknown tabs stay
        // on requests.
        if (in_array((string) request()->query('tab', ''), ['credits', 'ledger', 'policies'], true)) {
            $this->tab = (string) request()->query('tab');
        }
    }

    /** The strip's buttons; an unknown value collapses to the request table, and a switch forgets the open row. */
    public function updatedTab(string $value): void
    {
        $this->tab = in_array($value, ['requests', 'credits', 'ledger', 'policies', 'cashouts'], true) ? $value : 'requests';
        $this->detailOpen = false;
        $this->openId = null;
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
        $visible = app(AbsenceRecords::class)->visible(app(Access::class), $id);

        if (! $visible) {
            return;
        }

        $this->openId = $id;
        $this->reset('cancelDates', 'cancelReason', 'cancelError');
        $this->detailOpen = true;
    }

    /** Management may cancel its own approved leave; elapsed dates remain protected. */
    public function cancelSelectedDates(): void
    {
        $actor = app(Access::class);
        $this->cancelError = '';
        if ($this->openId === null) {
            return;
        }
        try {
            app(CancelAbsence::class)->handle($actor, $this->openId, $this->cancelDates, $this->cancelReason);
        } catch (QueryException $e) {
            report($e);
            $this->cancelError = 'The cancellation could not be saved. Please try again.';

            return;
        } catch (RuntimeException $e) {
            $this->cancelError = $e->getMessage();

            return;
        }
        $this->reset('cancelDates', 'cancelReason');
        Flux::toast(text: 'Selected leave dates cancelled. Leave balances have been updated.', variant: 'success');
    }

    /**
     * Approve or reject the open request — the port of
     * POST /api/leave-requests/[id]/review.
     *
     * Approving records whether the day is paid; rejecting leaves it null.
     * Official business is approved with `is_paid = null` — the three-state
     * boolean is load-bearing (see the LeaveTravelRequest docblock).
     */
    /** Opens the filing dialog on today. */
    public function openFile(): void
    {
        $actor = app(Access::class);
        if (! $actor->mayReadAllData(ModuleCode::LEAVES)) {
            return;
        }
        $today = ManilaClock::todayKey();
        $this->fileForm = ['type' => '', 'day_type' => 'WHOLE_DAY', 'half' => '', 'start' => $today, 'end' => $today, 'reason' => ''];
        $this->fileEmployeeId = null;
        $this->fileError = null;
        $this->fileOpen = true;
    }

    public function updatedFileOpen(bool $value): void
    {
        if (! $value) {
            $this->fileError = null;
            $this->fileEmployeeId = null;
        }
    }

    /** The picker's choice, re-resolved against the directory before it is kept. */
    public function employeePicked(string $name, string $id): void
    {
        $actor = app(Access::class);
        if ($name !== 'file-employee' || ! $actor->mayReadAllData(ModuleCode::LEAVES)) {
            return;
        }
        $this->fileEmployeeId = app(OperationalDirectory::class)->find($actor, $id) === null ? null : $id;
    }

    /** Files the request for the picked employee through the command; past dates are allowed (HR back-files). */
    public function fileForEmployee(): void
    {
        $actor = app(Access::class);
        $employeeId = $this->fileEmployeeId;
        if (! $actor->mayReadAllData(ModuleCode::LEAVES) || ! $this->fileOpen) {
            return;
        }
        if ($employeeId === null) {
            $this->fileError = 'Choose the employee the request is for.';

            return;
        }
        try {
            app(FileAbsence::class)->forEmployee(
                $actor, $employeeId, (string) ($this->fileForm['type'] ?? ''), (string) ($this->fileForm['day_type'] ?? 'WHOLE_DAY'),
                (string) ($this->fileForm['start'] ?? ''), (string) ($this->fileForm['end'] ?? ''), (string) ($this->fileForm['reason'] ?? ''),
                ($this->fileForm['half'] ?? '') === '' ? null : (string) $this->fileForm['half'],
            );
        } catch (QueryException $e) {
            report($e);
            $this->fileError = 'The request could not be saved. Nothing was changed. Please try again.';

            return;
        } catch (RuntimeException $e) {
            $this->fileError = $e->getMessage();

            return;
        }
        $this->fileOpen = false;
        $this->fileEmployeeId = null;
        $this->fileError = null;
        Flux::toast(text: 'Request filed for approval.', variant: 'success');
    }

    public function review(string $status, ?bool $isPaid = null): void
    {
        $actor = app(Access::class);
        if (! in_array($status, ['APPROVED', 'REJECTED'], true) || $this->openId === null) {
            return;
        }
        try {
            $outcome = app(ReviewAbsence::class)->handle($actor, $this->openId, $status, $isPaid);
        } catch (QueryException $e) {
            report($e);
            Flux::toast(text: 'The review could not be saved. Please try again.', variant: 'danger');

            return;
        } catch (RuntimeException $e) {
            Flux::toast(text: $e->getMessage(), variant: 'danger');

            return;
        }
        Flux::toast(text: "Request {$outcome}.", variant: 'success');
        $this->detailOpen = false;
        $this->openId = null;
    }

    public function render(): View
    {
        $actor = app(Access::class);

        /** Descriptions come from Records Management → Leave Settings. */
        $typeNames = array_values(app(ReferenceData::class)->leaveTypes($actor)->map(static fn (LeaveTypeView $type): string => $type->name)->all());
        // What a manager may file for an employee: the leave types, business travel as official business (the portal's rule).
        $requestOptions = [];
        foreach ($typeNames as $name) {
            if (preg_match('/business|travel/i', $name) !== 1) {
                $requestOptions[] = ['value' => HrisFormat::normalizeRequestType($name), 'label' => $name];
            }
        }
        $requestOptions[] = ['value' => AbsencePolicy::OFFICIAL_BUSINESS, 'label' => 'Official Business'];
        $canFile = $actor->mayReadAllData(ModuleCode::LEAVES);

        // The balance and policy tabs read through their child components.
        if (in_array($this->tab, ['credits', 'ledger', 'policies'], true)) {
            return view('livewire.leaves.index', [
                'requestOptions' => $requestOptions,
                'canFile' => $canFile,
                'halvesReady' => LeaveHalves::ready(),
                'rows' => $this->paginateClamped([]),
                'described' => [],
                'descriptions' => [],
                'open' => null,
                'openDescription' => '',
                'reviewerName' => null,
                'openBalance' => null,
                'openNeeds' => 0.0,
                'openPaidDates' => [],
                'cancelReturned' => 0.0,
            ]);
        }

        // GET /api/leave-requests: scoped to the caller unless their role sees
        // all (route.ts:60-70). `reviewer` only to name whoever reviewed.
        $rows = app(AbsenceRecords::class)->list($actor);

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

        // The filter pipeline and sort (leaves-page.tsx:79-107): one copy,
        // shared with the CSV export.
        $sorted = LeaveRows::arrange($rows, $described, $this->search, $this->status, $this->description, $this->dayType, $this->sortKey, $this->sortDir);

        $paginator = $this->paginateClamped($sorted);

        // The open row comes from the SCOPED list, like `rows.find` (page:111).
        $open = $this->openId !== null ? $rows->firstWhere('id', $this->openId) : null;
        [$openBalance, $openNeeds] = $this->creditLine($actor, $open);

        return view('livewire.leaves.index', [
            'requestOptions' => $requestOptions,
            'canFile' => $canFile,
            'halvesReady' => LeaveHalves::ready(),
            'rows' => $paginator,
            'described' => $described,
            'descriptions' => $descriptions,
            'open' => $open,
            'openDescription' => $open !== null
                ? ($described[$open->id] ?? LeaveLabels::describe($open->request_type, $typeNames))
                : '',
            'reviewerName' => $open?->reviewer?->name,
            'openBalance' => $openBalance,
            'openNeeds' => $openNeeds,
            // A pending request's dates a posted payslip already covers.
            'openPaidDates' => $open !== null ? app(AbsenceRecords::class)->paidDates($actor, $open) : [],
            // What the cancel dialog's ticked dates would give back.
            'cancelReturned' => $this->returnedCredits($open, array_values(array_filter($this->cancelDates, 'is_string'))),
        ]);
    }

    /**
     * The review dialog's credits line: the open request's standing against
     * the credit of its type in the year it STARTS, and what it costs. Only
     * for a request that draws on a credit — pending leave (its pay is not
     * decided yet, so it may), or leave already approved with pay (its days
     * are inside `used`) — and only when the type has a credit row or is a
     * creditable Leave Settings type; official business, rejected, withdrawn
     * and without-pay rows get no line. Read only while a dialog is open, so
     * the list itself costs nothing more.
     *
     * @return array{0: LeaveBalanceView|null, 1: float}
     */
    private function creditLine(Access $actor, ?LeaveTravelRequest $open): array
    {
        if ($open === null) {
            return [null, 0.0];
        }
        $counts = $open->status === 'PENDING'
            ? LeaveCreditPolicy::wouldConsume($open->request_type, $open->is_paid)
            : LeaveCreditPolicy::consumes($open->status, $open->request_type, $open->is_paid);
        if (! $counts) {
            return [null, 0.0];
        }

        // The year the request draws on and what it costs (LeaveBalances):
        // frozen on an approved row; for a pending one, the live year of its
        // start and the scheduled working days it would be charged today.
        $start = $open->start_date->format('Y-m-d');
        if ($open->status === 'PENDING') {
            $year = LeaveBalances::liveYear($open->employee_id, $start);
            $needs = app(LeaveBalances::class)->cost($open->employee_id, $open->day_type, $start, $open->end_date->format('Y-m-d'))['days'];
        } else {
            $year = $open->credits_year ?? LeaveCreditPolicy::yearOf($start);
            $needs = $open->credit_days ?? LeaveCreditPolicy::coveredDays($open->day_type, $start, $open->end_date->format('Y-m-d'), $open->cancelled_dates);
        }
        $rows = app(LeaveCreditRecords::class)->balances($actor, $open->employee_id, $year);
        $key = LeaveCreditPolicy::resolveKey(
            $open->request_type,
            array_map(static fn (LeaveBalanceView $row): string => $row->request_type, $rows),
        );
        foreach ($rows as $row) {
            if ($row->request_type === $key) {
                return [$row, $needs];
            }
        }

        return [null, 0.0];
    }

    /**
     * The credits the selected cancellation dates give back: the ones the
     * approval counted (its frozen working days, or every active calendar
     * day of an older row), at the request's whole/half-day value.
     *
     * @param  list<string>  $selected
     */
    private function returnedCredits(?LeaveTravelRequest $open, array $selected): float
    {
        if ($open === null || ! LeaveCreditPolicy::wouldConsume($open->request_type, $open->is_paid)) {
            return 0.0;
        }
        $counted = $open->credit_dates ?? LeaveCreditPolicy::activeDays($open->start_date->format('Y-m-d'), $open->end_date->format('Y-m-d'), $open->cancelled_dates);

        return LeaveCreditPolicy::selectedDays($open->day_type, array_values(array_intersect($counted, $selected)));
    }
}
