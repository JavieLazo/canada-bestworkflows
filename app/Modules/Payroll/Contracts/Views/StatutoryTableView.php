<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Contracts\Views;

use App\Platform\ReadModels\Record;
use Carbon\CarbonImmutable;

/**
 * Detached PayrollStatutoryTable data: one dated version of one
 * Contribution & Tax Table, with no persistence or lazy relationships.
 *
 * @property-read string $id
 * @property-read string $kind
 * @property-read CarbonImmutable $effective_from
 * @property-read CarbonImmutable $covered_through
 * @property-read string|null $reference
 * @property-read array<string, mixed> $params
 * @property-read int|null $created_by
 * @property-read string|null $created_by_name
 * @property-read CarbonImmutable $created_at
 * @property-read CarbonImmutable|null $updated_at
 */
final readonly class StatutoryTableView extends Record {}
