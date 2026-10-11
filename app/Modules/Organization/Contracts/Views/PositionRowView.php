<?php

declare(strict_types=1);

namespace App\Modules\Organization\Contracts\Views;

use App\Platform\ReadModels\Record;

/**
 * One row of the Position page: the position, where it sits, and whether it
 * is at either end of its place's order.
 *
 * @property-read string $id
 * @property-read string $code
 * @property-read string $name
 * @property-read string|null $description
 * @property-read string|null $org_node_id
 * @property-read string|null $department_id
 * @property-read string|null $department_name  null when not placed
 * @property-read string|null $unit_id
 * @property-read string|null $unit_name  null when in the department itself, or not placed
 * @property-read bool $first
 * @property-read bool $last
 */
final readonly class PositionRowView extends Record {}
