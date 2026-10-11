<?php

declare(strict_types=1);

namespace App\Modules\WorkManagement\Contracts\Views;

use App\Modules\IdentityAccess\Contracts\AccountLabel;
use App\Platform\ReadModels\Record;
use Carbon\CarbonImmutable;

/**
 * Detached ProjectTaskFile data, with no persistence or lazy relationships.
 *
 * @property-read AccountLabel|null $uploader
 * @property-read string $id
 * @property-read string $task_id
 * @property-read string|null $employee_id
 * @property-read string $kind
 * @property-read string $file_name
 * @property-read string $mime
 * @property-read int $size
 * @property-read string $path
 * @property-read int|null $uploaded_by
 * @property-read CarbonImmutable $created_at
 */
final readonly class ProjectTaskFileView extends Record {}
