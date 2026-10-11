<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * The eleven Social migrations (2026_09_29_0001 … 0011, Community and Chat)
 * are retry-safe as a chain — the cPanel cron re-running a half-applied
 * deploy, or a database that applied them under other file names.
 * Every one runs again on a migrated schema without DDL beyond restating
 * ROW_FORMAT (and the grants CHECK re-issuing its own list), and without
 * bringing back a table or a CHECK a later one renamed or dropped. The whole
 * chain rolls back, tolerates a second rollback and re-applies twice. Its
 * data steps change nothing the second time: a starter tag a moderator
 * removed stays removed, and neither a group whose owner handed it on nor a
 * group chat whose admins changed gets its creator promoted again.
 *
 * THIS FILE ALWAYS ENDS MIGRATED, pass or fail: each `finally` re-runs the
 * whole chain before anything else, then deletes the rows seeded here by
 * key. On MySQL/MariaDB DDL commits implicitly, so RefreshDatabase's
 * transaction cannot roll either back (the RosterLocationsMigrationTest
 * pattern). The CHECKs are MySQL/MariaDB only and belong to
 * tests/Integration/SocialSchemaTest.
 */

/** @return list<string> the chain's files, oldest first */
function socmigFiles(): array
{
    return [
        '2026_09_29_0001_add_community_and_chat_to_role_module_grants_check',
        '2026_09_29_0002_create_community_tables',
        '2026_09_29_0003_create_chat_tables',
        '2026_09_29_0004_create_social_preferences_table',
        '2026_09_29_0005_add_reactions_to_community_comments',
        '2026_09_29_0006_create_community_tags_table',
        '2026_09_29_0007_add_anniversary_to_social_preferences',
        '2026_09_29_0008_add_roles_and_photos_to_community_groups',
        '2026_09_29_0009_add_admins_and_photos_to_chat',
        '2026_09_29_0010_turn_photos_into_attachments',
        '2026_09_29_0011_add_allow_mentions_to_chat_participants',
    ];
}

/** One migration of the chain by its sequence (1 … 11) — an anonymous class, so untyped here. */
function socmigMigration(int $sequence): mixed
{
    return require base_path('database/migrations/'.socmigFiles()[$sequence - 1].'.php');
}

/** @return list<mixed> the chain's migrations, oldest first */
function socmigMigrations(): array
{
    return array_map(static fn (int $sequence): mixed => socmigMigration($sequence), range(1, 11));
}

function socmigUpAll(): void
{
    foreach (socmigMigrations() as $migration) {
        $migration->up();
    }

    // The Social CHECK is historical. Restore its later Reports extension
    // after testing the old chain so MySQL's committed DDL cannot leak out.
    $reports = require base_path('database/migrations/2026_10_02_0007_add_reports_to_role_module_grants_table.php');
    $reports->up();
}

function socmigOnMySql(): bool
{
    return in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true);
}

/**
 * Every CREATE, ALTER or DROP the callback sends, in order.
 *
 * @return list<string>
 */
function socmigDdlDuring(callable $callback): array
{
    DB::flushQueryLog();
    DB::enableQueryLog();
    try {
        $callback();
    } finally {
        DB::disableQueryLog();
    }

    return array_values(array_filter(
        array_column(DB::getQueryLog(), 'query'),
        static fn (string $sql): bool => preg_match('/^\s*(create|alter|drop)\b/i', $sql) === 1,
    ));
}

/** @return list<string> the codes of the tags, in menu order */
function socmigTagCodes(): array
{
    return array_values(DB::table('community_tags')->orderBy('position')->pluck('code')->map(static fn (mixed $code): string => (string) $code)->all());
}

/** Every table, column, index and key the finished chain leaves — present or absent together — and never a retired name. */
function socmigAssertApplied(bool $applied): void
{
    $tables = [
        'community_groups', 'community_group_members', 'community_posts', 'community_attachments', 'community_comments',
        'community_reactions', 'community_comment_reactions', 'community_tags', 'chat_conversations', 'chat_participants',
        'chat_messages', 'chat_attachments', 'chat_reactions', 'social_preferences',
    ];
    foreach ($tables as $table) {
        expect(Schema::hasTable($table))->toBe($applied, $table);
    }
    foreach (['community_post_photos', 'community_comment_likes', 'chat_message_photos'] as $retired) {
        expect(Schema::hasTable($retired))->toBeFalse($retired);
    }
    if (! $applied) {
        return;
    }

    foreach ([
        ['community_comment_reactions', 'type'], ['community_comment_reactions', 'updated_at'],
        ['social_preferences', 'show_anniversary'], ['community_groups', 'photo_path'], ['community_groups', 'photo_size'],
        ['community_group_members', 'role'], ['community_group_members', 'added_by'], ['chat_conversations', 'photo_path'],
        ['chat_conversations', 'photo_size'], ['chat_participants', 'role'], ['chat_participants', 'allow_mentions'],
        ['community_attachments', 'comment_id'], ['community_attachments', 'kind'], ['community_attachments', 'name'],
        ['chat_attachments', 'kind'], ['chat_attachments', 'name'],
    ] as [$table, $column]) {
        expect(Schema::hasColumn($table, $column))->toBeTrue("{$table}.{$column}");
    }

    foreach ([
        'community_groups' => ['cgroups_name_unq'],
        'community_group_members' => ['cgmembers_group_user_unq', 'cgmembers_user_status_idx', 'cgmembers_updated_idx'],
        'community_posts' => ['cposts_created_idx', 'cposts_group_created_idx', 'cposts_pinned_idx', 'cposts_author_idx', 'cposts_updated_idx'],
        'community_attachments' => ['cphotos_post_position_idx', 'cattach_comment_position_idx'],
        'community_comments' => ['ccomments_post_created_idx', 'ccomments_parent_idx', 'ccomments_author_idx', 'ccomments_created_idx'],
        'community_reactions' => ['creactions_post_user_unq', 'creactions_updated_idx'],
        'community_comment_reactions' => ['clikes_comment_user_unq', 'clikes_created_idx'],
        'community_tags' => ['ctags_code_unq', 'ctags_name_unq'],
        'chat_conversations' => ['cconv_direct_key_unq', 'cconv_last_message_idx'],
        'chat_participants' => ['cpart_conv_user_unq', 'cpart_user_idx'],
        'chat_messages' => ['cmsg_conv_created_idx', 'cmsg_sender_idx', 'cmsg_updated_idx', 'cmsg_reply_to_idx'],
        'chat_attachments' => ['cmphotos_message_position_idx'],
        'chat_reactions' => ['creact_message_user_unq', 'creact_updated_idx'],
        'social_preferences' => ['sprefs_user_unq'],
    ] as $table => $indexes) {
        foreach ($indexes as $index) {
            expect(Schema::hasIndex($table, $index))->toBeTrue("{$table} index {$index}");
        }
    }

    foreach ([
        'community_groups' => ['created_by'],
        'community_group_members' => ['group_id', 'user_id', 'added_by'],
        'community_posts' => ['author_id', 'group_id', 'pinned_by'],
        'community_attachments' => ['post_id', 'comment_id'],
        'community_comments' => ['post_id', 'parent_id', 'author_id'],
        'community_reactions' => ['post_id', 'user_id'],
        'community_comment_reactions' => ['comment_id', 'user_id'],
        'community_tags' => ['created_by'],
        'chat_conversations' => ['created_by'],
        'chat_participants' => ['conversation_id', 'user_id'],
        'chat_messages' => ['conversation_id', 'sender_id'],
        'chat_attachments' => ['message_id'],
        'chat_reactions' => ['message_id', 'user_id'],
        'social_preferences' => ['user_id'],
    ] as $table => $columns) {
        foreach ($columns as $column) {
            expect(Schema::hasForeignKey($table, [$column]))->toBeTrue("{$table}.{$column} key");
        }
    }
}

it('runs every Social migration again with no DDL beyond restating ROW_FORMAT and the grants CHECK', function () {
    try {
        socmigAssertApplied(true);
        $starters = socmigTagCodes();
        expect($starters)->toBe(['ANNOUNCEMENT', 'KUDOS', 'QUESTION', 'SOCIAL', 'LOST_FOUND']);

        $restated = static fn (string ...$tables): array => socmigOnMySql()
            ? array_map(static fn (string $table): string => "ALTER TABLE `{$table}` ROW_FORMAT=DYNAMIC", $tables)
            : [];
        $expected = [
            // The grants CHECK is re-issued with its full list on every run.
            1 => socmigOnMySql() ? ['ALTER TABLE `role_module_grants` DROP CONSTRAINT `rmg_module_chk`', 'ALTER TABLE `role_module_grants` ADD CONSTRAINT `rmg_module_chk` CHECK ('."`module` IN ('EMPLOYEES','EDTR','ROSTER','LEAVES','PROJECTS','PAYROLL','RECORDS','USERS','AUDIT','PORTAL','TASKS','COMMUNITY','CHAT','PROFILE'))"] : [],
            // The two tables later renamed are neither restated nor recreated.
            2 => $restated('community_groups', 'community_group_members', 'community_posts', 'community_comments', 'community_reactions'),
            3 => $restated('chat_conversations', 'chat_participants', 'chat_messages', 'chat_reactions'),
            4 => $restated('social_preferences'),
            6 => $restated('community_tags'),
        ];

        foreach (socmigMigrations() as $i => $migration) {
            $sequence = $i + 1;
            expect(socmigDdlDuring(static fn () => $migration->up()))->toBe($expected[$sequence] ?? [], "migration {$sequence}");
        }

        socmigAssertApplied(true);
        // The starters were not inserted a second time.
        expect(socmigTagCodes())->toBe($starters);
    } finally {
        socmigUpAll();
    }
});

it('rolls the Social chain back, tolerates a second rollback, and re-applies it twice', function () {
    $migrations = socmigMigrations();

    try {
        foreach (array_reverse($migrations) as $migration) {
            $migration->down();
        }
        socmigAssertApplied(false);

        // A repeated rollback finds nothing to undo and does nothing.
        foreach (array_reverse($migrations) as $migration) {
            $migration->down();
        }
        socmigAssertApplied(false);

        foreach ($migrations as $migration) {
            $migration->up();
        }
        // The retry: every piece already exists, so every step is skipped.
        foreach ($migrations as $migration) {
            $migration->up();
        }
        socmigAssertApplied(true);
        expect(socmigTagCodes())->toBe(['ANNOUNCEMENT', 'KUDOS', 'QUESTION', 'SOCIAL', 'LOST_FOUND']);
    } finally {
        // Re-migrate FIRST, even when an expectation failed between the
        // rollback and the re-apply above.
        socmigUpAll();
    }
});

it('adds owners, admins and starter tags on a second run only where there are none', function () {
    $users = [];
    $groups = [];
    $chats = [];

    try {
        $creator = User::factory()->create();
        $heir = User::factory()->create();
        $users = [$creator->id, $heir->id];
        $now = now()->utc()->format('Y-m-d H:i:s.v');

        // Group A's creator handed it on; group B never got an owner.
        $groups = ['A' => (string) Str::ulid(), 'B' => (string) Str::ulid()];
        DB::table('community_groups')->insert([
            ['id' => $groups['A'], 'name' => 'Handed on '.Str::random(6), 'is_private' => false, 'created_by' => $creator->id, 'created_at' => $now],
            ['id' => $groups['B'], 'name' => 'Ownerless '.Str::random(6), 'is_private' => false, 'created_by' => $creator->id, 'created_at' => $now],
        ]);
        $membership = static fn (string $group, int $user, string $role): array => [
            'id' => (string) Str::ulid(), 'group_id' => $group, 'user_id' => $user, 'status' => 'MEMBER', 'role' => $role, 'created_at' => $now,
        ];
        DB::table('community_group_members')->insert([
            $membership($groups['A'], $creator->id, 'MEMBER'),
            $membership($groups['A'], $heir->id, 'OWNER'),
            $membership($groups['B'], $creator->id, 'MEMBER'),
        ]);

        // Chat A's admin is now someone else; chat B has none; a direct chat never has one.
        $chats = ['A' => (string) Str::ulid(), 'B' => (string) Str::ulid(), 'direct' => (string) Str::ulid()];
        $conversation = static fn (string $id, string $kind, ?string $directKey): array => [
            'id' => $id, 'kind' => $kind, 'title' => $kind === 'GROUP' ? 'Chat '.substr($id, -4) : null,
            'direct_key' => $directKey, 'created_by' => $creator->id, 'created_at' => $now,
        ];
        DB::table('chat_conversations')->insert([
            $conversation($chats['A'], 'GROUP', null),
            $conversation($chats['B'], 'GROUP', null),
            $conversation($chats['direct'], 'DIRECT', min($users).':'.max($users)),
        ]);
        $participant = static fn (string $chat, int $user, string $role): array => [
            'id' => (string) Str::ulid(), 'conversation_id' => $chat, 'user_id' => $user, 'role' => $role, 'created_at' => $now,
        ];
        DB::table('chat_participants')->insert([
            $participant($chats['A'], $creator->id, 'MEMBER'),
            $participant($chats['A'], $heir->id, 'ADMIN'),
            $participant($chats['B'], $creator->id, 'MEMBER'),
            $participant($chats['direct'], $creator->id, 'MEMBER'),
            $participant($chats['direct'], $heir->id, 'MEMBER'),
        ]);

        // A moderator removed Lost & found and renamed Kudos.
        DB::table('community_tags')->where('code', 'LOST_FOUND')->delete();
        DB::table('community_tags')->where('code', 'KUDOS')->update(['name' => 'Shout-outs']);

        socmigMigration(6)->up();
        socmigMigration(8)->up();
        socmigMigration(9)->up();

        $groupRole = static fn (string $group, int $user): string => (string) DB::table('community_group_members')->where('group_id', $group)->where('user_id', $user)->value('role');
        expect($groupRole($groups['A'], $creator->id))->toBe('MEMBER')
            ->and($groupRole($groups['A'], $heir->id))->toBe('OWNER')
            ->and($groupRole($groups['B'], $creator->id))->toBe('OWNER');

        $chatRole = static fn (string $chat, int $user): string => (string) DB::table('chat_participants')->where('conversation_id', $chat)->where('user_id', $user)->value('role');
        expect($chatRole($chats['A'], $creator->id))->toBe('MEMBER')
            ->and($chatRole($chats['A'], $heir->id))->toBe('ADMIN')
            ->and($chatRole($chats['B'], $creator->id))->toBe('ADMIN')
            ->and($chatRole($chats['direct'], $creator->id))->toBe('MEMBER')
            ->and($chatRole($chats['direct'], $heir->id))->toBe('MEMBER');

        // The moderator's choices stand: no starter comes back, no name reverts.
        expect(socmigTagCodes())->toBe(['ANNOUNCEMENT', 'KUDOS', 'QUESTION', 'SOCIAL'])
            ->and(DB::table('community_tags')->where('code', 'KUDOS')->value('name'))->toBe('Shout-outs');

        // Only an empty table gets the five starters — all of them, once.
        DB::table('community_tags')->delete();
        socmigMigration(6)->up();
        socmigMigration(6)->up();
        expect(socmigTagCodes())->toBe(['ANNOUNCEMENT', 'KUDOS', 'QUESTION', 'SOCIAL', 'LOST_FOUND']);
    } finally {
        socmigUpAll();
        if (socmigOnMySql()) {
            // DDL has committed these rows: remove them by key, chats and
            // groups before the accounts they name, and put the starters back.
            DB::table('chat_conversations')->whereIn('id', array_values($chats))->delete();
            DB::table('community_groups')->whereIn('id', array_values($groups))->delete();
            User::query()->whereIn('id', $users)->delete();
            if (socmigTagCodes() !== ['ANNOUNCEMENT', 'KUDOS', 'QUESTION', 'SOCIAL', 'LOST_FOUND']) {
                DB::table('community_tags')->delete();
                socmigMigration(6)->up();
            }
        }
    }
});
