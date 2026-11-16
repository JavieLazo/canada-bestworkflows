<?php

declare(strict_types=1);

namespace App\Modules\WorkManagement\Contracts\Views;

use App\Platform\ReadModels\Record;

/**
 * @property-read string $id
 * @property-read string $code
 * @property-read string $title
 * @property-read string $status
 */
final readonly class TaskParentView extends Record {}
