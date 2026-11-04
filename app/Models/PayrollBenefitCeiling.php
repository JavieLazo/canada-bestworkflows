<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;

/**
 * One dated version of the yearly tax-free ceiling on exempt benefits and
 * the 13th month pay (payroll_benefit_ceilings, 2026_11_02_0010): the
 * pesos in force from `effective_from` until a later version's. The
 * engine carried 90,000 as a constant (`ExemptBenefitCeiling::CEILING`);
 * the seeded version holds it from 2025-01-01. `created_by` is an actor
 * column: set from Access, never fillable.
 *
 * Owner: Payroll. Read by every payroll holder, written by all-data
 * PAYROLL (SaveBenefitCeiling).
 *
 * @property string $id
 * @property CarbonImmutable $effective_from
 * @property float $ceiling
 * @property string $reason
 * @property int|null $created_by
 * @property string|null $created_by_name
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable|null $updated_at
 */
class PayrollBenefitCeiling extends BaseModel
{
    /** @var string */
    protected $table = 'payroll_benefit_ceilings';

    /** @var list<string> */
    protected $fillable = ['effective_from', 'ceiling', 'reason'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'effective_from' => 'immutable_date:Y-m-d',
            'ceiling' => 'float',
            'created_by' => 'integer',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
