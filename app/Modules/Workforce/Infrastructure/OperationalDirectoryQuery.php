<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Infrastructure;

use App\Domain\Enums\ModuleCode;
use App\Domain\Payroll\PayrollPeriod;
use App\Models\EmployeeProfile;
use App\Modules\Workforce\Application\EmployeePlacements;
use App\Modules\Workforce\Application\PlaceFilter;
use App\Modules\Workforce\Application\TableFilters;
use App\Modules\Workforce\Contracts\DirectoryEmployee;
use App\Modules\Workforce\Contracts\OperationalDirectory;
use App\Platform\ReadModels\Snapshot;
use App\Platform\ReadModels\SqlText;
use App\Support\Access;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/** The shared picker tier, with restricted SQL columns even for an organization-wide actor. */
final class OperationalDirectoryQuery implements OperationalDirectory
{
    /** The SQL columns a DirectoryEmployee carries — never salary, statutory IDs or contacts. */
    private const array COLUMNS = ['id', 'employee_code', 'employee_id_no', 'first_name', 'last_name', 'suffix', 'position', 'employment_status', 'is_active', 'photo_path', 'work_site_id', 'date_hired', 'date_resigned'];

    /** @param list<string>|null $ids
     * @param  array{position?: string, active?: string, employmentStatus?: string, sort?: string, place?: string, keep?: list<string>}  $filters
     * @return Collection<int, DirectoryEmployee>
     */
    public function list(Access $actor, ?array $ids = null, string $search = '', array $filters = []): Collection
    {
        $query = $this->matching($actor, $ids, $search, $filters);

        if ($query === null) {
            return collect();
        }

        $this->sort($query, $filters['sort'] ?? 'name-asc');

        return $query->get(self::COLUMNS)
            ->map(static fn (EmployeeProfile $row): DirectoryEmployee => Snapshot::of($row, DirectoryEmployee::class));
    }

    /**
     * @param  list<string>|null  $ids
     * @param  array{position?: string, active?: string, employmentStatus?: string, sort?: string, place?: string, keep?: list<string>}  $filters
     * @return LengthAwarePaginator<int, DirectoryEmployee>
     */
    public function firstPage(Access $actor, int $perPage, ?array $ids = null, string $search = '', array $filters = []): LengthAwarePaginator
    {
        $perPage = max(1, min(100, $perPage));
        $query = $this->matching($actor, $ids, $search, $filters);

        if ($query === null) {
            return new LengthAwarePaginator([], 0, $perPage, 1);
        }

        // Counted before the ORDER BY is added: the total is every match,
        // and only the page's own rows are read.
        $total = (clone $query)->count();
        $this->sort($query, $filters['sort'] ?? 'name-asc');
        $rows = $total === 0 ? collect() : $query->limit($perPage)->get(self::COLUMNS)
            ->map(static fn (EmployeeProfile $row): DirectoryEmployee => Snapshot::of($row, DirectoryEmployee::class));

        return new LengthAwarePaginator($rows, $total, $perPage, 1);
    }

    public function find(Access $actor, string $id): ?DirectoryEmployee
    {
        return $this->list($actor, [$id])->first();
    }

    /**
     * @param  list<string>  $ids
     * @return array<string, string>
     */
    public function placeLabels(Access $actor, array $ids): array
    {
        $query = $ids === [] ? null : $this->scoped($actor, $ids);
        if ($query === null) {
            return [];
        }
        $labels = [];
        $columns = EmployeePlacements::ready() ? ['id', 'position', 'position_id'] : ['id', 'position'];
        foreach (EmployeePlacements::of($query->get($columns)) as $id => $placement) {
            $names = array_filter([$placement['department_name'] ?? null, $placement['unit_name'] ?? null], static fn (mixed $name): bool => is_string($name) && $name !== '');
            $labels[$id] = implode(' · ', $names);
        }

        return $labels;
    }

    /**
     * @param  array{search: string, status: string, position: string, place?: string, active?: string, left_from?: string}  $filters
     * @param  list<string>|null  $ids
     * @param  array<string, int>  $rank
     * @return LengthAwarePaginator<int, DirectoryEmployee>
     */
    public function tablePage(Access $actor, array $filters, ?array $ids, string $sort, string $direction, int $page, int $perPage, array $rank = []): LengthAwarePaginator
    {
        $perPage = max(1, min(100, $perPage));
        $direction = $direction === 'desc' ? 'desc' : 'asc';
        $query = $this->scoped($actor, $ids);

        if ($query === null) {
            return new LengthAwarePaginator([], 0, $perPage, 1);
        }

        TableFilters::status($query, $filters['status']);
        TableFilters::position($query, $filters['position']);
        // After the row scope above, so it only ever narrows (PlaceFilter).
        PlaceFilter::apply($query, $actor, $filters['place'] ?? self::PLACE_ALL);
        if (($filters['active'] ?? 'ALL') === 'ACTIVE') {
            $leftFrom = $filters['left_from'] ?? null;
            if (is_string($leftFrom) && PayrollPeriod::isPayrollDate($leftFrom)) {
                $query->where(static function (Builder $row) use ($leftFrom): void {
                    $row->where('is_active', true)
                        ->orWhere(static fn (Builder $left) => $left->where('is_active', false)->whereDate('date_resigned', '>=', $leftFrom));
                });
            } else {
                $query->where('is_active', true);
            }
        }
        SqlText::contains($query, ['employee_code', SqlText::joinedWords($query, ['first_name', 'last_name', 'suffix']), 'position'], $filters['search']);

        if ($sort === 'id') {
            SqlText::orderNumeric($query, 'employee_code', $direction);
        } elseif ($sort === 'rank' && $rank !== []) {
            // An unranked id ranks after every ranked one (no rank reaches
            // the fallback), so it is last ascending and first descending:
            // a caller's comparator that files a row without the value last
            // sorts it the same way, and desc is the whole order reversed.
            SqlText::orderMapped($query, 'id', $rank, '2147483647', $direction, false);
        } elseif ($sort !== 'rank') {
            SqlText::order($query, 'last_name', $direction);
            SqlText::order($query, 'first_name', $direction);
        }

        // The directory's own name order breaks every tie, as list() reads.
        $query->orderBy('last_name', $direction)->orderBy('first_name', $direction)
            ->orderBy('suffix', $direction)->orderBy('id', $direction);

        $rows = $query->paginate($perPage, self::COLUMNS, 'page', max(1, $page));
        if ($rows->currentPage() > $rows->lastPage()) {
            $rows = $query->paginate($perPage, self::COLUMNS, 'page', $rows->lastPage());
        }

        return $rows->through(static fn (EmployeeProfile $row): DirectoryEmployee => Snapshot::of($row, DirectoryEmployee::class));
    }

    /**
     * @param  list<string>|null  $ids
     * @return array{positions: list<string>, statuses: list<string>}
     */
    public function heldValues(Access $actor, ?array $ids = null): array
    {
        $filled = static fn (array $values): array => array_values(array_filter($values, static fn (string $value): bool => $value !== ''));
        $positions = $this->scoped($actor, $ids);
        $statuses = $this->scoped($actor, $ids);

        return [
            'positions' => $positions === null ? [] : $filled(SqlText::distinctValues($positions, 'position')),
            'statuses' => $statuses === null ? [] : $filled(SqlText::distinctValues($statuses, 'employment_status')),
        ];
    }

    /**
     * The operational tier: every record for an all-data holder of any
     * module that lists employees, the actor's own otherwise, and null — no
     * one — for an account with no employee link. `$ids` narrows it.
     *
     * @param  list<string>|null  $ids
     * @return Builder<EmployeeProfile>|null
     */
    private function scoped(Access $actor, ?array $ids): ?Builder
    {
        $query = EmployeeProfile::query();
        if (! $actor->mayReadAllData(ModuleCode::EMPLOYEES, ModuleCode::PAYROLL, ModuleCode::EDTR, ModuleCode::ROSTER, ModuleCode::LEAVES, ModuleCode::PROJECTS, ModuleCode::TASKS, ModuleCode::INVENTORY)) {
            if (! $actor->hasEmployeeLink()) {
                return null;
            }
            $query->whereKey($actor->ownEmployeeId());
        }
        if ($ids !== null) {
            $query->whereKey($ids);
        }

        return $query;
    }

    /**
     * The scoped, filtered and searched rows `list()` and `firstPage()` share,
     * still unordered; null when the actor may see nobody at all.
     *
     * @param  list<string>|null  $ids
     * @param  array{position?: string, active?: string, employmentStatus?: string, sort?: string, place?: string, keep?: list<string>}  $filters
     * @return Builder<EmployeeProfile>|null
     */
    private function matching(Access $actor, ?array $ids, string $search, array $filters): ?Builder
    {
        $query = $this->scoped($actor, $ids);
        if ($query === null) {
            return null;
        }
        $this->filter($query, $filters);
        // After the row scope above, so it only ever narrows (PlaceFilter).
        PlaceFilter::apply($query, $actor, $filters['place'] ?? self::PLACE_ALL);

        foreach (preg_split('/\s+/', trim($search), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $term) {
            $like = '%'.addcslashes($term, '\\%_').'%';
            // Shared operational search backs both employee controls. Match
            // every term across identity and position so names, suffixes and
            // the Employee ID supplied on paper all remain searchable.
            $query->where(static function (Builder $q) use ($like): void {
                $q->where('employee_code', 'like', $like)->orWhere('employee_id_no', 'like', $like)
                    ->orWhere('first_name', 'like', $like)->orWhere('last_name', 'like', $like)
                    ->orWhere('suffix', 'like', $like)->orWhere('position', 'like', $like);
            });
        }

        return $query;
    }

    /**
     * @param  Builder<EmployeeProfile>  $query
     * @param  array{position?: string, active?: string, employmentStatus?: string, sort?: string, place?: string, keep?: list<string>}  $filters
     */
    private function filter(Builder $query, array $filters): void
    {
        $position = $filters['position'] ?? 'ALL';
        if ($position === 'NONE') {
            $query->where(static function (Builder $row): void {
                $row->whereNull('position')->orWhereRaw("TRIM(position) = ''");
            });
        } elseif ($position !== 'ALL') {
            $query->where('position', $position);
        }

        $active = $filters['active'] ?? 'ALL';
        $keep = array_values(array_filter($filters['keep'] ?? [], static fn (string $id): bool => $id !== ''));
        if ($active === 'ACTIVE' || $active === 'INACTIVE') {
            // `keep` is exempt from this filter alone; the scope and every
            // other condition on the query still bind it.
            $query->where(static function (Builder $row) use ($active, $keep): void {
                $row->where('is_active', $active === 'ACTIVE');
                if ($keep !== []) {
                    $row->orWhereIn('id', $keep);
                }
            });
        } elseif ($active !== 'ALL') {
            // An invalid selection must NEVER silently expand a filtered list.
            $query->whereRaw('1 = 0');
        }

        $status = $filters['employmentStatus'] ?? 'ALL';
        if ($status !== 'ALL') {
            $query->where('employment_status', $status);
        }
    }

    /** @param Builder<EmployeeProfile> $query */
    private function sort(Builder $query, string $sort): void
    {
        if ($sort === 'employee-id-asc' || $sort === 'employee-id-desc') {
            // Sort the displayed Employee ID; the internal row code is a
            // different identifier. Missing Employee IDs stay at the end.
            $query->orderByRaw("CASE WHEN employee_id_no IS NULL OR TRIM(employee_id_no) = '' THEN 1 ELSE 0 END")
                ->orderBy('employee_id_no', $sort === 'employee-id-desc' ? 'desc' : 'asc');
        } elseif ($sort === 'position') {
            $query->orderByRaw("CASE WHEN position IS NULL OR TRIM(position) = '' THEN 1 ELSE 0 END")
                ->orderBy('position');
        }

        $direction = $sort === 'name-desc' ? 'desc' : 'asc';

        // Suffix and primary key make every order stable between renders.
        // Unknown sort values retain the existing default name order.
        $query->orderBy('last_name', $direction)->orderBy('first_name', $direction)
            ->orderBy('suffix', $direction)->orderBy('id');
    }
}
