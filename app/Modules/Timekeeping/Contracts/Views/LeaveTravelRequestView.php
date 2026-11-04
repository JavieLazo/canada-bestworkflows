<?php

declare(strict_types=1);

namespace App\Modules\Timekeeping\Contracts\Views;

use App\Modules\IdentityAccess\Contracts\AccountLabel;
use App\Modules\Workforce\Contracts\DirectoryEmployee;
use App\Platform\ReadModels\Record;
use Carbon\CarbonImmutable;

/**
 * Detached LeaveTravelRequest data, with no persistence or lazy relationships.
 *
 * @property-read DirectoryEmployee|null $employee
 * @property-read AccountLabel|null $reviewer
 * @property-read string $id
 * @property-read string $code
 * @property-read string $employee_id
 * @property-read string $request_type
 * @property-read string|null $day_type
 * @property-read string|null $half AM or PM on a half day that says which half is off; null otherwise, and absent before 2026_11_02_0015
 * @property-read CarbonImmutable $start_date
 * @property-read CarbonImmutable $end_date
 * @property-read string|null $reason
 * @property-read string $status
 * @property-read bool|null $is_paid
 * @property-read int|null $reviewed_by
 * @property-read CarbonImmutable|null $reviewed_at
 * @property-read int|null $credits_year the year an approved paid leave draws on; null reads the start year
 * @property-read float|null $credit_days the working days the approval charged; null counts calendar days
 * @property-read list<string>|null $credit_dates the dates `credit_days` counts
 * @property-read CarbonImmutable $created_at
 * @property-read CarbonImmutable|null $updated_at
 * @property-read list<string> $cancelled_dates
 * @property-read list<string> $cancellable_dates
 * @property-read list<LeaveCancellationView> $cancellations
 */
final readonly class LeaveTravelRequestView extends Record {}
