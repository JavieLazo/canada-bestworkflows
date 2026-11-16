<?php

declare(strict_types=1);

namespace App\Modules\WorkManagement\Contracts\Views;

use App\Platform\ReadModels\Record;
use Carbon\CarbonImmutable;

/**
 * @property-read string $id
 * @property-read string $project_id
 * @property-read string $entity_type
 * @property-read string $record_id
 * @property-read string $file_name
 * @property-read string $path
 * @property-read string $mime_type
 * @property-read int $file_size
 * @property-read string $sha256
 * @property-read int $uploaded_by
 * @property-read CarbonImmutable|null $removed_at
 * @property-read CarbonImmutable|null $retained_at
 * @property-read CarbonImmutable $created_at
 * @property-read CarbonImmutable|null $updated_at
 */
final readonly class RecordFileView extends Record {}
