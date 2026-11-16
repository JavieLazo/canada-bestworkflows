<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;

/**
 * @property string $id
 * @property string $project_id
 * @property int $user_id
 * @property string $role
 * @property string|null $position
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable|null $updated_at
 */
class ProjectMember extends BaseModel
{
    /** @var list<string> */
    protected $fillable = ['role', 'position'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['user_id' => 'integer', 'created_at' => 'immutable_datetime', 'updated_at' => 'immutable_datetime'];
    }
}
