<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\IsReferenceList;
use Carbon\CarbonImmutable;

/**
 * Positions: the job titles offered when hiring or editing an employee
 * record, each placed in a department or in one of its units.
 *
 * `org_node_id` is that department or unit (`org_nodes`, 2026_10_07_0012);
 * NULL is "Not placed". `sort_order` is HR's order among the positions of one
 * department or unit. Both are written only by the Organization commands,
 * under the `copems:org-structure` mutex, which also keeps a title once among
 * the Not placed positions — `positions_org_node_name_unq` keeps it once
 * inside a department or unit, and the same title may repeat across them.
 * Employees link here through `employee_profiles.position_id`.
 *
 * @property string $id
 * @property string $code
 * @property string $name
 * @property string|null $description
 * @property string|null $org_node_id
 * @property int $sort_order
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable|null $updated_at
 */
class Position extends BaseModel
{
    use IsReferenceList;

    /** @var string */
    protected $table = 'positions';

    /**
     * The trait's initializer, taken over so the placement and the order are
     * fillable too. Eloquent calls the method by name for each trait, and a
     * same-named class method wins — the composition stays valid on PHP 8.4,
     * unlike a second `$fillable` declaration would.
     */
    public function initializeIsReferenceList(): void
    {
        $this->fillable = ['code', 'name', 'description', 'org_node_id', 'sort_order'];
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
