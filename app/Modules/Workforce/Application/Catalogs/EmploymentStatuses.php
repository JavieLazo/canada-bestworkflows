<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Application\Catalogs;

use App\Models\EmployeeProfile;
use App\Models\EmploymentStatusOption;
use App\Platform\Records\ReferenceCatalog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Owns this catalog's persistence while sharing its name/description editor
 * mechanics.
 *
 * A RENAME IS COPIED ONTO THE RECORDS. `employee_profiles.employment_status`
 * holds the status's name, and that name is what picks an employee's Pay
 * Basis & Rates override and benefit eligibility (Workforce's
 * EmployeePayContextQuery matches it to the list by name). Left alone, a
 * rename would silently drop every holder to the company rule, so the copy
 * on every record carrying the old name — active or not — is rewritten in
 * the renaming transaction, as a position rename does. A query-builder
 * update on purpose: no model events, so no Employment History rows; history
 * keeps the name each entry was saved with, and posted payroll keeps what
 * its evidence captured. The match ignores case and surrounding spaces, the
 * way the pay context reads the name, so a record typed "REGULAR " follows
 * the rename too.
 *
 * A DELETE IS REFUSED WHILE ANY RECORD CARRIES THE NAME, counted unscoped:
 * an orphaned status would silently drop its holders to the company rule.
 */
final class EmploymentStatuses extends ReferenceCatalog
{
    protected function model(): string
    {
        return EmploymentStatusOption::class;
    }

    protected function prefix(): string
    {
        return 'EMP';
    }

    protected function noun(): string
    {
        return 'employment status';
    }

    protected function copyRename(Model $row, string $from, string $to): int
    {
        return self::holders($from)->update(['employment_status' => $to]);
    }

    protected function inUse(string $name): int
    {
        return self::holders($name)->count();
    }

    /** @return Builder<EmployeeProfile> */
    private static function holders(string $name): Builder
    {
        return EmployeeProfile::query()->whereRaw('LOWER(TRIM(employment_status)) = ?', [mb_strtolower(trim($name))]);
    }
}
