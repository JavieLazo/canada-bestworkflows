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
 * @property-read list<string>|null $unpaid_dates the dates an approval with pay left without pay because the credits or set days ran short; null when none, and absent before 2026_11_07_0002
 * @property-read string|null $disapproval_reason why a disapproved (REJECTED) request was refused; null on every other row, and absent before 2026_11_08_0001
 * @property-read string|null $approval_note the application's "others (specify)" written on approving; null otherwise, and absent before 2026_11_11_0003
 * @property-read string|null $leave_detail the details of leave picked (LeaveApplication); null when none, and absent before 2026_11_08_0003
 * @property-read string|null $leave_detail_note what the detail specifies: where abroad, or the illness
 * @property-read string|null $commutation NOT_REQUESTED or REQUESTED on a leave; null on official business and before the column
 * @property-read string|null $recommendation FOR_APPROVAL or FOR_DISAPPROVAL; null while none is recorded, and absent before 2026_11_08_0004
 * @property-read string|null $recommendation_reason why disapproval was recommended
 * @property-read int|null $recommended_by
 * @property-read CarbonImmutable|null $recommended_at
 * @property-read AccountLabel|null $recommender who recorded the recommendation
 * @property-read CarbonImmutable $created_at
 * @property-read CarbonImmutable|null $updated_at
 * @property-read list<string> $cancelled_dates
 * @property-read list<string> $cancellable_dates
 * @property-read list<LeaveCancellationView> $cancellations
 */
final readonly class LeaveTravelRequestView extends Record {}
