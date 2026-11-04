<?php

declare(strict_types=1);

namespace App\Modules\Timekeeping\Contracts\Views;

use App\Platform\ReadModels\Record;
use Carbon\CarbonImmutable;

/**
 * Detached LeaveType data, with no persistence or lazy relationships.
 *
 * @property-read string $id
 * @property-read string $code
 * @property-read string $name
 * @property-read string|null $description
 * @property-read string|null $token  the normalized request_type token, when the reader adds it
 * @property-read bool|null $earns_credits  the credit rules (LeaveTypeCredits), null before the columns exist
 * @property-read string|null $unit
 * @property-read bool|null $charge_late
 * @property-read bool|null $charge_absence
 * @property-read int|null $charge_order
 * @property-read float|null $below_zero_limit
 * @property-read bool|null $can_cash_out  whether the type's credits may be cashed out; null before the column exists
 * @property-read CarbonImmutable $created_at
 * @property-read CarbonImmutable|null $updated_at
 */
final readonly class LeaveTypeView extends Record {}
