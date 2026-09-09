<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\ScopesToActor;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The brief handed down with a task, or the evidence sent back with a
 * submission. `kind` is BRIEF (from management) or SUBMISSION (from the
 * assignee), free text like every other pseudo-enum here.
 *
 * Stored exactly like `employee_documents` and drawing on the same storage
 * budget — which now means bytes on disk and a `path` column, not the source's
 * inline data: URL in a TEXT column.
 *
 * `uploaded_by` is an ACTOR column: session-supplied, never mass-assigned.
 *
 * @property string $id
 * @property string $task_id
 * @property string $kind
 * @property string $file_name
 * @property string $mime
 * @property int $size
 * @property string $path
 * @property int|null $uploaded_by
 * @property CarbonImmutable $created_at
 * @property-read ProjectTask|null $task
 * @property-read User|null $uploader
 */
class ProjectTaskFile extends BaseModel
{
    use ScopesToActor;

    /** The table has no updated_at column; created_at is still filled on insert. */
    public const UPDATED_AT = null;

    /** @var string */
    protected $table = 'project_task_files';

    /** @var list<string> */
    protected $fillable = [
        'task_id',
        'kind',
        'file_name',
        'mime',
        'size',
        // Written by AttachmentStore from the stored file, never from a body.
        'path',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'size' => 'integer',
            'created_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<ProjectTask, $this> */
    public function task(): BelongsTo
    {
        return $this->belongsTo(ProjectTask::class, 'task_id');
    }

    /** @return BelongsTo<User, $this> */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
