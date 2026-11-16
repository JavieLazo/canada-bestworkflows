<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;

/**
 * @property string $id
 * @property string $project_id
 * @property string $entity_type
 * @property string $record_id
 * @property string $file_name
 * @property string $path
 * @property string $mime_type
 * @property int $file_size
 * @property string $sha256
 * @property int $uploaded_by
 * @property CarbonImmutable|null $removed_at
 * @property CarbonImmutable|null $retained_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable|null $updated_at
 */
class ProjectRecordFile extends BaseModel
{
    /** @var list<string> */
    protected $fillable = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['file_size' => 'integer', 'uploaded_by' => 'integer', 'removed_at' => 'immutable_datetime', 'retained_at' => 'immutable_datetime', 'created_at' => 'immutable_datetime', 'updated_at' => 'immutable_datetime'];
    }
}
