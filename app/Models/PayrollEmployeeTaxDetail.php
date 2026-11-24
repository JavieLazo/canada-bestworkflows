<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;

/**
 * One employee's tax details: the province of employment, the claim amounts
 * from their personal tax credits returns, any extra tax to deduct each pay,
 * and whether the pension plan and the insurance apply to them. One row per
 * employee, changed in place; an employee with none is at the starting
 * position (`App\Modules\Payroll\Domain\Canada\TaxDetails::starting()`).
 *
 * Read and written only by Payroll: `EmployeeTaxDetails` for a payroll's
 * capture, `SaveEmployeeTaxDetails` for the one writer.
 *
 * `updated_by` and `updated_by_name` are set by the command, never filled.
 *
 * @property string $id
 * @property string $employee_id
 * @property string $province
 * @property float|null $federal_claim
 * @property float|null $provincial_claim
 * @property float $additional_tax
 * @property bool $deduct_pension
 * @property bool $deduct_insurance
 * @property int|null $updated_by
 * @property string|null $updated_by_name
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable|null $updated_at
 */
class PayrollEmployeeTaxDetail extends BaseModel
{
    /** @var string */
    protected $table = 'payroll_employee_tax_details';

    /** @var list<string> */
    protected $fillable = ['employee_id', 'province', 'federal_claim', 'provincial_claim', 'additional_tax', 'deduct_pension', 'deduct_insurance'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'federal_claim' => 'float',
            'provincial_claim' => 'float',
            'additional_tax' => 'float',
            'deduct_pension' => 'boolean',
            'deduct_insurance' => 'boolean',
        ];
    }
}
