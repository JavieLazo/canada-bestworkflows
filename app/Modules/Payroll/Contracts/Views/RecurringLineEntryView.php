<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Contracts\Views;

use App\Platform\ReadModels\Record;
use Carbon\CarbonImmutable;

/**
 * Detached PayrollRecurringLineEntry data — one outside payment or
 * balance adjustment on a loan, with the line's catalog name copied
 * beside it — with no persistence or lazy relationships.
 *
 * @property-read string $id
 * @property-read string $line_id
 * @property-read string $line_name
 * @property-read string $employee_id
 * @property-read string $kind
 * @property-read float $amount
 * @property-read CarbonImmutable $entry_date
 * @property-read string $reason
 * @property-read string|null $reference
 * @property-read string $status
 * @property-read int|null $prepared_by
 * @property-read string|null $prepared_by_name
 * @property-read int|null $approved_by
 * @property-read string|null $approved_by_name
 * @property-read CarbonImmutable|null $approved_at
 * @property-read CarbonImmutable $created_at
 * @property-read CarbonImmutable|null $updated_at
 */
final readonly class RecurringLineEntryView extends Record {}
