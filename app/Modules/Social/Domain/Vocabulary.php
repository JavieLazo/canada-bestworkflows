<?php

declare(strict_types=1);

namespace App\Modules\Social\Domain;

/**
 * The fixed lists Community and Chat store, in one place.
 *
 * Every list is also a CHECK constraint in the migrations, written out
 * literally there so that an edit here cannot silently rewrite a migration
 * that has already run. Change both together, with a new migration.
 *
 * Tags aren't here: moderators manage them, so they live in the
 * community_tags table. Only their tones are fixed.
 * How each value looks (emoji, label, pill colour) is presentation, and lives
 * in App\Support\SocialFormat and Community\Support\CommunityLabels.
 */
final class Vocabulary
{
    /** Workplace-shaped reactions: no angry, no sad, no downvote. */
    public const array REACTIONS = ['LIKE', 'LOVE', 'CELEBRATE', 'SUPPORT', 'INSIGHTFUL', 'FUNNY'];

    /** The pill colours a tag can take, the app's own tiers so tags never invent colours. */
    public const array TAG_TONES = ['muted', 'primary', 'info', 'success', 'caution', 'danger'];

    /** A group membership's status. Also a group role: an ordinary member. */
    public const string MEMBER = 'MEMBER';

    /** Asked to join a private group, or put forward by a member, and not let in yet. */
    public const string REQUESTED = 'REQUESTED';

    /** Runs a group: one per group, and it can be handed on. */
    public const string OWNER = 'OWNER';

    /** Helps run a group, picked by its owner. */
    public const string MODERATOR = 'MODERATOR';

    public const array GROUP_ROLES = [self::OWNER, self::MODERATOR, self::MEMBER];

    /** Can remove people from a group chat and make other admins. */
    public const string ADMIN = 'ADMIN';

    public const array CHAT_ROLES = [self::ADMIN, self::MEMBER];

    public const string PHOTO = 'PHOTO';

    public const string FILE = 'FILE';

    public const array ATTACHMENT_KINDS = [self::PHOTO, self::FILE];

    public const string DIRECT = 'DIRECT';

    public const string GROUP = 'GROUP';

    public const string TEXT = 'TEXT';

    /** A line the app writes into a thread, like "created the group". */
    public const string SYSTEM = 'SYSTEM';

    private function __construct() {}

    public static function isReaction(string $type): bool
    {
        return in_array($type, self::REACTIONS, true);
    }

    public static function isTone(string $tone): bool
    {
        return in_array($tone, self::TAG_TONES, true);
    }
}
