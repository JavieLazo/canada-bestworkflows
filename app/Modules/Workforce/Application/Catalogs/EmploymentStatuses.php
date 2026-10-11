<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Application\Catalogs;

use App\Models\EmploymentStatusOption;
use App\Platform\Records\ReferenceCatalog;

/** Owns this catalog's persistence while sharing its name/description editor mechanics. */
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
}
