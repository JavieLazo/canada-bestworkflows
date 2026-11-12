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
 * @property-read string|null $year_end  CARRY or LAPSE; null before the column exists
 * @property-read string|null $filing  how the type is filed (LeaveTypeCredits::FILINGS); null before the column exists
 * @property-read float|null $allowance_days  the set days of a YEARLY or OCCASION type
 * @property-read string|null $day_count  WORKING or CALENDAR; null before the column exists
 * @property-read string|null $leave_details  which details of leave a request asks (LeaveApplication::SECTIONS); null before the column exists
 * @property-read float|null $cash_out_min_balance  the cash conversion limits (LeaveCashOutLimits); null is no limit, and before the columns exist
 * @property-read float|null $cash_out_min_days
 * @property-read float|null $cash_out_keep_days
 * @property-read float|null $cash_out_max_year
 * @property-read int|null $cash_out_per_year
 * @property-read int|null $cash_out_reason_share
 * @property-read bool|null $paid_on_separation  whether the balance is paid out when an employee leaves; null before the column exists
 * @property-read CarbonImmutable $created_at
 * @property-read CarbonImmutable|null $updated_at
 */
final readonly class LeaveTypeView extends Record {}
