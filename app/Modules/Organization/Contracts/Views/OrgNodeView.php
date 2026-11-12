<?php

declare(strict_types=1);

namespace App\Modules\Organization\Contracts\Views;

use App\Platform\ReadModels\Record;
use Carbon\CarbonImmutable;

/**
 * Detached department or unit data, with no persistence or lazy
 * relationships.
 *
 * @property-read string $id
 * @property-read string $code
 * @property-read string $kind  OrgLevel::DEPARTMENT or OrgLevel::UNIT
 * @property-read string $name
 * @property-read string|null $description
 * @property-read string|null $parent_id  the department, for a unit; null for a department
 * @property-read int $sort_order
 * @property-read string|null $head_name  absent before 2026_11_11_0002
 * @property-read string|null $head_designation
 * @property-read CarbonImmutable $created_at
 * @property-read CarbonImmutable|null $updated_at
 */
final readonly class OrgNodeView extends Record {}
