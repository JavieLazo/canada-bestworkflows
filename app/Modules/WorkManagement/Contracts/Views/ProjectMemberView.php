<?php

declare(strict_types=1);

namespace App\Modules\WorkManagement\Contracts\Views;

use App\Platform\ReadModels\Record;

/**
 * @property-read string $id
 * @property-read string $project_id
 * @property-read int $user_id
 * @property-read string $role
 * @property-read string|null $position
 * @property-read string $name
 * @property-read string|null $employee_id
 */
final readonly class ProjectMemberView extends Record {}
