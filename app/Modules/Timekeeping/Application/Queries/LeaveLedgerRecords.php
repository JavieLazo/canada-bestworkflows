<?php

declare(strict_types=1);

namespace App\Modules\Timekeeping\Application\Queries;

use App\Domain\Enums\ModuleCode;
use App\Models\LeaveCreditEntry;
use App\Models\LeaveTravelRequest;
use App\Models\LeaveType;
use App\Modules\Timekeeping\Contracts\Views\LeaveBalanceView;
use App\Modules\Timekeeping\Contracts\Views\LeaveLedgerBalanceView;
use App\Modules\Timekeeping\Contracts\Views\LeaveLedgerEntryView;
use App\Modules\Timekeeping\Domain\Absence\LeaveCreditPolicy;
use App\Modules\Timekeeping\Domain\Absence\LeaveLedgerTotals;
use App\Platform\ReadModels\QueryPage;
use App\Platform\ReadModels\Snapshot;
use App\Support\Access;
use App\Support\HrisFormat;
use App\Support\ManilaClock;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Annual ledger reads preserve the established credit and start-year rules,
 * while showing approved unpaid leave and separating elapsed days from
 * future bookings. HR and My Portal share this projection and its scope.
 */
final class LeaveLedgerRecords
{
    public function __construct(private readonly LeaveCreditRecords $credits) {}

    /** @return list<LeaveLedgerBalanceView> */
    public function summary(Access $actor, string $employeeId, int $year): array
    {
        if (! $this->mayRead($actor, $employeeId) || ! $this->validYear($year)) {
            return [];
        }

        $balances = [];
        foreach ($this->credits->balances($actor, $employeeId, $year) as $balance) {
            $balances[$balance->request_type] = $balance;
        }
        $creditKeys = array_keys($balances);
        $entries = $this->entryQuery($actor, $employeeId, $year)
            ->select(['request_type', 'kind', 'days'])->get();
        $requests = LeaveTravelRequest::query()->with('cancellations:id,leave_travel_request_id,date')
            ->toEmployee($actor, 'employee_id', ModuleCode::LEAVES)
            ->where('employee_id', '=', $employeeId)
            ->where('status', 'APPROVED')
            ->whereBetween('start_date', [sprintf('%04d-01-01', $year), sprintf('%04d-12-31', $year)])
            ->where('request_type', '!=', 'BUSINESS_TRAVEL')
            ->get(['id', 'request_type', 'day_type', 'start_date', 'end_date', 'is_paid']);

        // History survives a cleared entitlement. Request-only types include
        // unpaid leave; neither may change which established credit is charged.
        $extraKeys = $entries->pluck('request_type')->all();
        foreach ($requests as $request) {
            if (LeaveCreditPolicy::resolveKey($request->request_type, $creditKeys) === null) {
                $extraKeys[] = $request->request_type;
            }
        }
        $extraKeys = array_values(array_diff(array_unique($extraKeys), $creditKeys));
        sort($extraKeys);
        $keys = [...$creditKeys, ...$extraKeys];
        $totals = [];
        foreach ($keys as $key) {
            $totals[$key] = new LeaveLedgerTotals;
        }
        $historied = [];
        foreach ($entries as $entry) {
            $totals[$entry->request_type]->credit($entry->kind, $entry->days);
            $historied[$entry->request_type] = true;
        }
        // Seeded or legacy aggregates may predate history. Their original
        // entitlement is the opening, never an invented accrual transaction.
        foreach ($balances as $key => $balance) {
            if (! isset($historied[$key])) {
                $totals[$key]->opening = $balance->credited ?? 0.0;
            }
        }

        $today = ManilaClock::todayKey();
        foreach ($requests as $request) {
            $key = LeaveCreditPolicy::resolveKey($request->request_type, $creditKeys) ?? $request->request_type;
            $start = $request->start_date->format('Y-m-d');
            $end = $request->end_date->format('Y-m-d');
            $cancelledDates = $request->cancelledDates();
            $all = LeaveCreditPolicy::coveredDays($request->day_type, $start, $end, $cancelledDates);
            $taken = LeaveCreditPolicy::coveredDays($request->day_type, $start, min($end, $today), $cancelledDates);
            $paid = $request->is_paid !== false;
            $totals[$key]->usage($paid, $taken, $all - $taken);
        }

        $names = $this->typeNames();
        $rows = [];
        foreach ($keys as $key) {
            $balance = $balances[$key] ?? null;
            $credited = $balance?->credited;
            $amounts = $totals[$key];
            $rows[] = new LeaveLedgerBalanceView(
                request_type: $key,
                label: $balance->label ?? $this->label($key, $names, $balances),
                year: $year,
                opening: $amounts->opening,
                accrued: $amounts->accrued,
                carried: $amounts->carried,
                adjustments: $amounts->adjustments,
                credited: $credited,
                paid_taken: $amounts->paidTaken,
                unpaid_taken: $amounts->unpaidTaken,
                booked_paid: $amounts->bookedPaid,
                booked_unpaid: $amounts->bookedUnpaid,
                closing: $credited === null ? null : $credited - $amounts->paidTaken,
                available: $balance?->remaining,
            );
        }

        return $rows;
    }

    /** My Portal always stays pinned to the actor, including all-data holders.
     *
     * @return list<LeaveLedgerBalanceView>
     */
    public function own(Access $actor, int $year): array
    {
        return $actor->hasEmployeeLink() ? $this->summary($actor, $actor->ownEmployeeId(), $year) : [];
    }

    /** @return LengthAwarePaginator<int, LeaveLedgerEntryView> */
    public function page(Access $actor, string $employeeId, int $year, string $requestType, int $page = 1, int $perPage = 20): LengthAwarePaginator
    {
        $query = $this->historyQuery($actor, $employeeId, $year, $requestType);
        $rows = QueryPage::get($query, $page, $perPage, LeaveLedgerEntryView::class, 'ledgerPage');
        $names = $rows->isEmpty() ? [] : $this->typeNames();

        return $rows->through(fn (LeaveLedgerEntryView $row): LeaveLedgerEntryView => $this->entryView($row, $names));
    }

    /**
     * Export walks the same scope, filters and ordering as the paginated
     * credit history, without holding the complete history in memory.
     *
     * @return iterable<LeaveLedgerEntryView>
     */
    public function entries(Access $actor, string $employeeId, int $year, string $requestType): iterable
    {
        if (! $this->mayRead($actor, $employeeId) || ! $this->validYear($year)) {
            return;
        }
        $names = $this->typeNames();
        foreach ($this->historyQuery($actor, $employeeId, $year, $requestType)->cursor() as $entry) {
            yield $this->entryView(Snapshot::of($entry, LeaveLedgerEntryView::class), $names);
        }
    }

    /** @param list<string> $names */
    private function entryView(LeaveLedgerEntryView $row, array $names): LeaveLedgerEntryView
    {
        $label = HrisFormat::requestDescription($row->request_type, $names);
        $tokens = array_map(HrisFormat::normalizeRequestType(...), $names);
        if (! in_array($row->request_type, $tokens, true) && in_array($label, $names, true)) {
            $label .= " ({$row->request_type})";
        }

        return new LeaveLedgerEntryView([
            'id' => $row->id,
            'request_type' => $row->request_type,
            'label' => $label,
            'kind' => $row->kind,
            'days' => $row->days,
            'note' => $row->note,
            'source_year' => $row->source_year,
            'created_at' => $row->created_at,
            'recorded_at' => $row->created_at,
        ]);
    }

    /** @return Builder<LeaveCreditEntry> */
    private function entryQuery(Access $actor, string $employeeId, int $year): Builder
    {
        return LeaveCreditEntry::query()
            ->toEmployee($actor, 'employee_id', ModuleCode::LEAVES)
            ->when(! $this->mayRead($actor, $employeeId) || ! $this->validYear($year), static fn (Builder $query): Builder => $query->whereRaw('1 = 0'))
            ->where('employee_id', '=', $employeeId)->where('year', $year);
    }

    /** @return Builder<LeaveCreditEntry> */
    private function historyQuery(Access $actor, string $employeeId, int $year, string $requestType): Builder
    {
        return $this->entryQuery($actor, $employeeId, $year)
            ->when($requestType !== '' && $requestType !== 'ALL', static fn (Builder $query): Builder => $query->where('request_type', $requestType))
            ->orderByDesc('created_at')->orderByDesc('id')
            ->select(['id', 'request_type', 'kind', 'days', 'note', 'source_year', 'created_at']);
    }

    private function mayRead(Access $actor, string $employeeId): bool
    {
        return $actor->isSignedIn() && ($actor->mayReadAllData(ModuleCode::LEAVES)
            || ($actor->hasEmployeeLink() && $actor->ownEmployeeId() === $employeeId));
    }

    private function validYear(int $year): bool
    {
        return $year >= LeaveCreditPolicy::YEAR_MIN && $year <= LeaveCreditPolicy::YEAR_MAX;
    }

    /** @return list<string> */
    private function typeNames(): array
    {
        return array_values(LeaveType::query()->orderBy('name')->pluck('name')->map(static fn (mixed $name): string => (string) $name)->all());
    }

    /**
     * @param  list<string>  $names
     * @param  array<string, LeaveBalanceView>  $balances
     */
    private function label(string $key, array $names, array $balances): string
    {
        $label = HrisFormat::requestDescription($key, $names);
        foreach ($balances as $balance) {
            if ($balance->label === $label) {
                return "{$label} ({$key})";
            }
        }

        return $label;
    }
}
