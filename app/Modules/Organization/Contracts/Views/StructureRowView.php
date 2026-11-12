<?php

declare(strict_types=1);

namespace App\Modules\Organization\Contracts\Views;

use App\Platform\ReadModels\Record;

/**
 * One row of the Departments & Units page: a department, or a unit under
 * it, with what its row menu needs.
 *
 * @property-read string $id
 * @property-read string $code
 * @property-read string $kind  OrgLevel::DEPARTMENT or OrgLevel::UNIT
 * @property-read string $name
 * @property-read string|null $description
 * @property-read string|null $parent_id
 * @property-read string $head  who heads the office, as one line, or ''
 * @property-read bool $first  first among its siblings: Move up is disabled
 * @property-read bool $last  last among its siblings: Move down is disabled
 * @property-read string $blocked_by  what keeps it from being deleted — "2 units, 2 positions" — or ''
 */
final readonly class StructureRowView extends Record {}
