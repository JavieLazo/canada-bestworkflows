<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Contracts\Views;

use App\Platform\ReadModels\Record;
use Carbon\CarbonImmutable;

/**
 * Detached inventory evidence for authorized presentation.
 *
 * @property-read string $id
 * @property-read CarbonImmutable $created_at
 * @property-read CarbonImmutable|null $updated_at
 * @property-read string|null $receipt_id
 * @property-read string|null $disposal_id
 * @property-read string $file_name
 * @property-read string $mime
 * @property-read int $size
 * @property-read string $path
 * @property-read int $uploaded_by
 */
final readonly class AttachmentView extends Record {}
