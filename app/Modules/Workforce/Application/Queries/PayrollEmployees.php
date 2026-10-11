<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Application\Queries;

use App\Domain\Enums\ModuleCode;
use App\Models\EmployeeProfile;
use App\Modules\Workforce\Contracts\DirectoryEmployee;
use App\Platform\ReadModels\Snapshot;
use App\Support\Access;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Who a payroll's add-employees dialog may offer. A regular cut-off offers the
 * ACTIVE employees; a 13th month run offers everyone employed at some point
 * of its year — hired on or before 31 December and not resigned before
 * 1 January — resigned or inactive ones included, since an employee who
 * left in June is still owed the 13th month of what they earned.
 */
final class PayrollEmployees
{
    /** @return Collection<int, DirectoryEmployee> */
    public function list(Access $actor): Collection
    {
        return $actor->mayReadAllData(ModuleCode::PAYROLL)
            ? EmployeeProfile::query()->where('is_active', true)
                ->get(['id', 'employee_code', 'employee_id_no', 'first_name', 'last_name', 'suffix', 'position', 'is_active', 'photo_path'])
                ->map(static fn (EmployeeProfile $row): DirectoryEmployee => Snapshot::of($row, DirectoryEmployee::class))
            : collect();
    }

    public function active(Access $actor, string $id): bool
    {
        return $actor->mayReadAllData(ModuleCode::PAYROLL) && EmployeeProfile::query()->whereKey($id)->where('is_active', true)->exists();
    }

    /** @return Collection<int, DirectoryEmployee> everyone employed at some point of the year */
    public function employedInYear(Access $actor, int $year): Collection
    {
        return $actor->mayReadAllData(ModuleCode::PAYROLL)
            ? self::inYear(EmployeeProfile::query(), $year)
                ->get(['id', 'employee_code', 'employee_id_no', 'first_name', 'last_name', 'suffix', 'position', 'is_active', 'photo_path'])
                ->map(static fn (EmployeeProfile $row): DirectoryEmployee => Snapshot::of($row, DirectoryEmployee::class))
            : collect();
    }

    /** Whether this employee may join the year's 13th month run: the dialog's own rows, and no other. */
    public function admissibleInYear(Access $actor, string $id, int $year): bool
    {
        return $actor->mayReadAllData(ModuleCode::PAYROLL) && self::inYear(EmployeeProfile::query()->whereKey($id), $year)->exists();
    }

    /**
     * @param  Builder<EmployeeProfile>  $query
     * @return Builder<EmployeeProfile>
     */
    private static function inYear(Builder $query, int $year): Builder
    {
        return $query
            ->where('date_hired', '<=', sprintf('%04d-12-31', $year))
            ->where(static fn (Builder $q) => $q->whereNull('date_resigned')->orWhere('date_resigned', '>=', sprintf('%04d-01-01', $year)));
    }
}
