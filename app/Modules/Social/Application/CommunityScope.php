<?php

declare(strict_types=1);

namespace App\Modules\Social\Application;

use App\Domain\Enums\ModuleCode;
use App\Models\CommunityGroup;
use App\Models\CommunityGroupMember;
use App\Models\CommunityPost;
use App\Modules\IdentityAccess\Contracts\ModuleAccounts;
use App\Modules\Social\Domain\Vocabulary;
use App\Support\Access;
use Illuminate\Database\Eloquent\Builder;

/**
 * Which posts an account may see, written once so the feed, a single post,
 * a photo and every action agree.
 *
 *  - Reading: posts to Everyone, posts in public groups, and posts in
 *    private groups the account has joined. A request to join still reads
 *    nothing, because a private group shows nothing to someone outside it.
 *  - The feed: narrower than what may be read. Everyone, plus the account's
 *    own groups. A public group they haven't joined is one click away rather
 *    than in the way.
 *  - Taking part: reacting or commenting on a group's post takes being in
 *    the group. Anyone may read a public group; joining is how you join in.
 */
final class CommunityScope
{
    private function __construct() {}

    /** @return Builder<CommunityPost> */
    public static function readable(int $userId): Builder
    {
        return CommunityPost::query()->where(static function (Builder $q) use ($userId): void {
            $q->whereNull('group_id')
                ->orWhereIn('group_id', CommunityGroup::query()->where('is_private', false)->select('id'))
                ->orWhereIn('group_id', self::memberships($userId)->select('group_id'));
        });
    }

    /** @return Builder<CommunityPost> */
    public static function feed(int $userId): Builder
    {
        return CommunityPost::query()->where(static function (Builder $q) use ($userId): void {
            $q->whereNull('group_id')
                ->orWhereIn('group_id', self::memberships($userId)->select('group_id'));
        });
    }

    /** A post the account may read, or null. */
    public static function post(int $userId, string $postId): ?CommunityPost
    {
        return self::readable($userId)->whereKey($postId)->first();
    }

    public static function canInteract(int $userId, CommunityPost $post): bool
    {
        return $post->group_id === null || self::isMember($userId, $post->group_id);
    }

    public static function isMember(int $userId, string $groupId): bool
    {
        return self::memberships($userId)->where('group_id', $groupId)->exists();
    }

    /** @return list<string> */
    public static function groupIds(int $userId): array
    {
        return array_values(array_map('strval', self::memberships($userId)->pluck('group_id')->all()));
    }

    /**
     * Of the given accounts, those who can read a post in this audience:
     * active accounts that hold Community, and for a private group, its
     * members. Who gets told about a post never outruns who may open it.
     *
     * @param  list<int>  $ids
     * @return list<int>
     */
    public static function audience(Access $actor, array $ids, ?string $groupId): array
    {
        $ids = app(ModuleAccounts::class)->filter($actor, ModuleCode::COMMUNITY, $ids);

        if ($ids === [] || $groupId === null || ! (bool) CommunityGroup::query()->whereKey($groupId)->value('is_private')) {
            return $ids;
        }

        return array_values(array_map(
            static fn (mixed $id): int => (int) $id,
            CommunityGroupMember::query()
                ->where('group_id', $groupId)
                ->where('status', Vocabulary::MEMBER)
                ->whereIn('user_id', $ids)
                ->pluck('user_id')
                ->all(),
        ));
    }

    /** @return Builder<CommunityGroupMember> */
    private static function memberships(int $userId): Builder
    {
        return CommunityGroupMember::query()->where('user_id', $userId)->where('status', Vocabulary::MEMBER);
    }
}
