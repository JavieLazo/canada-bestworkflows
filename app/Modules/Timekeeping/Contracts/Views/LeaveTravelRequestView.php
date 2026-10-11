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
 * @property-read CarbonImmutable $start_date
 * @property-read CarbonImmutable $end_date
 * @property-read string|null $reason
 * @property-read string $status
 * @property-read bool|null $is_paid
 * @property-read int|null $reviewed_by
 * @property-read CarbonImmutable|null $reviewed_at
 * @property-read CarbonImmutable $created_at
 * @property-read CarbonImmutable|null $updated_at
 * @property-read list<string> $cancelled_dates
 * @property-read list<string> $cancellable_dates
 * @property-read list<LeaveCancellationView> $cancellations
 */
final readonly class LeaveTravelRequestView extends Record {}
