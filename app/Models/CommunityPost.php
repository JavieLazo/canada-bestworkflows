<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A Community post, to Everyone (`group_id` NULL) or to one group. Owned by
 * the Social module.
 *
 * `author_id` and `pinned_by` come from the session, `pinned_at` only from
 * a moderator's pin, and `edited_at` and `earlier_versions` only from its
 * author's edit, so none of the five is mass-assignable.
 *
 * `earlier_versions` is every version the post had before an edit, oldest
 * first: its text, when it went up ('Y-m-d H:i:s.v', UTC), its tag, and the
 * ids of its photos and files in order. A photo several versions share is
 * stored once. The versions ride on the post row, so the feed loads them
 * too, which is fine for a few edits each; give them their own table if
 * posts start collecting long histories.
 *
 * @property string $id
 * @property int|null $author_id
 * @property string|null $group_id
 * @property string|null $tag
 * @property string $body
 * @property CarbonImmutable|null $pinned_at
 * @property int|null $pinned_by
 * @property CarbonImmutable|null $edited_at
 * @property list<array{body: string, at: string, tag?: string|null, attachments?: list<string>}>|null $earlier_versions
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read CommunityGroup|null $group
 */
class CommunityPost extends BaseModel
{
    /** @var string */
    protected $table = 'community_posts';

    /** @var list<string> */
    protected $fillable = [
        'group_id',
        'tag',
        'body',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'author_id' => 'integer',
            'pinned_by' => 'integer',
            'pinned_at' => 'immutable_datetime',
            'edited_at' => 'immutable_datetime',
            'earlier_versions' => 'array',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<CommunityGroup, $this> */
    public function group(): BelongsTo
    {
        return $this->belongsTo(CommunityGroup::class, 'group_id');
    }

    /** @return HasMany<CommunityComment, $this> */
    public function comments(): HasMany
    {
        return $this->hasMany(CommunityComment::class, 'post_id');
    }

    /** @return HasMany<CommunityReaction, $this> */
    public function reactions(): HasMany
    {
        return $this->hasMany(CommunityReaction::class, 'post_id');
    }
}
