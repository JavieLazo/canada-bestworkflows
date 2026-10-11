<?php

declare(strict_types=1);

namespace App\Modules\Social\Application;

use App\Domain\Enums\ModuleCode;
use App\Models\ChatParticipant;
use App\Models\CommunityGroup;
use App\Models\CommunityGroupMember;
use App\Models\CommunityTag;
use App\Modules\Social\Domain\Vocabulary;
use App\Support\Access;
use Illuminate\Database\Eloquent\Builder;
use RuntimeException;

/** Who may act in Community and Chat, and how much one post or message may hold. */
final class SocialRules
{
    public const int MAX_POST = 5000;

    public const int MAX_COMMENT = 2000;

    public const int MAX_MESSAGE = 2000;

    /** Photos and files together, on one post, comment or message. */
    public const int MAX_ATTACHMENTS = 10;

    /** How deep a thread goes: 0 is a comment, so 2 means a comment, a reply, and a reply to that. */
    public const int MAX_COMMENT_DEPTH = 2;

    /** Posts the main feed may pin above everything else. */
    public const int MAX_PINNED = 3;

    public const int MAX_GROUP_TITLE = 60;

    /** A Community group's name and description, the sizes of their columns. */
    public const int MAX_GROUP_NAME = 80;

    public const int MAX_GROUP_ABOUT = 280;

    public const int MAX_TAG_NAME = 30;

    private function __construct() {}

    /**
     * The actor's account id, once it is established that they may use
     * Community. Re-checked in every operation, because a Livewire action is
     * reachable without the route that rendered its page.
     */
    public static function community(Access $actor): int
    {
        if ($actor->userId === null || ! $actor->hasModule(ModuleCode::COMMUNITY)) {
            throw new RuntimeException('You do not have access to Community.');
        }

        return (int) $actor->userId;
    }

    /**
     * The actor's account id, for the things both modules share, like the
     * privacy switches on My Profile. Holding either module is enough, since
     * the choice applies wherever the person shows up.
     */
    public static function member(Access $actor): int
    {
        if ($actor->userId === null || ! $actor->hasModule(ModuleCode::COMMUNITY, ModuleCode::CHAT)) {
            throw new RuntimeException('You do not have access to Community or Chat.');
        }

        return (int) $actor->userId;
    }

    /** The actor's account id, once it is established that they may use Chat. */
    public static function chat(Access $actor): int
    {
        if ($actor->userId === null || ! $actor->hasModule(ModuleCode::CHAT)) {
            throw new RuntimeException('You do not have access to Chat.');
        }

        return (int) $actor->userId;
    }

    /**
     * Who may pin posts and remove other people's comments. NEVER SOMEONE
     * ELSE'S POST — RemovePost is its author's alone, and a moderator takes
     * other people's posts only with a whole group (DeleteGroup).
     *
     * A stand-in until we settle who moderates.
     * Whoever administers accounts already holds the widest trust the roles
     * express, and with the seeded roles that is System Admin alone. Changing
     * the rule means changing this one method.
     */
    public static function moderates(Access $actor): bool
    {
        return $actor->hasModule(ModuleCode::COMMUNITY) && $actor->hasModule(ModuleCode::USERS);
    }

    /**
     * Someone's role in a group they're in: OWNER, MODERATOR or MEMBER. Null
     * when they're not in it, or only asked to be.
     */
    public static function groupRole(int $userId, string $groupId): ?string
    {
        $role = CommunityGroupMember::query()
            ->where('group_id', $groupId)
            ->where('user_id', $userId)
            ->where('status', Vocabulary::MEMBER)
            ->value('role');

        return is_string($role) ? $role : null;
    }

    /**
     * Whether someone runs a group: its owner or one of its moderators. They
     * answer its join requests and look after its members.
     *
     * Company-wide moderators don't get this for free. A private group's
     * members and requests are the group's own business, so someone has to
     * be in it and running it to see them.
     */
    public static function runsGroup(int $userId, string $groupId): bool
    {
        return in_array(self::groupRole($userId, $groupId), [Vocabulary::OWNER, Vocabulary::MODERATOR], true);
    }

    /**
     * The group, once it's clear the actor runs it. One refusal for a group
     * that doesn't exist and one they don't run, so nobody learns more than
     * they should.
     */
    public static function runGroup(Access $actor, string $groupId, string $refusal): CommunityGroup
    {
        $me = self::community($actor);
        $group = CommunityGroup::query()->find($groupId);

        if ($group === null || ! self::runsGroup($me, $group->id)) {
            throw new RuntimeException($refusal);
        }

        return $group;
    }

    /**
     * Who may @everyone: in a group, anyone in it; in the main feed, only
     * company-wide moderators, since it rings the whole company's bells.
     */
    public static function mayMentionEveryone(Access $actor, ?string $groupId): bool
    {
        if ($actor->userId === null) {
            return false;
        }

        return $groupId === null
            ? self::moderates($actor)
            : self::groupRole((int) $actor->userId, $groupId) !== null;
    }

    /**
     * The groups someone may @mention in a post or comment to $groupId (null
     * for the main feed), as id => name, by name. Any public group, and a
     * private one only for its own members and company-wide moderators, so
     * outsiders can't ring its members' bells.
     *
     * None at all inside a private group. Its posts only reach its members,
     * so naming another group there would tell nobody new.
     *
     * @return array<string, string>
     */
    public static function mentionableGroups(Access $actor, ?string $groupId): array
    {
        $me = self::community($actor);

        if ($groupId !== null && (bool) CommunityGroup::query()->whereKey($groupId)->value('is_private')) {
            return [];
        }

        $moderator = self::moderates($actor);
        $mine = array_flip(CommunityScope::groupIds($me));
        $groups = [];
        foreach (CommunityGroup::query()->orderBy('name')->get(['id', 'name', 'is_private']) as $group) {
            if (! $group->is_private || $moderator || isset($mine[$group->id])) {
                $groups[$group->id] = $group->name;
            }
        }

        return $groups;
    }

    /** Whether someone is an admin of a group chat. */
    public static function chatAdmin(int $userId, string $conversationId): bool
    {
        return ChatParticipant::query()
            ->where('conversation_id', $conversationId)
            ->where('user_id', $userId)
            ->where('role', Vocabulary::ADMIN)
            ->exists();
    }

    /** The actor's account id, once it is established that they moderate Community. */
    public static function moderator(Access $actor, string $refusal): int
    {
        $me = self::community($actor);

        if (! self::moderates($actor)) {
            throw new RuntimeException($refusal);
        }

        return $me;
    }

    /** A group name: trimmed, within its cap, and not taken by another group, whatever the case. */
    public static function groupName(string $name, ?string $except): string
    {
        $name = self::text($name, self::MAX_GROUP_NAME, 'A group name');

        if ($name === '') {
            throw new RuntimeException('Give the group a name.');
        }
        if (self::taken(CommunityGroup::query(), $name, $except)) {
            throw new RuntimeException("There's already a group called {$name}.");
        }

        return $name;
    }

    /** A tag name, the same way. */
    public static function tagName(string $name, ?string $except): string
    {
        $name = self::text($name, self::MAX_TAG_NAME, 'A tag name');

        if ($name === '') {
            throw new RuntimeException('Give the tag a name.');
        }
        if (self::taken(CommunityTag::query(), $name, $except)) {
            throw new RuntimeException("There's already a tag called {$name}.");
        }

        return $name;
    }

    public static function tone(string $tone): string
    {
        if (! Vocabulary::isTone($tone)) {
            throw new RuntimeException('Pick one of the listed colours.');
        }

        return $tone;
    }

    /** Trimmed text, refused past its cap. */
    public static function text(string $body, int $max, string $what): string
    {
        $text = trim($body);

        if (mb_strlen($text) > $max) {
            throw new RuntimeException(sprintf('%s holds up to %s characters.', $what, number_format($max)));
        }

        return $text;
    }

    /**
     * Whether another row already has this name, ignoring case.
     *
     * @param  Builder<CommunityGroup>|Builder<CommunityTag>  $query
     */
    private static function taken(Builder $query, string $name, ?string $except): bool
    {
        return $query
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
            ->when($except !== null, static fn (Builder $q) => $q->whereKeyNot($except))
            ->exists();
    }

    /** The first 120 characters, for a notification's preview line. */
    public static function preview(string $text): string
    {
        $text = trim((string) preg_replace('/\s+/', ' ', $text));

        return mb_strlen($text) > 120 ? mb_substr($text, 0, 117).'...' : $text;
    }
}
