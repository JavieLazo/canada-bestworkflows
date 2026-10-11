<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Contracts\Views;

/**
 * One unit of a department on the Organization view, with the people who hold
 * its positions. An empty unit is kept: the screen says nobody is in it yet.
 */
final readonly class OrgChartUnitView
{
    /**
     * @param  list<OrgChartPersonView>  $people  in the unit's position order, then by name
     */
    public function __construct(
        public string $id,
        public string $name,
        public array $people,
    ) {}
}
