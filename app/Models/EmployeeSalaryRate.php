<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\ScopesToActor;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One dated version of an employee's salary (employee_salary_rates,
 * 2026_11_02_0008): the Rate Per Month and the Rate Per Day in force from
 * `effective_from`, the reason and the paper behind it, and who recorded
 * and who approved it. APPROVED versions are the salary history payroll
 * prices each day by; a PENDING one is a past-dated change waiting for
 * review; a REJECTED one stays as the record of the refusal.
 * The profile columns hold the rates in force today, copied from the
 * version that has taken effect (`Workforce\Application\SalaryRates`).
 *
 * `prepared_by` and `approved_by` are actor columns: set from Access,
 * never fillable. Row scoping widens on EMPLOYEES and PAYROLL, the two
 * modules that read pay figures.
 *
 * Owner: Workforce.
 *
 * @property string $id
 * @property string $employee_id
 * @property CarbonImmutable $effective_from
 * @property float|null $monthly_rate
 * @property float|null $daily_rate
 * @property string $reason
 * @property string|null $reference
 * @property string $status
 * @property int|null $prepared_by
 * @property string|null $prepared_by_name
 * @property int|null $approved_by
 * @property string|null $approved_by_name
 * @property CarbonImmutable|null $approved_at
 * @property string|null $review_note
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read EmployeeProfile|null $employee
 */
class EmployeeSalaryRate extends BaseModel
{
    use ScopesToActor;

    public const string PENDING = 'PENDING';

    public const string APPROVED = 'APPROVED';

    public const string REJECTED = 'REJECTED';

    /** @var string */
    protected $table = 'employee_salary_rates';

    /** @var list<string> */
    protected $fillable = ['employee_id', 'effective_from', 'monthly_rate', 'daily_rate', 'reason', 'reference', 'status', 'review_note'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'effective_from' => 'immutable_date:Y-m-d',
            'monthly_rate' => 'float',
            'daily_rate' => 'float',
            'prepared_by' => 'integer',
            'approved_by' => 'integer',
            'approved_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<EmployeeProfile, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(EmployeeProfile::class, 'employee_id');
    }
}
