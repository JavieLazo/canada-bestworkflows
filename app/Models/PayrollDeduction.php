<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\IsReferenceList;
use Carbon\CarbonImmutable;

/**
 * The deduction labels Records Management maintains.
 *
 * @property string $id
 * @property string $code
 * @property string $name
 * @property string|null $description
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable|null $updated_at
 */
class PayrollDeduction extends BaseModel
{
    use IsReferenceList;

    /** @var string */
    protected $table = 'payroll_deductions';
}
