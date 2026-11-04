<?php

declare(strict_types=1);

namespace App\Modules\Timekeeping\Application\Queries;

use App\Domain\Enums\ModuleCode;
use App\Models\LeaveCredit;
use App\Models\LeaveTravelRequest;
use App\Models\LeaveType;
use App\Modules\Timekeeping\Application\LeaveBalances;
use App\Modules\Timekeeping\Application\LeaveCashOuts;
use App\Modules\Timekeeping\Contracts\Views\LeaveBalanceView;
use App\Modules\Timekeeping\Contracts\Views\LeaveCreditView;
use App\Modules\Timekeeping\Domain\Absence\LeaveCreditPolicy;
use App\Modules\Timekeeping\Domain\Credits\LeaveQuantity;
use App\Platform\ReadModels\Snapshot;
use App\Support\Access;
use App\Support\HrisFormat;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Reads of leave credits (leave_credits, 2026_09_26_0003) and the balances
 * derived from them.
 *
 * A balance is never stored: it is this year's entered credit minus the
 * credits the APPROVED, with-pay requests drawing on the year used
 * (LeaveBalances: the working days frozen on each approval, the calendar
 * days of an older row), computed here from ONE batched read of each table
 * for a whole page of employees, never a query per row — plus what the
 * employee's unpaid cash-outs have spoken for, so `available` is what filing
 * judges by. Both tables are read behind the leave list's scope
 * — all_data + LEAVES sees everyone, a linked account its own rows, an
 * unlinked account nothing — and `own()` pins the actor's own employee on
 * top of it for My Portal, so an all_data holder's "mine" stays theirs.
 *
 * Detached views only.
 */
final class LeaveCreditRecords
{
    /**
     * The Leave Settings types an employee can hold credits for, keyed by
     * their normalised token; the first name wins when two names normalise
     * alike ("Sick Leave" and "Sick-Leave" are one column, not two).
     *
     * @return array<string, string> token => Leave Settings name, in name order
     */
    public function creditableTypes(Access $actor): array
    {
        $types = [];
        foreach ($this->typeNames($actor) as $name) {
            $key = HrisFormat::normalizeRequestType($name);
            if ($key === '' || ! LeaveCreditPolicy::creditable($name) || isset($types[$key])) {
                continue;
            }
            $types[$key] = $name;
        }

        return $types;
    }

    /**
     * Every credit entered for the year, for a page of employees, in one query.
     *
     * @param  list<string>  $employeeIds
     * @return array<string, array<string, LeaveCreditView>> employee id => token => view; employees with no row are absent
     */
    public function forYear(Access $actor, int $year, array $employeeIds): array
    {
        if ($employeeIds === []) {
            return [];
        }
        $out = [];
        foreach ($this->creditQuery($actor, $year)->whereIn('employee_id', $employeeIds)->get() as $row) {
            $out[$row->employee_id][$row->request_type] = Snapshot::of($row, LeaveCreditView::class);
        }

        return $out;
    }

    /**
     * The balances of a page of employees for one year — one row per
     * creditable Leave Settings type, plus one per stale token the employee
     * still holds a credit under (a type renamed or deleted since), so a
     * balance never silently disappears from the dialog that could clear it.
     * Approved leave of a type with neither is not shown anywhere.
     *
     * @param  list<string>  $employeeIds
     * @return array<string, list<LeaveBalanceView>> employee id => rows in Leave Settings order, stale tokens last
     */
    public function balancesForEmployees(Access $actor, int $year, array $employeeIds): array
    {
        if ($employeeIds === []) {
            return [];
        }
        $types = $this->creditableTypes($actor);
        $names = $this->typeNames($actor);
        $credits = $this->forYear($actor, $year, $employeeIds);
        $requests = $this->requestQuery($actor, $year)->whereIn('employee_id', $employeeIds)->get()->groupBy('employee_id');
        $spoken = LeaveCashOuts::spokenForMany($employeeIds);

        $out = [];
        foreach ($employeeIds as $employeeId) {
            $entered = [];
            foreach ($credits[$employeeId] ?? [] as $key => $view) {
                $entered[$key] = (float) $view->credits;
            }
            $keys = $this->keysFor(array_keys($types), array_keys($entered));
            $out[$employeeId] = $this->rows($keys, $entered, $requests->get($employeeId, new Collection), $year, $types, $names, $spoken[$employeeId] ?? []);
        }

        return $out;
    }

    /**
     * One employee's balances for a year — the review dialog's line and the
     * credits dialog's rows. For an employee the actor may not see, both
     * scoped reads return nothing, so every row reads "nothing entered,
     * nothing used" — never another employee's numbers. Callers resolve the
     * employee through the directory first, so that case does not reach a
     * screen.
     *
     * @return list<LeaveBalanceView>
     */
    public function balances(Access $actor, string $employeeId, int $year): array
    {
        return $this->balancesForEmployees($actor, $year, [$employeeId])[$employeeId] ?? [];
    }

    /**
     * MY balances for a year: only the types with a credit entered, pinned
     * to the session's own employee on top of the scope (the self-service
     * rule), and empty for an account with no employee link — never an
     * unfiltered read.
     *
     * @return Collection<int, LeaveBalanceView>
     */
    public function own(Access $actor, int $year): Collection
    {
        if (! $actor->hasEmployeeLink()) {
            return collect();
        }
        $entered = [];
        foreach ($this->creditQuery($actor, $year)->where('employee_id', '=', $actor->ownEmployeeId())->get() as $row) {
            $entered[$row->request_type] = (float) $row->credits;
        }
        if ($entered === []) {
            return collect();
        }
        $types = $this->creditableTypes($actor);
        $keys = array_values(array_filter($this->keysFor(array_keys($types), array_keys($entered)), static fn (string $key): bool => isset($entered[$key])));
        $requests = $this->requestQuery($actor, $year)->where('employee_id', '=', $actor->ownEmployeeId())->get();

        return collect($this->rows($keys, $entered, $requests, $year, $types, $this->typeNames($actor), LeaveCashOuts::spokenFor($actor->ownEmployeeId())));
    }

    /** @return Builder<LeaveCredit> */
    private function creditQuery(Access $actor, int $year): Builder
    {
        return LeaveCredit::query()->toEmployee($actor, 'employee_id', ModuleCode::LEAVES)->where('year', $year);
    }

    /**
     * The approved requests drawing on the year — the policy decides which
     * of them draw on a credit and by how much.
     *
     * @return Builder<LeaveTravelRequest>
     */
    private function requestQuery(Access $actor, int $year): Builder
    {
        $query = LeaveTravelRequest::query()->with('cancellations:id,leave_travel_request_id,date')
            ->toEmployee($actor, 'employee_id', ModuleCode::LEAVES)
            ->where('status', 'APPROVED')
            ->select(LeaveBalances::columns());

        return LeaveBalances::drawingOn($query, $year);
    }

    /**
     * The row set: the creditable types in Leave Settings order, then any
     * stale token a credit is still entered under.
     *
     * @param  list<string>  $typeKeys
     * @param  list<string>  $enteredKeys
     * @return list<string>
     */
    private function keysFor(array $typeKeys, array $enteredKeys): array
    {
        $stale = array_values(array_diff($enteredKeys, $typeKeys));
        sort($stale);

        return [...$typeKeys, ...$stale];
    }

    /**
     * @param  list<string>  $keys
     * @param  array<string, float>  $entered  token => credits entered
     * @param  Collection<int, LeaveTravelRequest>  $requests  the year's approved requests of this employee
     * @param  array<string, string>  $types  token => name
     * @param  list<string>  $names
     * @param  array<string, float>  $spoken  token => days claimed by unpaid cash-outs
     * @return list<LeaveBalanceView>
     */
    private function rows(array $keys, array $entered, Collection $requests, int $year, array $types, array $names, array $spoken = []): array
    {
        $used = LeaveBalances::usage($requests, $keys, $year);

        $rows = [];
        foreach ($keys as $key) {
            $credited = $entered[$key] ?? null;
            $remaining = $credited === null ? null : LeaveQuantity::normalize($credited - $used[$key]);
            $claimed = LeaveQuantity::normalize($spoken[$key] ?? 0.0);
            $rows[] = new LeaveBalanceView(
                request_type: $key,
                label: $types[$key] ?? self::staleLabel($key, $types, $names),
                year: $year,
                credited: $credited,
                used: $used[$key],
                remaining: $remaining,
                spoken_for: $claimed,
                available: $remaining === null ? null : LeaveQuantity::normalize($remaining - $claimed),
            );
        }

        return $rows;
    }

    /**
     * The label of a token no current type carries. `requestDescription`'s
     * `_LEAVE` tolerance can hand a stale `SICK` row the same "Sick Leave" as
     * the live `SICK_LEAVE` row beside it, so when the two would read alike
     * the token is appended — "Sick Leave (SICK)" — and the dialog and the
     * audit sentence tell them apart.
     *
     * @param  array<string, string>  $types  token => name
     * @param  list<string>  $names
     */
    private static function staleLabel(string $key, array $types, array $names): string
    {
        $label = HrisFormat::requestDescription($key, $names);

        return in_array($label, $types, true) ? "{$label} ({$key})" : $label;
    }

    /**
     * Every Leave Settings name, in name order — the label source, open to
     * any session as the reference-list reads are.
     *
     * @return list<string>
     */
    private function typeNames(Access $actor): array
    {
        abort_if($actor->userId === null, 403);

        return array_values(LeaveType::query()->orderBy('name')->pluck('name')->map(static fn (mixed $name): string => (string) $name)->all());
    }
}
