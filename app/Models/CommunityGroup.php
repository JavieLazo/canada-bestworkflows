<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A Community group: its own feed, public (anyone may join) or private
 * (joining is a request). Owned by the Social module.
 *
 * `created_by` is session-supplied, and the photo columns are only ever set
 * by the Social module once it has made the photo, so none of them is
 * mass-assignable.
 *
 * @property string $id
 * @property string $name
 * @property string|null $description
 * @property bool $is_private
 * @property string|null $photo_path
 * @property int|null $photo_size
 * @property int|null $created_by
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable|null $updated_at
 */
class CommunityGroup extends BaseModel
{
    /** @var string */
    protected $table = 'community_groups';

    /** @var list<string> */
    protected $fillable = [
        'name',
        'description',
        'is_private',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'is_private' => 'boolean',
            'photo_size' => 'integer',
            'created_by' => 'integer',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /** @return HasMany<CommunityGroupMember, $this> */
    public function members(): HasMany
    {
        return $this->hasMany(CommunityGroupMember::class, 'group_id');
    }
}
