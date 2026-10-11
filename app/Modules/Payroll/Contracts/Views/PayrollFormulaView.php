<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Contracts\Views;

use App\Platform\ReadModels\Record;
use Carbon\CarbonImmutable;

/**
 * Detached PayrollFormula data, with no persistence or lazy relationships.
 *
 * @property-read string $id
 * @property-read string $code
 * @property-read string $key
 * @property-read string $name
 * @property-read string $expression
 * @property-read list<array{key: string, label: string, value: float}> $params
 * @property-read CarbonImmutable $created_at
 * @property-read CarbonImmutable|null $updated_at
 */
final readonly class PayrollFormulaView extends Record {}
