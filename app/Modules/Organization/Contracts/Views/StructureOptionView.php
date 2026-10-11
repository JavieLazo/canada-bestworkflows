<?php

declare(strict_types=1);

namespace App\Modules\Organization\Contracts\Views;

use App\Platform\ReadModels\Record;

/**
 * One choice in a department or unit select: every department in HR order,
 * each followed by its units, which a select indents.
 *
 * @property-read string $id
 * @property-read string $kind  OrgLevel::DEPARTMENT or OrgLevel::UNIT
 * @property-read string $name
 * @property-read string $department_id  itself, for a department
 * @property-read bool $unit  true for a unit row
 */
final readonly class StructureOptionView extends Record {}
