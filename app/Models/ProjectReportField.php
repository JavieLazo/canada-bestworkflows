<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;

/**
 * @property string $id
 * @property string $project_id
 * @property string $entity_type
 * @property string $name
 * @property string $type
 * @property string|null $unit
 * @property string $visibility
 * @property bool $required
 * @property bool $total
 * @property bool $archived
 * @property array<string, mixed>|null $options
 * @property int $revision
 * @property array<int, array<string, mixed>>|null $history
 * @property int $created_by
 * @property int $updated_by
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable|null $updated_at
 */
class ProjectReportField extends BaseModel
{
    /** @var list<string> */
    protected $fillable = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['required' => 'boolean', 'total' => 'boolean', 'archived' => 'boolean', 'options' => 'array', 'revision' => 'integer', 'history' => 'array', 'created_by' => 'integer', 'updated_by' => 'integer', 'created_at' => 'immutable_datetime', 'updated_at' => 'immutable_datetime'];
    }
}
