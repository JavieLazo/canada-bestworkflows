<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A comment on a Community post, or a reply to one (`parent_id`). Threads go
 * three levels deep: a comment, a reply, and a reply to that. Its author can
 * edit the text, and `edited_at` says when.
 *
 * `author_id` comes from the session and `edited_at` only from its author's
 * edit, so neither is mass-assignable.
 *
 * @property string $id
 * @property string $post_id
 * @property string|null $parent_id
 * @property int|null $author_id
 * @property string $body
 * @property CarbonImmutable|null $edited_at
 * @property CarbonImmutable $created_at
 */
class CommunityComment extends BaseModel
{
    /** There's no updated_at column: created_at is filled on insert, and an edit stamps edited_at instead. */
    public const UPDATED_AT = null;

    /** @var string */
    protected $table = 'community_comments';

    /** @var list<string> */
    protected $fillable = [
        'post_id',
        'parent_id',
        'body',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'author_id' => 'integer',
            'edited_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }

    /** @return HasMany<CommunityCommentReaction, $this> */
    public function reactions(): HasMany
    {
        return $this->hasMany(CommunityCommentReaction::class, 'comment_id');
    }
}
