<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;

/**
 * A photo or a file in a Chat message. Owned by the Social module.
 *
 * Photos sit under chat-photos/ and files under chat-files/ on the private
 * `local` disk, and they're only ever streamed to people in the
 * conversation. A file keeps the name it was uploaded with. Append-only:
 * deleted with its message, never edited.
 *
 * @property string $id
 * @property string $message_id
 * @property string $kind
 * @property string $path
 * @property string|null $name
 * @property string $mime
 * @property int $size
 * @property int $position
 * @property CarbonImmutable $created_at
 */
class ChatAttachment extends BaseModel
{
    /** Append-only: created_at is filled on insert, updated_at never written. */
    public const UPDATED_AT = null;

    /** @var string */
    protected $table = 'chat_attachments';

    /** @var list<string> */
    protected $fillable = [
        'message_id',
        'kind',
        'path',
        'name',
        'mime',
        'size',
        'position',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'size' => 'integer',
            'position' => 'integer',
            'created_at' => 'immutable_datetime',
        ];
    }
}
