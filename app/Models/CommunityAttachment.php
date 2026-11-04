<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;

/**
 * A photo or a file on a Community post or comment. Owned by the Social
 * module.
 *
 * Every row carries its post, a comment's too, so whatever decides who may
 * read the post decides who may open the attachment. Photos sit under
 * community-photos/ and files under community-files/ on the private `local`
 * disk, and they're only ever streamed through a permission check. A file
 * keeps the name it was uploaded with. Append-only, apart from `removed_at`
 * and `position`: an edit can take a post's photo or file off, where it
 * stays for the post's edit history, or bring one back, and every edit
 * numbers what's on the post afresh from 0, in the order it shows. A row
 * taken off goes with its post or comment like the rest.
 *
 * @property string $id
 * @property string $post_id
 * @property string|null $comment_id
 * @property string $kind
 * @property string $path
 * @property string|null $name
 * @property string $mime
 * @property int $size
 * @property int $position
 * @property CarbonImmutable|null $removed_at
 * @property CarbonImmutable $created_at
 */
class CommunityAttachment extends BaseModel
{
    /** Append-only: created_at is filled on insert, updated_at never written. */
    public const UPDATED_AT = null;

    /** @var string */
    protected $table = 'community_attachments';

    /** @var list<string> */
    protected $fillable = [
        'post_id',
        'comment_id',
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
            'removed_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }
}
