<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Infrastructure;

use App\Domain\Enums\ModuleCode;
use App\Models\EmployeeProfile;
use App\Modules\Organization\Contracts\OrganizationDirectory;
use App\Modules\Workforce\Application\PositionLink;
use App\Modules\Workforce\Contracts\DirectoryEmployee;
use App\Modules\Workforce\Contracts\OperationalDirectory;
use App\Platform\ReadModels\Snapshot;
use App\Support\Access;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/** The shared picker tier, with restricted SQL columns even for an organization-wide actor. */
final class OperationalDirectoryQuery implements OperationalDirectory
{
    /** @param list<string>|null $ids
     * @param  array{position?: string, active?: string, employmentStatus?: string, sort?: string, place?: string}  $filters
     * @return Collection<int, DirectoryEmployee>
     */
    public function list(Access $actor, ?array $ids = null, string $search = '', array $filters = []): Collection
    {
        $query = EmployeeProfile::query();
        if (! $actor->mayReadAllData(ModuleCode::EMPLOYEES, ModuleCode::PAYROLL, ModuleCode::EDTR, ModuleCode::ROSTER, ModuleCode::LEAVES, ModuleCode::PROJECTS, ModuleCode::TASKS, ModuleCode::INVENTORY)) {
            if (! $actor->hasEmployeeLink()) {
                return collect();
            }
            $query->whereKey($actor->ownEmployeeId());
        }
        if ($ids !== null) {
            $query->whereKey($ids);
        }
        $this->filter($query, $filters);
        $this->place($query, $actor, $filters['place'] ?? self::PLACE_ALL);

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

        $this->sort($query, $filters['sort'] ?? 'name-asc');

        return $query->get(['id', 'employee_code', 'employee_id_no', 'first_name', 'last_name', 'suffix', 'position', 'employment_status', 'is_active', 'photo_path', 'work_site_id'])
            ->map(static fn (EmployeeProfile $row): DirectoryEmployee => Snapshot::of($row, DirectoryEmployee::class));
    }

    public function find(Access $actor, string $id): ?DirectoryEmployee
    {
        return $this->list($actor, [$id])->first();
    }

    /**
     * @param  Builder<EmployeeProfile>  $query
     * @param  array{position?: string, active?: string, employmentStatus?: string, sort?: string, place?: string}  $filters
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
        if ($active === 'ACTIVE' || $active === 'INACTIVE') {
            $query->where('is_active', $active === 'ACTIVE');
        } elseif ($active !== 'ALL') {
            // An invalid selection must NEVER silently expand a filtered list.
            $query->whereRaw('1 = 0');
        }

        $status = $filters['employmentStatus'] ?? 'ALL';
        if ($status !== 'ALL') {
            $query->where('employment_status', $status);
        }
    }

    /**
     * The `place` filter, AFTER the row scope above. Organization owns where
     * a position sits, so the matching position ids come through its
     * contract and the narrowing here is one `position_id IN (…)`. Before
     * `position_id` exists nobody is placed, so anything but PLACE_ALL
     * matches nobody.
     *
     * @param  Builder<EmployeeProfile>  $query
     */
    private function place(Builder $query, Access $actor, string $place): void
    {
        if ($place === self::PLACE_ALL) {
            return;
        }

        if (! PositionLink::ready()) {
            $query->whereRaw('1 = 0');

            return;
        }

        $ids = [];

        foreach (app(OrganizationDirectory::class)->positionChoices($actor) as $position) {
            $matches = $place === self::PLACE_NOT_PLACED
                ? $position->org_node_id === null
                : ($position->department_id === $place || $position->unit_id === $place);

            if ($matches) {
                $ids[] = $position->id;
            }
        }

        if ($ids === []) {
            $query->whereRaw('1 = 0');

            return;
        }

        $query->whereIn('position_id', $ids);
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
