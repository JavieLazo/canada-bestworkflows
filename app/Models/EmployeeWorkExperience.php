<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One previous job on an employee's Personal Data Sheet work experience.
 *
 * Rows of their own, one per job held before this one (migration
 * 2026_10_07_0006 says why), each identified by its id. The current job is
 * never a row here: the work experience list derives it from the record's
 * Employee Details. Written only by the Workforce commands, inside the
 * profile's transaction after its row is locked, and read only through
 * `PdsRows` / `EmployeeRecords`, behind the record's own visibility rule — so
 * this model carries no actor scope, like `EmployeeChild`. A row is edited in
 * place. `government_service` is NULL when not stated.
 *
 * @property string $id
 * @property string $employee_id
 * @property string $position_title
 * @property string $company
 * @property CarbonImmutable $date_from
 * @property CarbonImmutable $date_to
 * @property float|null $monthly_salary
 * @property string|null $pay_grade
 * @property string|null $appointment_status
 * @property bool|null $government_service
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read EmployeeProfile|null $employee
 */
class EmployeeWorkExperience extends BaseModel
{
    /** @var string */
    protected $table = 'employee_work_experiences';

    /** @var list<string> */
    protected $fillable = [
        'employee_id',
        'position_title',
        'company',
        'date_from',
        'date_to',
        'monthly_salary',
        'pay_grade',
        'appointment_status',
        'government_service',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'date_from' => 'immutable_date:Y-m-d',
            'date_to' => 'immutable_date:Y-m-d',
            'monthly_salary' => 'float',
            'government_service' => 'boolean',
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
