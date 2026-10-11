<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;

/**
 * One account in one Chat conversation, with that person's own mute switch
 * and read position (`last_read_at`: the newest message they have seen).
 * In a group, `role` is ADMIN for the people who can remove others.
 *
 * Muted comes two ways: everything (`allow_mentions` off), or everything but
 * the messages that mention them (`allow_mentions` on). `allow_mentions`
 * means nothing while `is_muted` is off.
 *
 * `user_id` and `role` are decided by the Social module, never taken off a
 * request, so neither is mass-assignable.
 *
 * @property string $id
 * @property string $conversation_id
 * @property int $user_id
 * @property string $role
 * @property bool $is_muted
 * @property bool $allow_mentions
 * @property CarbonImmutable|null $last_read_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable|null $updated_at
 */
class ChatParticipant extends BaseModel
{
    /** @var string */
    protected $table = 'chat_participants';

    /** @var list<string> */
    protected $fillable = [
        'conversation_id',
        'is_muted',
        'allow_mentions',
        'last_read_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'is_muted' => 'boolean',
            'allow_mentions' => 'boolean',
            'last_read_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
