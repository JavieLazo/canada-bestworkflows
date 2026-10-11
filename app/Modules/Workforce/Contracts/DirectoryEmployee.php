<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Contracts;

use App\Platform\ReadModels\Record;

/** Operational identity only; salary, statutory IDs and personal contacts never enter this view.
 * @property-read string $id
 * @property-read string $employee_code
 * @property-read string|null $employee_id_no
 * @property-read string $first_name
 * @property-read string $last_name
 * @property-read string|null $suffix
 * @property-read string|null $position
 * @property-read string $employment_status
 * @property-read bool $is_active
 * @property-read string|null $photo_path
 * @property-read string|null $work_site_id
 */
final readonly class DirectoryEmployee extends Record {}
