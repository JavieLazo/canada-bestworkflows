<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Contracts\Views;

use App\Platform\ReadModels\Record;

/**
 * One row of a Personal Data Sheet list as the record page and the print
 * may know it, built only by PdsRows with its values already typed, so
 * Blade never formats a Carbon or re-derives a row. `kind` says where the
 * row comes from: `row` a stored row (the only kind with an id), `derived`
 * the current job read from Employee Details, `legacy` a College or
 * Graduate line kept from the record's legacy columns, `empty` a level
 * with no school.
 *
 * @property-read string|null $id
 * @property-read string $list
 * @property-read string $kind
 * @property-read string $level
 * @property-read string|null $school
 * @property-read string|null $course
 * @property-read int|null $attended_from
 * @property-read int|null $attended_to
 * @property-read string|null $units_earned
 * @property-read int|string|null $year_graduated an int on a stored row; the legacy text, printed as is, on a `legacy` row
 * @property-read string|null $honors
 * @property-read string|null $name
 * @property-read string|null $rating
 * @property-read string|null $exam_date `YYYY-MM-DD`
 * @property-read string|null $exam_place
 * @property-read string|null $license_no
 * @property-read string|null $license_valid_until `YYYY-MM-DD`
 * @property-read string|null $position_title
 * @property-read string|null $company
 * @property-read string|null $date_from `YYYY-MM-DD`
 * @property-read string|null $date_to `YYYY-MM-DD`; NULL on the current job reads "Present"
 * @property-read float|null $monthly_salary
 * @property-read string|null $pay_grade
 * @property-read string|null $appointment_status
 * @property-read bool|null $government_service
 * @property-read string|null $title
 * @property-read float|null $hours
 * @property-read string|null $ld_type
 * @property-read string|null $sponsor
 * @property-read string|null $organization
 * @property-read string|null $organization_address
 * @property-read string|null $position
 */
final readonly class PdsRowView extends Record {}
