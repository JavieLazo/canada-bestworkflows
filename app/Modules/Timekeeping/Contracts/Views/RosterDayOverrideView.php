<?php

declare(strict_types=1);

namespace App\Modules\Timekeeping\Contracts\Views;

use App\Platform\ReadModels\Record;
use Carbon\CarbonImmutable;

/**
 * A detached temporary assignment — one date, or `date` through `end_date`;
 * null hours or place independently follow weekly settings.
 *
 * @property-read string $id
 * @property-read string $employee_id
 * @property-read CarbonImmutable $date
 * @property-read string|null $shift_type_id
 * @property-read string|null $shift_name
 * @property-read bool $is_rest_day
 * @property-read array<string, mixed>|null $shift_data
 * @property-read string|null $location_kind
 * @property-read string|null $site_id
 * @property-read string|null $site_name
 * @property-read CarbonImmutable|null $end_date  null for a single date
 * @property-read bool $include_rest_days  true only when work was explicitly scheduled on rest days
 * @property-read string $status  ACTIVE | CANCELLED
 * @property-read CarbonImmutable|null $cut_after  the last date it still applies once cut short
 * @property-read string|null $revision_of
 */
final readonly class RosterDayOverrideView extends Record {}
