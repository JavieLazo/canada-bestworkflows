<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;

/**
 * One dated version of an employee's Contributions & tax policy (the
 * figures `App\Modules\Payroll\Domain\Deductions\GovDeductionSettings`
 * reads, `params`): prepared as a DRAFT by one person, APPROVED by
 * another, in force from its effective date until a later approved
 * version's, and never changed once approved. Pay data: read by all-data
 * PAYROLL holders only, through the Payroll queries.
 *
 * `prepared_by`, `approved_by` and their names are set by the commands,
 * never filled.
 *
 * @property string $id
 * @property string $employee_id
 * @property CarbonImmutable $effective_from
 * @property array<string, mixed> $params
 * @property string $reason
 * @property string $status
 * @property int|null $prepared_by
 * @property string|null $prepared_by_name
 * @property int|null $approved_by
 * @property string|null $approved_by_name
 * @property CarbonImmutable|null $approved_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable|null $updated_at
 */
class PayrollGovDeductionPolicy extends BaseModel
{
    public const string DRAFT = 'DRAFT';

    public const string APPROVED = 'APPROVED';

    /** @var string */
    protected $table = 'payroll_gov_deduction_policies';

    /** @var list<string> */
    protected $fillable = ['employee_id', 'effective_from', 'params', 'reason', 'status'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'effective_from' => 'immutable_date:Y-m-d',
            'params' => 'array',
            'prepared_by' => 'integer',
            'approved_by' => 'integer',
            'approved_at' => 'immutable_datetime',
        ];
    }
}
