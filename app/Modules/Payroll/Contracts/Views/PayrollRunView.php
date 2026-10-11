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
 * @property-read CarbonImmutable|null $finalized_at
 * @property-read int|null $finalized_by
 * @property-read CarbonImmutable $created_at
 * @property-read CarbonImmutable|null $updated_at
 */
final readonly class PayrollRunView extends Record {}
