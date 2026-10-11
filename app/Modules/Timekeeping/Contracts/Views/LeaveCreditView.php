<?php

declare(strict_types=1);

namespace App\Modules\Timekeeping\Contracts\Views;

use App\Platform\ReadModels\Record;
use Carbon\CarbonImmutable;

/**
 * Detached leave_credits data — one leave type's entitlement for one employee
 * in one year, as entered. No persistence, no lazy relations. The balance is
 * NOT here: see LeaveBalanceView, which the owner derives from the requests.
 *
 * @property-read string $id
 * @property-read string $employee_id
 * @property-read int $year
 * @property-read string $request_type The leave type's normalised key, the token requests store
 * @property-read float $credits
 * @property-read CarbonImmutable $created_at
 * @property-read CarbonImmutable|null $updated_at
 */
final readonly class LeaveCreditView extends Record {}
