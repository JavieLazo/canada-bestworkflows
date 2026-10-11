<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;

/**
 * One account's reaction to a Community comment or reply. Same six as posts,
 * and the same rule: one per person, a different one replaces it, the same
 * one again removes it.
 *
 * `user_id` is always the session's own account, so it is not mass-assignable.
 *
 * @property string $id
 * @property string $comment_id
 * @property int $user_id
 * @property string $type
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable|null $updated_at
 */
class CommunityCommentReaction extends BaseModel
{
    /** @var string */
    protected $table = 'community_comment_reactions';

    /** @var list<string> */
    protected $fillable = [
        'comment_id',
        'type',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
