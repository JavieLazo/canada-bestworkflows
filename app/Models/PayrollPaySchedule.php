<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One named pay schedule (`App\Modules\Payroll\Domain\Schedules\PaySchedule`
 * reads `params`): how a month is cut into salary periods and when each is
 * paid. One row is the company's default, the one a new payroll starts
 * from. A regular payroll records which schedule gave it its dates
 * (`payroll_runs.schedule_id`).
 *
 * `is_default`, `created_by` and `created_by_name` are set by the command,
 * never filled.
 *
 * @property string $id
 * @property string $name
 * @property array<string, mixed> $params
 * @property bool $is_default
 * @property int|null $created_by
 * @property string|null $created_by_name
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable|null $updated_at
 */
class PayrollPaySchedule extends BaseModel
{
    /** @var string */
    protected $table = 'payroll_pay_schedules';

    /** @var list<string> */
    protected $fillable = ['name', 'params'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'params' => 'array',
            'is_default' => 'boolean',
            'created_by' => 'integer',
        ];
    }

    /**
     * The payrolls that took their dates from this schedule.
     *
     * @return HasMany<PayrollRun, $this>
     */
    public function runs(): HasMany
    {
        return $this->hasMany(PayrollRun::class, 'schedule_id');
    }
}
