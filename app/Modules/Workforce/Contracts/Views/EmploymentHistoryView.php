<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Contracts\Views;

use App\Platform\ReadModels\Record;
use Carbon\CarbonImmutable;

/**
 * Detached employment timeline row for an employee record.
 *
 * @property-read string $id
 * @property-read string $employee_id
 * @property-read string $event_type
 * @property-read CarbonImmutable $effective_date
 * @property-read string|null $changed_fields
 * @property-read array<string, string|null>|null $previous_values
 * @property-read string|null $request_id
 * @property-read string|null $requested_by_name
 * @property-read CarbonImmutable|null $requested_at
 * @property-read string|null $request_reason
 * @property-read string|null $review_note
 * @property-read string $employee_name
 * @property-read string $employee_code
 * @property-read string|null $employee_id_no
 * @property-read list<array{field: string, label: string, before: string|null, after: string|null, before_known: bool, after_known: bool}> $changes
 * @property-read int $changed_count every field the save changed, including those a field filter left out of $changes
 * @property-read bool $is_latest no later row of any kind is stored for this employee, whatever the filters
 * @property-read list<array{field: string, label: string, value: string|null, known: bool}>|null $facts a starting record's details; null on a change
 * @property-read string|null $position
 * @property-read string|null $position_level
 * @property-read string|null $employment_status
 * @property-read string|null $work_site_id
 * @property-read string|null $work_site_name
 * @property-read CarbonImmutable|null $date_hired
 * @property-read CarbonImmutable|null $date_regular
 * @property-read CarbonImmutable|null $date_resigned
 * @property-read bool $is_active
 * @property-read float $monthly_rate
 * @property-read float|null $daily_rate
 * @property-read int|null $recorded_by
 * @property-read string|null $recorded_by_name
 * @property-read CarbonImmutable $created_at
 */
final readonly class EmploymentHistoryView extends Record {}
