<?php

declare(strict_types=1);

namespace App\Modules\WorkManagement\Contracts\Views;

use App\Platform\ReadModels\Record;

/**
 * @property-read string $id
 * @property-read string $project_id
 * @property-read string $name
 */
final readonly class TaskGroupView extends Record {}
