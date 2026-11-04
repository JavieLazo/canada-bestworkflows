<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\ScopesToActor;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A body of work management runs; tasks hang off it.
 *
 * `status` (PLANNING | ACTIVE | ON_HOLD | COMPLETED | CANCELLED) stays free
 * text — an open set the UI extends.
 *
 * `manager_id` points at a `user_accounts` row, NOT an employee, and is NOT an
 * actor column: `ProjectInput` (src/lib/api/schemas.ts:84-95) accepts it from
 * the request body, because it names who is accountable rather than who wrote
 * the row. It also carries authority (DELIBERATE DIVERGENCE): the named
 * account — the "Task Leader" — sees the whole project and runs it
 * beside the Projects managers (WorkRules::runsProject()), which is why only
 * an account whose role can open Project Management may be named
 * (ProjectAccounts::canLead()).
 *
 * `code` and `name` are both unique — never write this table with `upsert()`.
 *
 * @property string $id
 * @property string $code
 * @property string $name
 * @property string|null $description
 * @property string|null $site_id
 * @property string $status
 * @property CarbonImmutable $start_date
 * @property CarbonImmutable|null $due_date
 * @property int|null $manager_id
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read WorkSite|null $site
 * @property-read Collection<int, ProjectTask> $tasks
 */
class Project extends BaseModel
{
    use ScopesToActor;

    /** @var string */
    protected $table = 'projects';

    /** @var list<string> */
    protected $fillable = [
        'code',
        'name',
        'description',
        'site_id',
        'status',
        'start_date',
        'due_date',
        'manager_id',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'start_date' => 'immutable_date:Y-m-d',
            'due_date' => 'immutable_date:Y-m-d',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<WorkSite, $this> */
    public function site(): BelongsTo
    {
        return $this->belongsTo(WorkSite::class, 'site_id');
    }

    /** @return HasMany<ProjectTask, $this> */
    public function tasks(): HasMany
    {
        return $this->hasMany(ProjectTask::class, 'project_id');
    }
}
