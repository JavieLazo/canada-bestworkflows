<?php

declare(strict_types=1);

namespace App\Modules\WorkManagement\Contracts\Views;

use App\Platform\ReadModels\Record;

/**
 * @property-read int|string $id
 * @property-read int|null $user_id
 * @property-read string|null $employee_id
 * @property-read string $name
 * @property-read string $access
 * @property-read string $access_label
 * @property-read string|null $position
 * @property-read bool $active
 * @property-read bool $explicit
 * @property-read int $open_tasks
 */
final readonly class WorkMemberView extends Record {}
