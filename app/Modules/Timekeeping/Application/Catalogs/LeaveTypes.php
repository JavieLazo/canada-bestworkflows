<?php

declare(strict_types=1);

namespace App\Modules\Timekeeping\Application\Catalogs;

use App\Models\LeaveType;
use App\Platform\Records\ReferenceCatalog;

/** Owns this catalog's persistence while sharing its name/description editor mechanics. */
final class LeaveTypes extends ReferenceCatalog
{
    protected function model(): string
    {
        return LeaveType::class;
    }

    protected function prefix(): string
    {
        return 'LVE';
    }

    protected function noun(): string
    {
        return 'leave type';
    }
}
