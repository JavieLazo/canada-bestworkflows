<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Contracts\Views;

use App\Platform\ReadModels\Record;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Detached PayrollRun data, with no persistence or lazy relationships.
 *
 * @property-read string $id
 * @property-read int $members_count
 * @property-read Collection<int, PayrollRunMemberView> $members
 * @property-read string $code
 * @property-read string $name
 * @property-read CarbonImmutable $period_start
 * @property-read CarbonImmutable $period_end
 * @property-read string $status
 * @property-read string $kind  REGULAR | THIRTEENTH_MONTH (PayrollRunKind)
 * @property-read CarbonImmutable|null $attendance_through  the advance payroll's counted date (PayrollAdvance); null reads the whole period
 * @property-read string|null $schedule_id  the pay schedule the dates came from; null on a run made without one or before schedules existed
 * @property-read CarbonImmutable|null $pay_date  when the pay goes out; null on a legacy run
 * @property-read string|null $contribution_month  `YYYY-MM`; null reads the period's first day
 * @property-read CarbonImmutable|null $finalized_at
 * @property-read int|null $finalized_by
 * @property-read CarbonImmutable $created_at
 * @property-read CarbonImmutable|null $updated_at
 */
final readonly class PayrollRunView extends Record {}
