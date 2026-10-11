<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One message in a Chat conversation. TEXT from a person, or SYSTEM ("created
 * the group") with no sender. Removing a message keeps the row with
 * `removed_at` set and the body cleared, so the thread shows a placeholder.
 *
 * `sender_id` comes from the session, so it is not mass-assignable.
 *
 * @property string $id
 * @property string $conversation_id
 * @property int|null $sender_id
 * @property string $kind
 * @property string|null $body
 * @property string|null $reply_to_id
 * @property CarbonImmutable|null $removed_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable|null $updated_at
 */
class ChatMessage extends BaseModel
{
    /** @var string */
    protected $table = 'chat_messages';

    /** @var list<string> */
    protected $fillable = [
        'conversation_id',
        'kind',
        'body',
        'reply_to_id',
        'removed_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'sender_id' => 'integer',
            'removed_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /** @return HasMany<ChatAttachment, $this> */
    public function attachments(): HasMany
    {
        return $this->hasMany(ChatAttachment::class, 'message_id')->orderBy('position');
    }

    /** @return HasMany<ChatReaction, $this> */
    public function reactions(): HasMany
    {
        return $this->hasMany(ChatReaction::class, 'message_id');
    }
}
