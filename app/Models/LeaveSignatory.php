<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;

/**
 * One officer named on the Application for Leave: "Certified by" or
 * "Approved by" (`slot`, `App\Modules\Timekeeping\Domain\Absence\LeaveSignatorySlot`):
 * the employee who signs it, whose name and position the form reads from
 * Employee Records, or on a row set before employees could be picked the name
 * and designation typed. The account that last set it is kept by id and by
 * name. Nothing here approves a leave.
 *
 * `updated_by` and `updated_by_name` are set by the command, never filled.
 *
 * @property string $id
 * @property string $slot
 * @property string|null $employee_id
 * @property string|null $name
 * @property string|null $designation
 * @property int|null $updated_by
 * @property string|null $updated_by_name
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable|null $updated_at
 */
class LeaveSignatory extends BaseModel
{
    /** @var string */
    protected $table = 'leave_signatories';

    /** @var list<string> */
    protected $fillable = ['slot', 'employee_id', 'name', 'designation'];
}
