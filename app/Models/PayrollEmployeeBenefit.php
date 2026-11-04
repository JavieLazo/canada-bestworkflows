<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\ScopesToActor;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A scheduled Incentives & Bonuses type assigned to one employee for a
 * span of dates (payroll_employee_benefits, 2026_11_02_0010): from
 * `starts_on` through `ends_on` (NULL = open), with an amount of their own
 * (NULL = the type's rule), the reason and the paper behind it. A DRAFT
 * waits for approval; an APPROVED assignment proposes
 * the benefit on every payroll inside its span, whether or not the type's
 * status rule qualifies the employee (BenefitAssignments).
 *
 * `prepared_by` and `approved_by` are actor columns: set from Access,
 * never fillable. Row scoping widens on PAYROLL alone, like the payslips.
 *
 * Owner: Payroll.
 *
 * @property string $id
 * @property string $employee_id
 * @property string $type_id
 * @property CarbonImmutable $starts_on
 * @property CarbonImmutable|null $ends_on
 * @property float|null $amount
 * @property string $reason
 * @property string|null $reference
 * @property string $status
 * @property int|null $prepared_by
 * @property string|null $prepared_by_name
 * @property int|null $approved_by
 * @property string|null $approved_by_name
 * @property CarbonImmutable|null $approved_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read PayrollIncentiveType|null $type
 */
class PayrollEmployeeBenefit extends BaseModel
{
    use ScopesToActor;

    public const string DRAFT = 'DRAFT';

    public const string APPROVED = 'APPROVED';

    /** @var string */
    protected $table = 'payroll_employee_benefits';

    /** @var list<string> */
    protected $fillable = ['employee_id', 'type_id', 'starts_on', 'ends_on', 'amount', 'reason', 'reference', 'status'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'starts_on' => 'immutable_date:Y-m-d',
            'ends_on' => 'immutable_date:Y-m-d',
            'amount' => 'float',
            'prepared_by' => 'integer',
            'approved_by' => 'integer',
            'approved_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<PayrollIncentiveType, $this> */
    public function type(): BelongsTo
    {
        return $this->belongsTo(PayrollIncentiveType::class, 'type_id');
    }
}
