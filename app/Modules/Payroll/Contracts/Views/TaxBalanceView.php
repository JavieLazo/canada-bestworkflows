<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Contracts\Views;

use App\Platform\ReadModels\Record;
use Carbon\CarbonImmutable;

/**
 * Detached PayrollTaxBalance data: what a tax year already carried for an
 * employee before this system posted it, with no persistence or lazy
 * relationships.
 *
 * @property-read string $id
 * @property-read string $employee_id
 * @property-read int $tax_year
 * @property-read string $kind
 * @property-read float $taxable_income
 * @property-read float $tax_withheld
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
final readonly class TaxBalanceView extends Record {}
