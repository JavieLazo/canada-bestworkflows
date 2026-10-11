<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;

/**
 * A tag a post can carry, like Kudos or Question. Moderators manage the list.
 *
 * `code` is what a post stores. It's set once, when the tag is made, so a
 * rename changes the name and leaves every post alone. `created_by` comes
 * from the session, so neither is mass-assignable.
 *
 * @property string $id
 * @property string $code
 * @property string $name
 * @property string $tone
 * @property int $position
 * @property int|null $created_by
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable|null $updated_at
 */
class CommunityTag extends BaseModel
{
    /** @var string */
    protected $table = 'community_tags';

    /** @var list<string> */
    protected $fillable = [
        'name',
        'tone',
        'position',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'created_by' => 'integer',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
