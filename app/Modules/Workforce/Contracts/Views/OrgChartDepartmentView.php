<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Contracts\Views;

/**
 * One department on the Organization view: the people placed in the department
 * itself, then its units in HR order. A department nobody works in is kept,
 * and so is each empty unit — the screen shows them with a sentence instead.
 */
final readonly class OrgChartDepartmentView
{
    /**
     * @param  list<OrgChartPersonView>  $people  placed in the department itself, in its position order, then by name
     * @param  list<OrgChartUnitView>  $units  in HR order
     */
    public function __construct(
        public string $id,
        public string $name,
        public array $people,
        public array $units,
    ) {}
}
