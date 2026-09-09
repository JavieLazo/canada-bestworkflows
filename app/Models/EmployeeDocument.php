<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\ScopesToActor;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A file attached to a 201 file.
 *
 * The source stored the bytes inline as a data: URL in a `data` TEXT column.
 * MySQL TEXT is 65,535 *bytes* and a 2.8M-char base64 upload errors with 1406
 * (or, without strict mode, silently truncates and corrupts the file), so the
 * bytes live on disk and the row carries a `path` instead. `file_name`, `mime`
 * and `size` are unchanged, and `file_name` stays separate from `name` so
 * downloads keep the real extension.
 *
 * `uploaded_by` is an ACTOR column: it comes from the session and is
 * deliberately absent from `$fillable`.
 *
 * @property string $id
 * @property string $employee_id
 * @property string|null $document_no
 * @property string $name
 * @property string $category
 * @property CarbonImmutable|null $expires_on
 * @property string $file_name
 * @property string $mime
 * @property int $size
 * @property string $path
 * @property int|null $uploaded_by
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read EmployeeProfile|null $employee
 * @property-read User|null $uploader
 */
class EmployeeDocument extends BaseModel
{
    use ScopesToActor;

    /** @var string */
    protected $table = 'employee_documents';

    /** @var list<string> */
    protected $fillable = [
        'employee_id',
        'document_no',
        'name',
        'category',
        'expires_on',
        'file_name',
        'mime',
        'size',
        // Written by AttachmentStore from the stored file, never accepted from
        // a request body — the FormRequest allowlist does not contain it.
        'path',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'expires_on' => 'immutable_date:Y-m-d',
            'size' => 'integer',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<EmployeeProfile, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(EmployeeProfile::class, 'employee_id');
    }

    /** @return BelongsTo<User, $this> */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
