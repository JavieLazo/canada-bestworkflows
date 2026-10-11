<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;

/**
 * One dated version of the Pay Basis & Rates for one scope — `COMPANY`,
 * or an Employment list entry's id whose employees it overrides — in force
 * from `effective_from`, with the figures `App\Domain\Payroll\PayRules`
 * reads (`params`) and the reason the officer typed. A version's figures
 * never change once saved: a change is a new version.
 *
 * `created_by` and `created_by_name` are set by the command, never filled.
 *
 * @property string $id
 * @property string $scope
 * @property CarbonImmutable $effective_from
 * @property string|null $reason
 * @property array<string, mixed> $params
 * @property int|null $created_by
 * @property string|null $created_by_name
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable|null $updated_at
 */
class PayrollPayRule extends BaseModel
{
    public const string COMPANY = 'COMPANY';

    /** @var string */
    protected $table = 'payroll_pay_rules';

    /** @var list<string> */
    protected $fillable = ['scope', 'effective_from', 'reason', 'params'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'effective_from' => 'immutable_date:Y-m-d',
            'params' => 'array',
        ];
    }
}
