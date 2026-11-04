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
 * budget — which here means bytes on disk and a `path` column, not the source's
 * inline data: URL in a TEXT column.
 *
 * `uploaded_by` is an ACTOR column: session-supplied, never mass-assigned.
 *
 * `employee_id` names the MEMBER a SUBMISSION file belongs to, which is how
 * an INDIVIDUAL task tells one assignee's evidence from another's. The upload
 * writer sets it by property from the signed-in actor's own employee link,
 * never from input, so it is not in $fillable either. It is null on BRIEF
 * files and on any SUBMISSION file that names no member (2026_09_24_0003
 * has no backfill), and it outlives the member's place on the crew: a
 * removed assignee's files stay with the task.
 *
 * @property string $id
 * @property string $task_id
 * @property string|null $employee_id
 * @property string $kind
 * @property string $file_name
 * @property string $mime
 * @property int $size
 * @property string $path
 * @property int|null $uploaded_by
 * @property CarbonImmutable $created_at
 * @property-read ProjectTask|null $task
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
}
