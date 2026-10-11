<?php

declare(strict_types=1);

namespace App\Modules\Organization\Contracts\Views;

use App\Platform\ReadModels\Record;

/**
 * Where one position sits: its department, and its unit when it is placed in
 * one. A position in the department itself has no unit; a Not placed one has
 * neither.
 *
 * @property-read string $id  the position's id
 * @property-read string $code
 * @property-read string $name
 * @property-read string|null $org_node_id  the department or unit it is placed in; null when not placed
 * @property-read string|null $department_id
 * @property-read string|null $department_name
 * @property-read string|null $unit_id
 * @property-read string|null $unit_name
 */
final readonly class PositionPlacementView extends Record {}
