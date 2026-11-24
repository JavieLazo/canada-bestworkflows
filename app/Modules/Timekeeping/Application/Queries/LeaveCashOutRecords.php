<?php

declare(strict_types=1);

namespace App\Modules\Timekeeping\Application\Queries;

use App\Domain\Enums\ModuleCode;
use App\Models\LeaveCashOut;
use App\Modules\Timekeeping\Application\LeaveBalances;
use App\Modules\Timekeeping\Application\LeaveCashOuts;
use App\Modules\Timekeeping\Application\LeaveCharges;
use App\Modules\Timekeeping\Application\TerminalLeave;
use App\Modules\Timekeeping\Contracts\Views\LeaveCashOutView;
use App\Modules\Workforce\Contracts\DirectoryEmployee;
use App\Modules\Workforce\Contracts\EmployeeLeaveFacts;
use App\Modules\Workforce\Contracts\OperationalDirectory;
use App\Platform\ReadModels\Snapshot;
use App\Support\Access;
use App\Support\EmployeeName;
use App\Support\LocalClock;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * Reads of leave credit cash-outs (`leave_cash_outs`) behind the leave
 * list's scope (LEAVES): MY requests for My Portal, pinned to the session's
 * own employee on top of the scope, and the management list for Leave
 * Management. Each view carries the employee's display name and the leave
 * type's current name; nothing else about the employee. Also what MY
 * request for a type may be (its limits), and what terminal leave would pay
 * out for an employee before a leave manager files it.
 */
final class LeaveCashOutRecords
{
    public const string PAGE_NAME = 'cashOutsPage';

    /** The statuses a request moves through, for a screen that filters or branches on them. */
    public const string PENDING = LeaveCashOut::PENDING;

    public const array STATUSES = LeaveCashOut::STATUSES;

    public static function ready(): bool
    {
        return LeaveCashOuts::ready();
    }

    /** Whether terminal leave can be filed yet (TerminalLeave::ready). */
    public static function terminalReady(): bool
    {
        return TerminalLeave::ready();
    }

    /**
     * MY cash-outs, newest first; empty for an account with no employee link.
     *
     * @return Collection<int, LeaveCashOutView>
     */
    public function own(Access $actor, int $limit = 50): Collection
    {
        if (! $actor->hasEmployeeLink() || ! self::ready()) {
            return collect();
        }
        $rows = $this->scoped($actor)->where('employee_id', '=', $actor->ownEmployeeId())
            ->orderByDesc('requested_at')->orderByDesc('id')->limit($limit)->get();

        return $rows->map(fn (LeaveCashOut $row): LeaveCashOutView => $this->view($row, [], $this->typeNames()))->values();
    }

    /**
     * The management list: every request the actor may see, pending first
     * then newest, narrowed by status ('ALL' for every one) and by an
     * employee search.
     *
     * @return LengthAwarePaginator<int, LeaveCashOutView>
     */
    public function page(Access $actor, string $status, string $search, int $page, int $perPage): LengthAwarePaginator
    {
        $query = $this->scoped($actor);
        if (! self::ready()) {
            $query->whereRaw('1 = 0');
        }
        if ($status !== 'ALL') {
            $query->where('status', in_array($status, LeaveCashOut::STATUSES, true) ? $status : LeaveCashOut::PENDING);
        }
        $names = $this->employeeNames($actor, trim($search));
        if (trim($search) !== '') {
            $query->whereIn('employee_id', array_keys($names));
        }
        $query->orderByRaw("CASE WHEN status = 'PENDING' THEN 0 ELSE 1 END")->orderByDesc('requested_at')->orderByDesc('id');
        $perPage = max(1, min(100, $perPage));
        $rows = $query->paginate($perPage, ['*'], self::PAGE_NAME, max(1, $page));
        if ($rows->currentPage() > $rows->lastPage()) {
            $rows = $query->paginate($perPage, ['*'], self::PAGE_NAME, $rows->lastPage());
        }
        $types = $this->typeNames();

        return $rows->through(fn (LeaveCashOut $row): LeaveCashOutView => $this->view($row, $names, $types));
    }

    /** One request the actor may see, or null. */
    public function find(Access $actor, string $id): ?LeaveCashOutView
    {
        if (! self::ready()) {
            return null;
        }
        $row = $this->scoped($actor)->whereKey($id)->first();

        return $row === null ? null : $this->view($row, $this->employeeNames($actor, ''), $this->typeNames());
    }

    /**
     * What MY request for a leave type may be: the type's limits in
     * sentences (LeaveCashOutLimits::lines), the days from which it needs a
     * reason, and my available balance; null for an unlinked account, an
     * actor without PORTAL or LEAVES, or a type that may not be cashed out.
     *
     * @return array{lines: list<string>, reason_from: ?float, available: float}|null
     */
    public function ownTerms(Access $actor, string $token): ?array
    {
        if (! self::ready() || ! $actor->hasModule(ModuleCode::PORTAL, ModuleCode::LEAVES) || ! $actor->hasEmployeeLink()) {
            return null;
        }
        $entry = LeaveTypeCreditRecords::rules()[$token] ?? null;
        if ($entry === null || ! $entry['rules']->canCashOut) {
            return null;
        }
        $employeeId = $actor->ownEmployeeId();
        $available = LeaveBalances::available($employeeId, LeaveCharges::liveYear($employeeId, (int) substr(LocalClock::todayKey(), 0, 4)), $token);
        $limits = $entry['rules']->cashOutLimits;

        return ['lines' => $limits->lines(), 'reason_from' => $limits->reasonFrom($available), 'available' => $available];
    }

    /**
     * Terminal leave for an employee in the actor's scope, before a leave
     * manager files it (FileTerminalLeave): the name, the Date Resigned,
     * whether the record is active (an inactive employee cannot be added to
     * a payroll, so nothing would pay it), what would be paid out
     * (TerminalLeave::plan) and why it cannot be filed now. Null without
     * all-data LEAVES, before the upgrade, or for an employee the actor
     * cannot see.
     *
     * @return array{name: string, resigned: ?string, active: bool, lines: list<array{token: string, name: string, days: float}>, blocker: ?string}|null
     */
    public function terminal(Access $actor, string $employeeId): ?array
    {
        if (! $actor->mayReadAllData(ModuleCode::LEAVES) || ! TerminalLeave::ready()) {
            return null;
        }
        $employee = app(OperationalDirectory::class)->find($actor, $employeeId);
        if ($employee === null) {
            return null;
        }
        $name = EmployeeName::display($employee);
        $facts = app(EmployeeLeaveFacts::class)->forPosting([$employeeId])[$employeeId] ?? null;

        return [
            'name' => $name,
            'resigned' => $facts['date_resigned'] ?? null,
            'active' => (bool) ($facts['is_active'] ?? true),
            'lines' => TerminalLeave::plan($employeeId),
            'blocker' => TerminalLeave::blocker($employeeId, $name),
        ];
    }

    /** How many requests the actor may see are still pending. */
    public function pendingCount(Access $actor): int
    {
        if (! self::ready()) {
            return 0;
        }

        return $this->scoped($actor)->where('status', LeaveCashOut::PENDING)->count();
    }

    /** @return Builder<LeaveCashOut> */
    private function scoped(Access $actor): Builder
    {
        return LeaveCashOut::query()->toEmployee($actor, 'employee_id', ModuleCode::LEAVES);
    }

    /**
     * Display names of the employees the actor may see, narrowed by the
     * search, keyed by id.
     *
     * @return array<string, string>
     */
    private function employeeNames(Access $actor, string $search): array
    {
        $names = [];
        foreach (app(OperationalDirectory::class)->list($actor, null, $search) as $employee) {
            /** @var DirectoryEmployee $employee */
            $names[$employee->id] = EmployeeName::display($employee);
        }

        return $names;
    }

    /** @return array<string, string> token => the leave type's current name */
    private function typeNames(): array
    {
        $names = [];
        foreach (LeaveTypeCreditRecords::rules() as $token => $entry) {
            $names[$token] = $entry['name'];
        }

        return $names;
    }

    /**
     * @param  array<string, string>  $employeeNames
     * @param  array<string, string>  $typeNames
     */
    private function view(LeaveCashOut $row, array $employeeNames, array $typeNames): LeaveCashOutView
    {
        return Snapshot::of($row, LeaveCashOutView::class, [
            'employee_name' => $employeeNames[$row->employee_id] ?? '',
            'type_name' => $typeNames[$row->request_type] ?? $row->request_type,
        ]);
    }
}
