<?php

declare(strict_types=1);

namespace App\Modules\Timekeeping\Contracts\Views;

use App\Platform\ReadModels\Record;
use Carbon\CarbonImmutable;

/**
 * One month's earning posted for one employee and leave type, as Leave
 * Management's postings log reads it.
 *
 * @property-read string $id
 * @property-read string $employee_id
 * @property-read string $period  'YYYY-MM', or 'YYYY-YE' for the year-end step
 * @property-read string $request_type
 * @property-read string $type_name
 * @property-read float $days
 * @property-read array<string, mixed> $inputs  the month's facts and the rule applied
 * @property-read string $basis  the sentence of how the amount came out
 * @property-read CarbonImmutable $created_at
 */
final readonly class LeaveEarningPostingView extends Record {}
