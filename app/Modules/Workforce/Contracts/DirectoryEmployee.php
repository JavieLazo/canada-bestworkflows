<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Contracts;

use App\Platform\ReadModels\Record;
use Carbon\CarbonImmutable;

/** Operational identity only; salary, statutory IDs and personal contacts never enter this view.
 * The employment dates are here so a schedule screen can tell the dates an
 * employee was employed from the ones before and after.
 *
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
 * @property-read CarbonImmutable|null $date_hired
 * @property-read CarbonImmutable|null $date_resigned
 */
final readonly class DirectoryEmployee extends Record {}
