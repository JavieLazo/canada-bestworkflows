<?php

declare(strict_types=1);

namespace App\Modules\WorkManagement\Contracts\Views;

use App\Platform\ReadModels\Record;
use Carbon\CarbonImmutable;

/**
 * Detached TaskComment data, with no persistence or lazy relationships.
 *
 * @property-read string $id
 * @property-read string $task_id
 * @property-read int|null $author_id
 * @property-read string $author_name
 * @property-read string $side
 * @property-read string $body
 * @property-read string|null $event
 * @property-read CarbonImmutable $created_at
 */
final readonly class TaskCommentView extends Record {}
