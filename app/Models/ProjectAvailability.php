<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;

/**
 * @property string $id
 * @property string $employee_id
 * @property string $week_start
 * @property int|null $hours_hundredths
 * @property bool $shared
 * @property int $revision
 * @property int $updated_by
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class ProjectAvailability extends BaseModel
{
    /** @var string */
    protected $table = 'project_availability';

    /** @var list<string> */
    protected $fillable = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['hours_hundredths' => 'integer', 'shared' => 'boolean', 'revision' => 'integer', 'updated_by' => 'integer', 'created_at' => 'immutable_datetime', 'updated_at' => 'immutable_datetime'];
    }
}
