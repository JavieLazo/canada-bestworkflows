<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;

/**
 * One account's place in a Community group: MEMBER, or REQUESTED while a
 * private group has not let them in yet. `role` says who runs the group:
 * OWNER, MODERATOR or MEMBER. `added_by` is whoever put them forward, when
 * someone did. Owned by the Social module.
 *
 * `user_id`, `role` and `added_by` are always decided by the Social module,
 * never taken off a request, so none of them is mass-assignable.
 *
 * @property string $id
 * @property string $group_id
 * @property int $user_id
 * @property string $status
 * @property string $role
 * @property int|null $added_by
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable|null $updated_at
 */
class CommunityGroupMember extends BaseModel
{
    /** @var string */
    protected $table = 'community_group_members';

    /** @var list<string> */
    protected $fillable = [
        'group_id',
        'status',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'added_by' => 'integer',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
