<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;

/**
 * One account's reaction to a Chat message. One per person per message: a
 * different reaction replaces it, the same one again removes it.
 *
 * `user_id` is always the session's own account, so it is not mass-assignable.
 *
 * @property string $id
 * @property string $message_id
 * @property int $user_id
 * @property string $type
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable|null $updated_at
 */
class ChatReaction extends BaseModel
{
    /** @var string */
    protected $table = 'chat_reactions';

    /** @var list<string> */
    protected $fillable = [
        'message_id',
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
