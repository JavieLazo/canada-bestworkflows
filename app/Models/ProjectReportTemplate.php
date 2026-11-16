<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;

/**
 * @property string $id
 * @property string $project_id
 * @property string|null $code
 * @property string $name
 * @property string $kind
 * @property string|null $description
 * @property array{sections: list<string>, fields: list<string>} $definition
 * @property bool $work
 * @property bool $financial
 * @property bool $archived
 * @property int $revision
 * @property array<int, array<string, mixed>>|null $history
 * @property int $created_by
 * @property int $updated_by
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable|null $updated_at
 */
class ProjectReportTemplate extends BaseModel
{
    /** @var list<string> */
    protected $fillable = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['definition' => 'array', 'work' => 'boolean', 'financial' => 'boolean', 'archived' => 'boolean', 'revision' => 'integer', 'history' => 'array', 'created_by' => 'integer', 'updated_by' => 'integer', 'created_at' => 'immutable_datetime', 'updated_at' => 'immutable_datetime'];
    }
}
