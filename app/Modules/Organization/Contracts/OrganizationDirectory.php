<?php

declare(strict_types=1);

namespace App\Modules\Organization\Contracts;

use App\Modules\Organization\Contracts\Views\PositionPlacementView;
use App\Modules\Organization\Contracts\Views\StructureOptionView;
use App\Modules\Organization\Contracts\Views\WorkSiteView;
use App\Support\Access;
use Illuminate\Support\Collection;

/**
 * Session-visible reference labels, site facts and the department, unit and
 * position options, without persistence objects. No row scope: these are
 * catalogs with no employee column.
 */
interface OrganizationDirectory
{
    /**
     * Every position title, each ONCE: the same title may be entered in more
     * than one department or unit, and a list of titles to filter by names
     * it a single time.
     *
     * @return list<string>
     */
    public function positions(Access $actor, bool $ordered = true): array;

    /** @return Collection<int, WorkSiteView> */
    public function sites(Access $actor): Collection;

    /**
     * Departments in HR order, each followed by its units: the options of
     * every department or unit select.
     *
     * @return list<StructureOptionView>
     */
    public function structureOptions(Access $actor): array;

    /**
     * Every position with where it sits, in the Position page's order:
     * department order, a department's own positions before each of its
     * units' in unit order, and the Not placed ones last by name.
     *
     * @return list<PositionPlacementView>
     */
    public function positionChoices(Access $actor): array;
}
