<?php

declare(strict_types=1);

namespace App\Modules\Social\Application\Queries;

use App\Domain\Enums\ModuleCode;
use App\Models\CommunityAttachment;
use App\Models\CommunityComment;
use App\Models\CommunityCommentReaction;
use App\Models\CommunityGroup;
use App\Models\CommunityGroupMember;
use App\Models\CommunityPost;
use App\Models\CommunityReaction;
use App\Models\CommunityTag;
use App\Modules\IdentityAccess\Contracts\AccountNames;
use App\Modules\IdentityAccess\Contracts\ModuleAccounts;
use App\Modules\Social\Application\Attachments;
use App\Modules\Social\Application\CommunityScope;
use App\Modules\Social\Application\People;
use App\Modules\Social\Application\Privacy;
use App\Modules\Social\Application\SocialRules;
use App\Modules\Social\Contracts\FeedFilter;
use App\Modules\Social\Contracts\Views\CelebrationView;
use App\Modules\Social\Contracts\Views\CommentView;
use App\Modules\Social\Contracts\Views\FeedPage;
use App\Modules\Social\Contracts\Views\FileView;
use App\Modules\Social\Contracts\Views\GroupView;
use App\Modules\Social\Contracts\Views\MemberView;
use App\Modules\Social\Contracts\Views\PersonView;
use App\Modules\Social\Contracts\Views\PostView;
use App\Modules\Social\Contracts\Views\TagView;
use App\Modules\Social\Domain\Vocabulary;
use App\Modules\Workforce\Contracts\Colleague;
use App\Modules\Workforce\Contracts\ColleagueDirectory;
use App\Platform\Files\PortraitUrl;
use App\Platform\ReadModels\SqlText;
use App\Support\Access;
use App\Support\EmployeeName;
use App\Support\ManilaClock;
use App\Support\SocialFormat;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Everything the Community screen reads, through CommunityScope so the feed,
 * one post, a photo and every action agree on who sees what.
 *
 * A screenful is built in a fixed number of queries however many posts it
 * holds: reactions, comments, attachments and people are each fetched once
 * for the whole page, never once per card.
 */
final class CommunityFeed
{
    /** Posts per screenful; "Show more" adds another. */
    public const int PAGE = 10;

    /** How much of a group's shared photos and files its panel lists. */
    public const int MEDIA = 60;

    public function viewer(Access $actor): PersonView
    {
        return app(People::class)->card($actor, SocialRules::community($actor));
    }

    /**
     * The main feed, or one group's, narrowed by whatever the filter holds.
     * `$groupId` is the group being looked at, if any.
     */
    public function feed(Access $actor, ?string $groupId, FeedFilter $filter, int $limit): FeedPage
    {
        $me = SocialRules::community($actor);
        $limit = max(1, min($limit, 200));

        // A group, whether it's the one being looked at or the one picked in
        // the filters, reads like the group's own page: a public group the
        // viewer hasn't joined still shows.
        $scoped = $groupId ?? ($filter->groupId !== 'everyone' ? $filter->groupId : null);
        $query = $scoped !== null
            ? CommunityScope::readable($me)->where('group_id', $scoped)
            : CommunityScope::feed($me);

        $this->narrow($actor, $me, $query, $filter);

        // Pins sit above the main feed and leave its chronology, so the same
        // card is never on screen twice. Anything that narrows the feed shows
        // them in order like everything else.
        $pinned = collect();
        if ($groupId === null && ! $filter->narrows() && $filter->groupId === null && $filter->sort === 'newest') {
            $pinned = (clone $query)->whereNotNull('pinned_at')->orderByDesc('pinned_at')->limit(SocialRules::MAX_PINNED)->get();
            $query->whereNull('pinned_at');
        }

        match ($filter->sort) {
            'oldest' => $query->orderBy('created_at'),
            'discussed' => $query->withCount('comments')->orderByDesc('comments_count')->orderByDesc('created_at'),
            'reactions' => $query->withCount('reactions')->orderByDesc('reactions_count')->orderByDesc('created_at'),
            default => $query->orderByDesc('created_at'),
        };

        $rows = $query->limit($limit + 1)->get();

        return $this->page($actor, $me, $pinned, $rows->take($limit)->values(), $rows->count() > $limit);
    }

    /** One post on its own, for a link from a notification. Null when it is gone or out of reach. */
    public function focus(Access $actor, string $postId): ?FeedPage
    {
        $me = SocialRules::community($actor);
        $post = CommunityScope::post($me, $postId);

        return $post === null ? null : $this->page($actor, $me, collect(), collect([$post]), false);
    }

    /**
     * A post now and before each edit, newest first: its text, when it went
     * up, its tag, and its photos and files, with the names its mentions
     * need. A photo or file an edit took off still shows in the versions
     * that had it. Anyone who can see the post can see these. Null when it
     * is gone or out of reach.
     *
     * @return array{versions: list<array{body: string, at: CarbonImmutable|string, tag: string|null, photos: list<string>, files: list<FileView>}>, names: array<int, string>}|null
     */
    public function history(Access $actor, string $postId): ?array
    {
        $me = SocialRules::community($actor);
        $post = CommunityScope::post($me, $postId);

        if ($post === null) {
            return null;
        }

        $rows = CommunityAttachment::query()->where('post_id', $post->id)->whereNull('comment_id')->orderBy('position')
            ->get(['id', 'kind', 'name', 'mime', 'size', 'removed_at'])->keyBy('id');
        $onPost = array_values(array_map('strval', $rows->whereNull('removed_at')->keys()->all()));
        $kept = [['body' => $post->body, 'at' => $post->edited_at ?? $post->created_at, 'tag' => $post->tag, 'attachments' => $onPost], ...array_reverse($post->earlier_versions ?? [])];

        $versions = [];
        $mentioned = [];
        foreach ($kept as $version) {
            $photos = [];
            $files = [];
            foreach ($version['attachments'] ?? [] as $id) {
                $row = $rows->get($id);
                if ($row?->kind === Vocabulary::PHOTO) {
                    $photos[] = $row->id;
                } elseif ($row !== null) {
                    $files[] = self::fileView($row);
                }
            }
            $versions[] = ['body' => $version['body'], 'at' => $version['at'], 'tag' => $version['tag'] ?? null, 'photos' => $photos, 'files' => $files];
            array_push($mentioned, ...SocialFormat::mentions($version['body']));
        }

        $names = [];
        foreach (app(People::class)->cards($actor, $mentioned) as $id => $card) {
            $names[$id] = $card->name;
        }

        return ['versions' => $versions, 'names' => $names];
    }

    /** @return list<TagView> every tag, in menu order */
    public function tags(Access $actor): array
    {
        SocialRules::community($actor);

        $counts = CommunityPost::query()->whereNotNull('tag')->selectRaw('tag, COUNT(*) AS total')->groupBy('tag')->pluck('total', 'tag');

        return array_values(CommunityTag::query()
            ->orderBy('position')
            ->orderBy('name')
            ->get()
            ->map(static fn (CommunityTag $tag): TagView => new TagView(
                $tag->id,
                $tag->code,
                $tag->name,
                $tag->tone,
                (int) ($counts[$tag->code] ?? 0),
            ))
            ->all());
    }

    /** @return list<GroupView> every group, private ones included, by name */
    public function groups(Access $actor): array
    {
        $me = SocialRules::community($actor);
        $mine = CommunityGroupMember::query()->where('user_id', $me)->get(['group_id', 'status', 'role'])->keyBy('group_id');
        $moderator = SocialRules::moderates($actor);

        return array_values(CommunityGroup::query()
            ->withCount([
                'members as member_count' => static fn (Builder $q) => $q->where('status', Vocabulary::MEMBER),
                'members as request_count' => static fn (Builder $q) => $q->where('status', Vocabulary::REQUESTED),
            ])
            ->orderBy('name')
            ->get()
            ->map(static function (CommunityGroup $group) use ($mine, $moderator): GroupView {
                $membership = $mine->get($group->id);
                $role = $membership !== null && $membership->status === Vocabulary::MEMBER ? $membership->role : null;
                $runs = in_array($role, [Vocabulary::OWNER, Vocabulary::MODERATOR], true);

                return new GroupView(
                    $group->id,
                    $group->name,
                    $group->description,
                    $group->is_private,
                    (int) $group->getAttribute('member_count'),
                    $membership !== null ? $membership->status : 'NONE',
                    $runs,
                    $moderator,
                    $runs ? (int) $group->getAttribute('request_count') : 0,
                    $role,
                    $group->photo_path !== null ? route('community.groups.photo', ['group' => $group->id, 'v' => substr(md5($group->photo_path), 0, 8)]) : null,
                );
            })
            ->all());
    }

    /**
     * Who's in a group, the people who run it first. Anyone may see who's in
     * a public group. A private group's members are only for its members.
     *
     * @return list<MemberView>
     */
    public function members(Access $actor, string $groupId): array
    {
        $me = SocialRules::community($actor);
        $group = CommunityGroup::query()->find($groupId);

        if ($group === null || ($group->is_private && SocialRules::groupRole($me, $group->id) === null)) {
            return [];
        }

        $rows = CommunityGroupMember::query()->where('group_id', $group->id)->where('status', Vocabulary::MEMBER)->get();

        return $this->memberViews($actor, $rows);
    }

    /**
     * The people waiting to get into a group, oldest first, and who put them
     * forward. Only for the people who run it; anyone else gets nothing.
     *
     * @return list<MemberView>
     */
    public function joinRequests(Access $actor, string $groupId): array
    {
        $me = SocialRules::community($actor);

        if (! SocialRules::runsGroup($me, $groupId)) {
            return [];
        }

        $rows = CommunityGroupMember::query()->where('group_id', $groupId)->where('status', Vocabulary::REQUESTED)->orderBy('created_at')->get();

        return $this->memberViews($actor, $rows);
    }

    /**
     * Every photo and file shared in a group, in its posts and their
     * comments, newest first. Empty for anyone who can't read the group.
     *
     * @return array{photos: list<string>, files: list<FileView>}
     */
    public function media(Access $actor, string $groupId): array
    {
        $me = SocialRules::community($actor);
        $posts = CommunityScope::readable($me)->where('group_id', $groupId)->select('id');
        $rows = CommunityAttachment::query()->whereIn('post_id', $posts)->whereNull('removed_at')->orderByDesc('created_at')->orderByDesc('position')->get();

        $photos = [];
        $files = [];
        foreach ($rows as $row) {
            if ($row->kind === Vocabulary::PHOTO && count($photos) < self::MEDIA) {
                $photos[] = $row->id;
            } elseif ($row->kind === Vocabulary::FILE && count($files) < self::MEDIA) {
                $files[] = self::fileView($row);
            }
        }

        return ['photos' => $photos, 'files' => $files];
    }

    /**
     * What the @ list offers someone writing to this audience: the people
     * who can read it, the groups they may name, and @everyone when they may
     * use it. Each person is [id, name, job title, photo].
     *
     * @return array{people: list<array{id: int, name: string, sub: string|null, photo: string|null}>, groups: list<array{id: string, name: string}>, everyone: bool}
     */
    public function mentionOptions(Access $actor, ?string $groupId): array
    {
        $me = SocialRules::community($actor);
        $people = app(People::class)->holders($actor, ModuleCode::COMMUNITY);

        // A private group's posts only reach its members, so only they are offered.
        $private = $groupId !== null && (bool) CommunityGroup::query()->whereKey($groupId)->value('is_private');
        if ($private) {
            $members = array_flip(array_map('intval', CommunityGroupMember::query()->where('group_id', $groupId)->where('status', Vocabulary::MEMBER)->pluck('user_id')->all()));
            $people = array_intersect_key($people, $members);
        }

        $list = [];
        foreach ($people as $person) {
            if ($person->id !== $me) {
                $list[] = ['id' => $person->id, 'name' => $person->name, 'sub' => $person->position, 'photo' => $person->photo];
            }
        }
        usort($list, static fn (array $a, array $b): int => strcasecmp($a['name'], $b['name']));

        $groups = [];
        foreach (SocialRules::mentionableGroups($actor, $groupId) as $id => $name) {
            $groups[] = ['id' => $id, 'name' => $name];
        }

        return ['people' => $list, 'groups' => $groups, 'everyone' => SocialRules::mayMentionEveryone($actor, $groupId)];
    }

    /**
     * The "Coming up" card: the work anniversaries and birthdays in the next
     * $days days of people who switched them on in My Profile. Soonest first,
     * and a birthday never carries a year or an age.
     *
     * @return list<CelebrationView>
     */
    public function celebrations(Access $actor, CarbonImmutable $today, int $days = 30): array
    {
        SocialRules::community($actor);

        // Greeting someone @mentions their account, so only a person whose
        // account can see Community gets a name to mention. The account is
        // also where their own privacy switches live.
        $accountNames = [];
        $accountIds = [];
        foreach (app(ModuleAccounts::class)->holders($actor, ModuleCode::COMMUNITY) as $label) {
            if ($label->employee_id !== null) {
                $accountNames[$label->employee_id] ??= $label->name;
                $accountIds[$label->employee_id] ??= $label->id;
            }
        }

        $privacy = app(Privacy::class);
        $hidden = $privacy->hiddenPositions(array_values($accountIds));
        $line = static function (Colleague $colleague, CarbonImmutable $date, ?int $years) use ($accountNames, $accountIds, $hidden): CelebrationView {
            $account = $accountIds[$colleague->id] ?? null;

            return new CelebrationView(
                EmployeeName::display($colleague),
                $account !== null && in_array($account, $hidden, true) ? null : $colleague->position,
                PortraitUrl::for($colleague->photo_path),
                $date,
                $years,
                $accountNames[$colleague->id] ?? null,
                $years === null ? CelebrationView::BIRTHDAY : CelebrationView::ANNIVERSARY,
            );
        };

        // Both are shown only for someone who asked for it, so Workforce is
        // asked about those employees and nobody else.
        $opted = static fn (array $chose): array => array_map('strval', array_keys(array_filter($accountIds, static fn (int $id): bool => in_array($id, $chose, true))));
        $anniversaryIds = $opted($privacy->shownAnniversaries(array_values($accountIds)));
        $birthdayIds = $opted($privacy->shownBirthdays(array_values($accountIds)));

        $directory = app(ColleagueDirectory::class);
        $celebrations = [];

        foreach ($directory->anniversaries($actor, $anniversaryIds, $today, $days) as $anniversary) {
            $celebrations[] = $line($anniversary->colleague, $anniversary->date, $anniversary->years);
        }

        foreach ($directory->birthdays($actor, $birthdayIds, $today, $days) as $birthday) {
            $celebrations[] = $line($birthday->colleague, $birthday->date, null);
        }

        usort($celebrations, static fn (CelebrationView $a, CelebrationView $b): int => $a->date <=> $b->date);

        return $celebrations;
    }

    /**
     * What a tick compares: counts and newest instants across the Community
     * tables, in one statement. Deliberately wider than any one viewer's feed
     * (see WithChangeStamp): a change the viewer cannot see costs one redraw
     * that shows nothing new, which is safe. Missing a change would not be.
     */
    public function stamp(Access $actor): string
    {
        if ($actor->userId === null || ! $actor->hasModule(ModuleCode::COMMUNITY)) {
            return 'none';
        }

        $row = DB::query()
            ->selectSub(CommunityPost::query()->selectRaw('COUNT(*)'), 'posts')
            ->selectSub(CommunityPost::query()->selectRaw('MAX(updated_at)'), 'posts_at')
            ->selectSub(CommunityComment::query()->selectRaw('COUNT(*)'), 'comments')
            // Comments have no updated_at, so an edit shows up through edited_at.
            ->selectSub(CommunityComment::query()->selectRaw('MAX(COALESCE(edited_at, created_at))'), 'comments_at')
            ->selectSub(CommunityReaction::query()->selectRaw('COUNT(*)'), 'reactions')
            ->selectSub(CommunityReaction::query()->selectRaw('MAX(updated_at)'), 'reactions_at')
            ->selectSub(CommunityCommentReaction::query()->selectRaw('COUNT(*)'), 'comment_reactions')
            ->selectSub(CommunityCommentReaction::query()->selectRaw('MAX(COALESCE(updated_at, created_at))'), 'comment_reactions_at')
            ->selectSub(CommunityGroupMember::query()->selectRaw('COUNT(*)'), 'members')
            ->selectSub(CommunityGroupMember::query()->selectRaw('MAX(updated_at)'), 'members_at')
            ->selectSub(CommunityGroup::query()->selectRaw('COUNT(*)'), 'groups')
            ->selectSub(CommunityGroup::query()->selectRaw('MAX(updated_at)'), 'groups_at')
            ->first();

        return hash('xxh128', $actor->userId.'|'.json_encode($row));
    }

    /** One photo on a post or comment, for someone who may read the post. */
    public function photo(Access $actor, string $photoId, bool $download = false): StreamedResponse
    {
        $row = $this->attachment($actor, $photoId, Vocabulary::PHOTO);

        return Attachments::stream($row->path, $row->mime, $download);
    }

    /** One file on a post or comment, downloaded under its own name, for someone who may read the post. */
    public function file(Access $actor, string $fileId): StreamedResponse
    {
        $row = $this->attachment($actor, $fileId, Vocabulary::FILE);

        return Attachments::stream($row->path, $row->mime, true, $row->name ?? 'file');
    }

    /**
     * A group's photo. Anyone who can use Community may see it, the same as
     * the group's name, since every group shows up under Discover groups.
     */
    public function groupPhoto(Access $actor, string $groupId): StreamedResponse
    {
        abort_unless($actor->userId !== null && $actor->hasModule(ModuleCode::COMMUNITY), 403);

        $path = CommunityGroup::query()->whereKey($groupId)->value('photo_path');
        abort_unless(is_string($path), 404, 'That photo was not found.');

        return Attachments::stream($path, 'image/jpeg');
    }

    private function attachment(Access $actor, string $id, string $kind): CommunityAttachment
    {
        abort_unless($actor->userId !== null && $actor->hasModule(ModuleCode::COMMUNITY), 403);

        $row = CommunityAttachment::query()->whereKey($id)->where('kind', $kind)->first();
        abort_if($row === null || CommunityScope::post((int) $actor->userId, $row->post_id) === null, 404, 'That file was not found.');

        return $row;
    }

    /**
     * Applies everything the filter asks for. Each part narrows the feed
     * further, so they all add up.
     *
     * @param  Builder<CommunityPost>  $query
     */
    private function narrow(Access $actor, int $me, Builder $query, FeedFilter $filter): void
    {
        if (trim($filter->search) !== '') {
            $this->search($actor, $query, trim($filter->search));
        }

        if ($filter->groupId === 'everyone') {
            $query->whereNull('community_posts.group_id');
        }

        if ($filter->tag !== null) {
            $query->where('community_posts.tag', $filter->tag);
        }

        if ($filter->authorId !== null) {
            $query->where('community_posts.author_id', $filter->authorId);
        }

        // Dates are Manila calendar days, so the day starts at Manila midnight.
        if ($filter->from !== null) {
            $query->where('community_posts.created_at', '>=', $filter->from->setTimezone(ManilaClock::TZ)->startOfDay()->utc());
        }
        if ($filter->to !== null) {
            $query->where('community_posts.created_at', '<', $filter->to->setTimezone(ManilaClock::TZ)->startOfDay()->addDay()->utc());
        }

        $has = static fn (string $kind) => static fn (QueryBuilder $q) => $q->select(DB::raw('1'))
            ->from('community_attachments')
            ->whereColumn('community_attachments.post_id', 'community_posts.id')
            ->where('community_attachments.kind', $kind)
            ->whereNull('community_attachments.removed_at');
        if (in_array($filter->has, ['photos', 'both'], true)) {
            $query->whereExists($has(Vocabulary::PHOTO));
        }
        if (in_array($filter->has, ['files', 'both'], true)) {
            $query->whereExists($has(Vocabulary::FILE));
        }

        if ($filter->mentions) {
            // Named, or reached through @everyone or a group the viewer is
            // in, in the post itself or in one of its comments.
            $tokens = ['%@['.$me.']%', '%@['.SocialFormat::EVERYONE.']%'];
            foreach (CommunityScope::groupIds($me) as $groupId) {
                $tokens[] = '%@[g:'.$groupId.']%';
            }
            $query->where(static function (Builder $q) use ($tokens): void {
                foreach ($tokens as $token) {
                    $q->orWhere('community_posts.body', 'like', $token);
                }
                $q->orWhereIn('community_posts.id', CommunityComment::query()->where(static function (Builder $c) use ($tokens): void {
                    foreach ($tokens as $token) {
                        $c->orWhere('body', 'like', $token);
                    }
                })->select('post_id'));
            });
        }
    }

    /**
     * Matches the post's text, the author's name, or the name of someone the
     * post mentions. A mention is stored as `@[id]`, so a name is matched to
     * accounts first and the token searched for; a search that matches most
     * of the company by name checks the first 25 for mentions.
     *
     * @param  Builder<CommunityPost>  $query
     */
    private function search(Access $actor, Builder $query, string $needle): void
    {
        $lower = mb_strtolower($needle);
        $people = [];
        foreach (app(AccountNames::class)->list($actor) as $label) {
            if (str_contains(mb_strtolower($label->name), $lower)) {
                $people[] = $label->id;
            }
        }

        $byText = clone $query;
        SqlText::contains($byText, ['community_posts.body'], $needle);
        $postIds = array_values(array_map('strval', $byText->pluck('community_posts.id')->all()));

        $query->where(static function (Builder $q) use ($postIds, $people): void {
            $q->whereIn('community_posts.id', $postIds)->orWhereIn('community_posts.author_id', $people);
            foreach (array_slice($people, 0, 25) as $id) {
                $q->orWhere('community_posts.body', 'like', '%@['.$id.']%');
            }
        });
    }

    /**
     * @param  Collection<int, CommunityGroupMember>  $rows
     * @return list<MemberView>
     */
    private function memberViews(Access $actor, Collection $rows): array
    {
        $cards = app(People::class)->cards($actor, array_values(array_filter(
            [...$rows->pluck('user_id')->all(), ...$rows->pluck('added_by')->all()],
            static fn (mixed $id): bool => is_int($id),
        )));
        $rank = [Vocabulary::OWNER => 0, Vocabulary::MODERATOR => 1, Vocabulary::MEMBER => 2];

        $views = array_values($rows->map(static fn (CommunityGroupMember $row): MemberView => new MemberView(
            $cards[$row->user_id] ?? PersonView::missing($row->user_id),
            $row->role,
            $row->status === Vocabulary::REQUESTED,
            $row->added_by !== null ? ($cards[$row->added_by] ?? PersonView::missing($row->added_by)) : null,
            $row->created_at,
        ))->all());

        usort($views, static fn (MemberView $a, MemberView $b): int => [$rank[$a->role] ?? 3, mb_strtolower($a->person->name)] <=> [$rank[$b->role] ?? 3, mb_strtolower($b->person->name)]);

        return $views;
    }

    private static function fileView(CommunityAttachment $row): FileView
    {
        return new FileView($row->id, $row->name ?? 'file', $row->size, Attachments::FILE_TYPES[$row->mime] ?? 'File');
    }

    /**
     * @param  Collection<int, CommunityPost>  $pinned
     * @param  Collection<int, CommunityPost>  $posts
     */
    private function page(Access $actor, int $me, Collection $pinned, Collection $posts, bool $more): FeedPage
    {
        $all = $pinned->concat($posts);
        $postIds = $all->pluck('id')->all();

        if ($postIds === []) {
            return new FeedPage([], [], false, []);
        }

        // Photos and files, for the posts and for their comments, in order.
        // One an edit took off is only in the post's history now.
        $attached = CommunityAttachment::query()->whereIn('post_id', $postIds)->whereNull('removed_at')->orderBy('position')->get(['id', 'post_id', 'comment_id', 'kind', 'name', 'mime', 'size']);
        $photos = [];
        $files = [];
        foreach ($attached as $row) {
            $owner = $row->comment_id ?? $row->post_id;
            if ($row->kind === Vocabulary::PHOTO) {
                $photos[$owner][] = $row->id;
            } else {
                $files[$owner][] = self::fileView($row);
            }
        }

        $groupNames = CommunityGroup::query()->whereIn('id', $all->pluck('group_id')->filter()->unique()->values()->all())->pluck('name', 'id');

        // Who reacted with what, oldest first. Counts come from these lists,
        // and the lists open from the tally.
        $reactions = [];
        $mine = [];
        foreach (CommunityReaction::query()->whereIn('post_id', $postIds)->orderBy('created_at')->get(['post_id', 'user_id', 'type']) as $row) {
            $reactions[$row->post_id][$row->type][] = (int) $row->user_id;
            if ((int) $row->user_id === $me) {
                $mine[$row->post_id] = $row->type;
            }
        }

        $comments = CommunityComment::query()->whereIn('post_id', $postIds)->orderBy('created_at')->get();
        $commentIds = $comments->pluck('id')->all();
        $commentReactions = [];
        $myCommentReactions = [];
        $reactionRows = $commentIds === [] ? collect() : CommunityCommentReaction::query()
            ->whereIn('comment_id', $commentIds)
            ->orderBy('created_at')
            ->get(['comment_id', 'user_id', 'type']);
        foreach ($reactionRows as $row) {
            $commentReactions[$row->comment_id][$row->type][] = (int) $row->user_id;
            if ((int) $row->user_id === $me) {
                $myCommentReactions[$row->comment_id] = $row->type;
            }
        }

        $mentioned = [];
        foreach ([...$all->pluck('body')->all(), ...$comments->pluck('body')->all()] as $body) {
            array_push($mentioned, ...SocialFormat::mentions((string) $body));
        }
        $reactors = [];
        foreach ([...$reactions, ...$commentReactions] as $byType) {
            foreach ($byType as $ids) {
                array_push($reactors, ...$ids);
            }
        }
        $cards = app(People::class)->cards($actor, array_values(array_filter(
            [...$all->pluck('author_id')->all(), ...$comments->pluck('author_id')->all(), ...$mentioned, ...$reactors],
            static fn (mixed $id): bool => is_int($id),
        )));

        $person = static fn (?int $id): PersonView => $id !== null ? ($cards[$id] ?? PersonView::missing($id)) : PersonView::missing(0);

        $moderator = SocialRules::moderates($actor);

        // The thread as a tree: each comment carries its replies, and theirs.
        $children = $comments->groupBy(static fn (CommunityComment $comment): string => (string) $comment->parent_id);
        $build = static function (CommunityComment $comment, int $depth) use (&$build, $children, $person, $commentReactions, $myCommentReactions, $me, $moderator, $photos, $files): CommentView {
            $replies = [];
            foreach ($children->get($comment->id) ?? [] as $child) {
                $replies[] = $build($child, $depth + 1);
            }

            return new CommentView(
                $comment->id,
                $person($comment->author_id),
                $comment->body,
                $commentReactions[$comment->id] ?? [],
                $myCommentReactions[$comment->id] ?? null,
                $comment->created_at,
                $comment->author_id === $me,
                $depth,
                $comment->author_id === $me || $moderator,
                $replies,
                $photos[$comment->id] ?? [],
                $files[$comment->id] ?? [],
                editedAt: $comment->edited_at,
            );
        };
        $threads = [];
        foreach ($children->get('') ?? [] as $comment) {
            $threads[$comment->post_id][] = $build($comment, 0);
        }
        $totals = $comments->countBy('post_id');

        $memberOf = array_flip(CommunityScope::groupIds($me));
        $takesPart = static fn (CommunityPost $post): bool => $post->group_id === null || isset($memberOf[$post->group_id]);

        $view = static fn (CommunityPost $post): PostView => new PostView(
            $post->id,
            $person($post->author_id),
            $post->group_id,
            $post->group_id !== null ? (string) ($groupNames[$post->group_id] ?? 'A group') : null,
            $post->tag,
            $post->body,
            $photos[$post->id] ?? [],
            $reactions[$post->id] ?? [],
            $mine[$post->id] ?? null,
            $threads[$post->id] ?? [],
            (int) ($totals[$post->id] ?? 0),
            $post->pinned_at !== null,
            $post->created_at,
            $post->author_id === $me,
            $moderator && ($post->pinned_at !== null || $post->group_id === null),
            $takesPart($post),
            $files[$post->id] ?? [],
            editedAt: $post->edited_at,
            canEdit: $post->author_id === $me && $takesPart($post),
        );

        $names = [];
        foreach ($cards as $id => $card) {
            $names[$id] = $card->name;
        }

        return new FeedPage(
            array_values($pinned->map($view)->all()),
            array_values($posts->map($view)->all()),
            $more,
            $names,
            $cards,
        );
    }
}
