<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\ScopesToActor;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One message on a task's thread. Append-only, which is the whole point: a task
 * that bounces twice keeps both exchanges, where the single `remarks` /
 * `review_remarks` columns only ever hold the latest.
 *
 * `author_name` is denormalised so a deleted account still leaves a readable
 * thread — which is why `author_id` is SET NULL rather than CASCADE. Both are
 * written from the session, so NEITHER is mass-assignable: set them explicitly
 * on the model after `make()`/`fill()`.
 *
 * `side` is MANAGEMENT or ASSIGNEE — which side of the task spoke. `event` is
 * set when the comment accompanies a status change, e.g. "RETURNED".
 *
 * Ordering is by `(task_id, created_at)` at DATETIME(3): dropping the
 * millisecond precision reorders same-second replies.
 *
 * @property string $id
 * @property string $task_id
 * @property int|null $author_id
 * @property string $author_name
 * @property string $side
 * @property string $body
 * @property string|null $event
 * @property CarbonImmutable $created_at
 * @property-read ProjectTask|null $task
 * @property-read User|null $author
 */
class TaskComment extends BaseModel
{
    use ScopesToActor;

    /** Append-only: created_at is filled on insert, updated_at never written. */
    public const UPDATED_AT = null;

    /** @var string */
    protected $table = 'task_comments';

    /** @var list<string> */
    protected $fillable = [
        'task_id',
        'side',
        'body',
        'event',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'created_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<ProjectTask, $this> */
    public function task(): BelongsTo
    {
        return $this->belongsTo(ProjectTask::class, 'task_id');
    }

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }
}
