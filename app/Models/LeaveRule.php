<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;

/**
 * One dated version of a leave type's rule, which applies to EVERY
 * employee (`scope` = the type's token; LeaveTypeRule `params`).
 * SaveLeaveRule writes the version for the day it is saved, APPROVED at
 * once, in force from that day until a later version's; the readers walk
 * the versions so a past day still reads the rule it had. DRAFT is a value
 * the column allows and nothing writes. A company-wide setting with no
 * employee column, so it is not row-scoped.
 *
 * `prepared_by` and `approved_by` are ACTOR columns — both the account
 * that saved the version — taken from Access, never mass-assigned.
 *
 * @property string $id
 * @property string $scope
 * @property CarbonImmutable $effective_from
 * @property array<string, mixed> $params
 * @property string $status
 * @property int|null $prepared_by
 * @property string|null $prepared_by_name
 * @property int|null $approved_by
 * @property string|null $approved_by_name
 * @property CarbonImmutable|null $approved_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable|null $updated_at
 */
class LeaveRule extends BaseModel
{
    public const string DRAFT = 'DRAFT';

    public const string APPROVED = 'APPROVED';

    protected $table = 'leave_rules';

    protected $fillable = ['scope', 'effective_from', 'params', 'status'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'effective_from' => 'immutable_date:Y-m-d',
            'params' => 'array',
            'prepared_by' => 'integer',
            'approved_by' => 'integer',
            'approved_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
