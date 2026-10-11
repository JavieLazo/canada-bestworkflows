<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\ScopesToActor;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Where an employee works on ONE weekday: from home, or at a named site.
 *
 * `weekday` is 0 Sunday to 6 Saturday — the numbering `shifts.work_days`
 * and `CalendarDays::dayOfWeek` use. Each change adds a row from its
 * `effective_from` instead of overwriting one (2026_09_30_0006): the latest
 * row for a weekday on or before a date decides it (NULL = the baseline, in
 * effect from the earliest date), unless its `change_id` names a cancelled
 * change. NO row, or a row with NULL `kind`, means that weekday is the
 * Assigned Worksite from Employee Records (`employee_profiles.work_site_id`),
 * geofenced — the default for every weekday; a NULL-kind row is how a
 * weekday goes back to it from a date without erasing its history.
 *
 * `kind` is ONSITE | WFH (`Modules\Timekeeping\Domain\Roster\LocationKind`;
 * a plain string, as every enum-like column here is) or NULL, and `site_id`
 * is non-null exactly when the kind is ONSITE — `rloc_site_chk` and
 * `rloc_default_chk` on MySQL/MariaDB, the command everywhere.
 *
 * Hours are NOT here. The standing `shifts` row and weekday `roster_shifts`
 * overrides decide them independently of where the employee works.
 *
 * No `site()` relation on purpose: site names come through
 * `Organization\Contracts\OrganizationDirectory`, never by loading a WorkSite
 * from Timekeeping code. `employee()` exists for scoping and tests. Row
 * scoping widens on the roster list, ROSTER | EDTR | PAYROLL, exactly as
 * `shifts` does.
 *
 * @property string $id
 * @property string $employee_id
 * @property int $weekday
 * @property string|null $kind
 * @property string|null $site_id
 * @property CarbonImmutable|null $effective_from
 * @property string|null $change_id
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read EmployeeProfile|null $employee
 */
class RosterLocation extends BaseModel
{
    use ScopesToActor;

    /** @var string */
    protected $table = 'roster_locations';

    /** @var list<string> */
    protected $fillable = [
        'employee_id',
        'weekday',
        'kind',
        'site_id',
        'effective_from',
        'change_id',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'weekday' => 'integer',
            'effective_from' => 'immutable_date:Y-m-d',
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
