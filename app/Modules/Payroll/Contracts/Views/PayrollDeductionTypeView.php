<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Contracts\Views;

use App\Platform\ReadModels\Record;

/**
 * A detached custom-deduction choice; payroll never gives the screen its model.
 *
 * @property-read string $id
 * @property-read string $code
 * @property-read string $name
 * @property-read array<string, mixed>|null $percent
 */
final readonly class PayrollDeductionTypeView extends Record {}
