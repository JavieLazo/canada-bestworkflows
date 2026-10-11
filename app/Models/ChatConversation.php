<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A Chat conversation: DIRECT (two people, one per pair through `direct_key`)
 * or GROUP (a title and any number of people). Owned by the Social module.
 *
 * `created_by` is session-supplied, and the photo columns are only ever set
 * by the Social module once it has made the photo, so none of them is
 * mass-assignable.
 *
 * @property string $id
 * @property string $kind
 * @property string|null $title
 * @property string|null $photo_path
 * @property int|null $photo_size
 * @property string|null $direct_key
 * @property int|null $created_by
 * @property CarbonImmutable|null $last_message_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable|null $updated_at
 */
class ChatConversation extends BaseModel
{
    /** @var string */
    protected $table = 'chat_conversations';

    /** @var list<string> */
    protected $fillable = [
        'kind',
        'title',
        'direct_key',
        'last_message_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'created_by' => 'integer',
            'photo_size' => 'integer',
            'last_message_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /** @return HasMany<ChatParticipant, $this> */
    public function participants(): HasMany
    {
        return $this->hasMany(ChatParticipant::class, 'conversation_id');
    }

    /** @return HasMany<ChatMessage, $this> */
    public function messages(): HasMany
    {
        return $this->hasMany(ChatMessage::class, 'conversation_id');
    }
}
