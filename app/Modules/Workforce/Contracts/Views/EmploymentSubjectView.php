<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Contracts\Views;

use App\Platform\ReadModels\Record;
use Carbon\CarbonImmutable;

/**
 * The employee a history timeline belongs to, as their record reads TODAY.
 *
 * It carries only what introduces a person above their timeline: the facts
 * the Employee Records list shows, plus the date they joined. Position level,
 * worksite and rates are left out ON PURPOSE: a header is no place for pay,
 * and those values belong to the entries, where each one carries the date it
 * was saved.
 *
 * @property-read string $id
 * @property-read string $employee_code
 * @property-read string|null $employee_id_no
 * @property-read string $first_name
 * @property-read string $last_name
 * @property-read string|null $suffix
 * @property-read string $name
 * @property-read string|null $position
 * @property-read string|null $employment_status
 * @property-read bool $is_active
 * @property-read CarbonImmutable|null $date_hired
 * @property-read string|null $photo_path
 */
final readonly class EmploymentSubjectView extends Record {}
