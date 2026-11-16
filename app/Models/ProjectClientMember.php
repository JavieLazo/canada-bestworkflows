<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;

/**
 * @property string $id
 * @property string $client_id
 * @property int $user_id
 * @property CarbonImmutable $created_at
 */
class ProjectClientMember extends BaseModel
{
    public const UPDATED_AT = null;

    /** @var list<string> */
    protected $fillable = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['user_id' => 'integer', 'created_at' => 'immutable_datetime'];
    }
}
