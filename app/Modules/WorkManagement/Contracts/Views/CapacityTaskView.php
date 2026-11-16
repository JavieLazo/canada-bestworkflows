<?php

declare(strict_types=1);

namespace App\Modules\WorkManagement\Contracts\Views;

use App\Platform\ReadModels\Record;

/**
 * @property-read string $id
 * @property-read string|null $task_id
 * @property-read string $project_id
 * @property-read string $title
 * @property-read string $code
 * @property-read string|null $project_name
 * @property-read string|null $client_name
 * @property-read bool|int|null $on_crew
 * @property-read bool|int|null $can_plan
 * @property-read string $employee_id
 * @property-read string|null $person_name
 * @property-read string|null $week_start
 * @property-read int|null $hours_hundredths
 * @property-read int|null $revision
 * @property-read int|null $is_parent
 */
final readonly class CapacityTaskView extends Record {}
