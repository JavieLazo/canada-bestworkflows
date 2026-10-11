<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Contracts\Views;

use App\Platform\ReadModels\Record;
use Carbon\CarbonImmutable;

/**
 * Detached EmployeeDocument data, with no persistence or lazy relationships.
 *
 * @property-read string $id
 * @property-read string $employee_id
 * @property-read string|null $document_no
 * @property-read string $name
 * @property-read string $category
 * @property-read CarbonImmutable|null $expires_on
 * @property-read string $file_name
 * @property-read string $mime
 * @property-read int $size
 * @property-read string $path
 * @property-read int|null $uploaded_by
 * @property-read CarbonImmutable $created_at
 * @property-read CarbonImmutable|null $updated_at
 */
final readonly class EmployeeDocumentView extends Record {}
