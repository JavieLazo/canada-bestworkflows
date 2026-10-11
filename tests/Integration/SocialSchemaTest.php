<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * On the production engine the Social chain (2026_09_29_0001 … 0011,
 * Community and Chat) is retry-safe step by step: every CHECK exists exactly
 * once however often the chain runs, and refuses what it should; a run
 * killed after a CREATE TABLE, or on either side of one of the two renames,
 * resumes to the full schema with every index declared before the keys that
 * use it (so MariaDB adds none of its own); and a full rollback removes every
 * piece, tolerates a second one, and a re-run restores them all. The fixed
 * five-tag CHECK that 0006 retires never comes back.
 *
 * sqlite never creates a CHECK and rebuilds a table for most ALTERs, so only
 * this suite sees the real statements. DatabaseMigrations rolls the whole
 * schema back after each test, so EVERY TEST ENDS MIGRATED: that rollback
 * then runs each down() against the state it expects.
 */

/** @return list<mixed> the chain's migrations, oldest first — anonymous classes, so untyped here */
function socschemaMigrations(): array
{
    return array_map(static fn (string $file): mixed => require base_path("database/migrations/{$file}.php"), [
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
    ]);
}

/** @return array<string, list<string>> every CHECK the finished chain leaves, by table */
function socschemaCheckNames(): array
{
    return [
        'role_module_grants' => ['rmg_module_chk'],
        'community_group_members' => ['cgmembers_status_chk', 'cgmembers_role_chk'],
        'community_reactions' => ['creactions_type_chk'],
        'community_comment_reactions' => ['ccreactions_type_chk'],
        'community_tags' => ['ctags_tone_chk'],
        'community_attachments' => ['cattach_kind_chk'],
        'chat_conversations' => ['cconv_kind_chk'],
        'chat_messages' => ['cmsg_kind_chk'],
        'chat_reactions' => ['creact_type_chk'],
        'chat_participants' => ['cpart_role_chk'],
        'chat_attachments' => ['chattach_kind_chk'],
    ];
}

/** How many CHECK constraints of this name the table carries. */
function socschemaChecks(string $table, string $name): int
{
    $row = DB::selectOne(
        "SELECT COUNT(*) AS n FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ? AND CONSTRAINT_TYPE = 'CHECK'",
        [$table, $name],
    );

    return is_object($row) && isset($row->n) ? (int) $row->n : 0;
}

/** The grants CHECK's clause as the server keeps it. */
function socschemaGrantsClause(): string
{
    $row = DB::selectOne(
        "SELECT CHECK_CLAUSE AS clause FROM information_schema.CHECK_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_NAME = 'rmg_module_chk'",
    );

    return is_object($row) && isset($row->clause) ? (string) $row->clause : '';
}

/**
 * The table's index names, sorted — lower-cased by the schema builder.
 *
 * @return list<string>
 */
function socschemaIndexNames(string $table): array
{
    $names = array_map(static fn (array $index): string => $index['name'], Schema::getIndexes($table));
    sort($names);

    return $names;
}

/**
 * Every CREATE, ALTER or DROP the callback sends, in order.
 *
 * @return list<string>
 */
function socschemaDdlDuring(callable $callback): array
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

/** Every CHECK present exactly once, the retired tag CHECK absent, the grants CHECK naming both modules. */
function socschemaAssertChecks(): void
{
    foreach (socschemaCheckNames() as $table => $names) {
        foreach ($names as $name) {
            expect(socschemaChecks($table, $name))->toBe(1, "{$table}.{$name}");
        }
    }
    expect(socschemaChecks('community_posts', 'cposts_tag_chk'))->toBe(0);
    expect(socschemaGrantsClause())->toContain("'COMMUNITY'")->toContain("'CHAT'");
}

/** The two tables the kill tests strip, with exactly the indexes the design declares and nothing MariaDB added itself. */
function socschemaAssertDeclaredIndexes(): void
{
    // cgmembers_added_by_fk is the key's own index: 0008 declares no index on added_by.
    expect(socschemaIndexNames('community_group_members'))->toBe(['cgmembers_added_by_fk', 'cgmembers_group_user_unq', 'cgmembers_updated_idx', 'cgmembers_user_status_idx', 'primary']);
    expect(socschemaIndexNames('chat_participants'))->toBe(['cpart_conv_user_unq', 'cpart_user_idx', 'primary']);
    expect(socschemaIndexNames('community_attachments'))->toBe(['cattach_comment_position_idx', 'cphotos_post_position_idx', 'primary']);
}

it('applies every Social migration twice on top of a migrated schema, with each CHECK exactly once', function () {
    expect(in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true))->toBeTrue();
    // migrate:fresh has already run the chain through the migrator.
    socschemaAssertChecks();
    socschemaAssertDeclaredIndexes();

    foreach ([1, 2] as $run) {
        foreach (socschemaMigrations() as $migration) {
            $migration->up();
        }
    }

    socschemaAssertChecks();
    socschemaAssertDeclaredIndexes();
    expect(DB::table('community_tags')->count())->toBe(5);
});

it('refuses values outside the Social CHECK constraints and accepts a tag outside the old five', function () {
    $user = User::factory()->create();
    $now = now()->utc()->format('Y-m-d H:i:s.v');
    $group = (string) Str::ulid();
    $post = (string) Str::ulid();
    $chat = (string) Str::ulid();
    $message = (string) Str::ulid();
    DB::table('community_groups')->insert(['id' => $group, 'name' => 'Checks '.Str::random(6), 'created_by' => $user->id, 'created_at' => $now]);
    DB::table('community_posts')->insert(['id' => $post, 'author_id' => $user->id, 'body' => 'Hello', 'created_at' => $now]);
    DB::table('chat_conversations')->insert(['id' => $chat, 'kind' => 'GROUP', 'title' => 'Checks', 'created_by' => $user->id, 'created_at' => $now]);
    DB::table('chat_messages')->insert(['id' => $message, 'conversation_id' => $chat, 'sender_id' => $user->id, 'body' => 'Hi', 'created_at' => $now]);

    $refused = [
        ['community_group_members', ['group_id' => $group, 'user_id' => $user->id, 'status' => 'BANNED']],
        ['community_group_members', ['group_id' => $group, 'user_id' => $user->id, 'role' => 'KING']],
        ['community_reactions', ['post_id' => $post, 'user_id' => $user->id, 'type' => 'ANGRY']],
        ['community_tags', ['code' => 'NEON', 'name' => 'Neon', 'tone' => 'neon']],
        ['community_attachments', ['post_id' => $post, 'kind' => 'VIDEO', 'path' => 'community-files/x', 'mime' => 'video/mp4', 'size' => 1]],
        ['chat_conversations', ['kind' => 'CHANNEL']],
        ['chat_participants', ['conversation_id' => $chat, 'user_id' => $user->id, 'role' => 'OWNER']],
        ['chat_messages', ['conversation_id' => $chat, 'kind' => 'EMAIL']],
        ['chat_reactions', ['message_id' => $message, 'user_id' => $user->id, 'type' => 'ANGRY']],
        ['chat_attachments', ['message_id' => $message, 'kind' => 'VIDEO', 'path' => 'chat-files/x', 'mime' => 'video/mp4', 'size' => 1]],
    ];
    foreach ($refused as [$table, $row]) {
        expect(fn () => DB::table($table)->insert(['id' => (string) Str::ulid(), 'created_at' => $now, ...$row]))
            ->toThrow(QueryException::class);
    }

    // The tags table is the list now: a moderator's own tag needs no CHECK change.
    DB::table('community_posts')->where('id', $post)->update(['tag' => 'EVENTS']);
    expect(DB::table('community_posts')->where('id', $post)->value('tag'))->toBe('EVENTS');
});

it('resumes the table migrations from runs killed after their CREATE TABLEs', function () {
    [, $community, $chat] = socschemaMigrations();

    // Strip community_group_members back to what that run leaves of 0002's
    // pieces: the keys first, because MariaDB will not drop an index a key uses.
    foreach (['group_id', 'user_id'] as $column) {
        DB::statement("ALTER TABLE `community_group_members` DROP FOREIGN KEY `community_group_members_{$column}_foreign`");
    }
    foreach (['cgmembers_group_user_unq', 'cgmembers_user_status_idx', 'cgmembers_updated_idx'] as $index) {
        DB::statement("ALTER TABLE `community_group_members` DROP INDEX `{$index}`");
    }
    DB::statement('ALTER TABLE `community_group_members` DROP CONSTRAINT `cgmembers_status_chk`');
    expect(socschemaIndexNames('community_group_members'))->toBe(['cgmembers_added_by_fk', 'primary']);

    $ddl = socschemaDdlDuring(static fn () => $community->up());

    expect($ddl)->toHaveCount(11)
        ->and(strtolower($ddl[0]))->toContain('add unique `cgmembers_group_user_unq`')
        ->and(strtolower($ddl[1]))->toContain('add index `cgmembers_user_status_idx`')
        ->and(strtolower($ddl[2]))->toContain('add index `cgmembers_updated_idx`')
        ->and(strtolower($ddl[3]))->toContain('foreign key (`group_id`) references `community_groups`')
        ->and(strtolower($ddl[3]))->toContain('on delete cascade')
        ->and(strtolower($ddl[4]))->toContain('foreign key (`user_id`) references `users`')
        ->and($ddl[10])->toStartWith('ALTER TABLE `community_group_members` ADD CONSTRAINT `cgmembers_status_chk` CHECK');
    foreach (array_slice($ddl, 5, 5) as $statement) {
        expect($statement)->toEndWith('ROW_FORMAT=DYNAMIC');
    }

    // The same for chat_participants and 0003.
    foreach (['conversation_id', 'user_id'] as $column) {
        DB::statement("ALTER TABLE `chat_participants` DROP FOREIGN KEY `chat_participants_{$column}_foreign`");
    }
    foreach (['cpart_conv_user_unq', 'cpart_user_idx'] as $index) {
        DB::statement("ALTER TABLE `chat_participants` DROP INDEX `{$index}`");
    }
    expect(socschemaIndexNames('chat_participants'))->toBe(['primary']);

    $chat->up();

    // The indexes landed before the keys, so MariaDB reused them and added
    // none of its own; a second run of each sends only the restatements.
    socschemaAssertDeclaredIndexes();
    socschemaAssertChecks();
    foreach ([$community, $chat] as $migration) {
        foreach (socschemaDdlDuring(static fn () => $migration->up()) as $statement) {
            expect($statement)->toEndWith('ROW_FORMAT=DYNAMIC');
        }
    }
});

it('resumes the rename migrations from runs killed on either side of a rename', function () {
    $migrations = socschemaMigrations();
    [$reactions, $attachments] = [$migrations[4], $migrations[9]];

    // 0005 killed before its rename: the likes table as 0002 left it.
    DB::statement('ALTER TABLE `community_comment_reactions` DROP CONSTRAINT `ccreactions_type_chk`');
    DB::statement('ALTER TABLE `community_comment_reactions` DROP COLUMN `type`, DROP COLUMN `updated_at`');
    Schema::rename('community_comment_reactions', 'community_comment_likes');

    $reactions->up();

    expect(Schema::hasTable('community_comment_likes'))->toBeFalse()
        ->and(Schema::hasColumns('community_comment_reactions', ['type', 'updated_at']))->toBeTrue()
        // The user_id key keeps its own index (named for the likes table it was
        // made on): no declared index leads with user_id.
        ->and(socschemaIndexNames('community_comment_reactions'))->toBe(['clikes_comment_user_unq', 'clikes_created_idx', 'community_comment_likes_user_id_foreign', 'primary'])
        ->and(socschemaChecks('community_comment_reactions', 'ccreactions_type_chk'))->toBe(1);

    // 0010 killed after its first ALTER on the post side, and before the
    // rename on the chat side.
    DB::statement('ALTER TABLE `community_attachments` DROP CONSTRAINT `cattach_kind_chk`');
    DB::statement('ALTER TABLE `community_attachments` DROP FOREIGN KEY `cattach_comment_fk`');
    DB::statement('ALTER TABLE `community_attachments` DROP INDEX `cattach_comment_position_idx`');
    DB::statement('ALTER TABLE `community_attachments` DROP COLUMN `name`, DROP COLUMN `kind`');
    DB::statement('ALTER TABLE `chat_attachments` DROP CONSTRAINT `chattach_kind_chk`');
    DB::statement('ALTER TABLE `chat_attachments` DROP COLUMN `name`, DROP COLUMN `kind`');
    Schema::rename('chat_attachments', 'chat_message_photos');

    $attachments->up();

    expect(Schema::hasTable('chat_message_photos'))->toBeFalse()
        ->and(Schema::hasColumns('community_attachments', ['comment_id', 'kind', 'name']))->toBeTrue()
        ->and(Schema::hasColumns('chat_attachments', ['kind', 'name']))->toBeTrue()
        ->and(Schema::hasForeignKey('community_attachments', ['comment_id']))->toBeTrue();
    socschemaAssertDeclaredIndexes();
    socschemaAssertChecks();

    // Both find everything in place the second time and send no DDL at all.
    expect(socschemaDdlDuring(static function () use ($reactions, $attachments): void {
        $reactions->up();
        $attachments->up();
    }))->toBe([]);
});

it('removes every piece on a full rollback, tolerates a second one, and restores them all', function () {
    $migrations = socschemaMigrations();
    $social = ['community_groups', 'community_posts', 'community_attachments', 'community_comment_reactions', 'community_tags', 'chat_conversations', 'chat_attachments', 'social_preferences'];

    foreach ([1, 2] as $run) {
        foreach (array_reverse($migrations) as $migration) {
            $migration->down();
        }
        foreach ([...$social, 'community_post_photos', 'community_comment_likes', 'chat_message_photos'] as $table) {
            expect(Schema::hasTable($table))->toBeFalse("{$table} after rollback {$run}");
        }
        expect(socschemaGrantsClause())->not->toContain("'COMMUNITY'")->not->toContain("'CHAT'")->toContain("'PROFILE'");
    }

    foreach ([1, 2] as $run) {
        foreach ($migrations as $migration) {
            $migration->up();
        }
    }

    foreach ($social as $table) {
        expect(Schema::hasTable($table))->toBeTrue($table);
    }
    socschemaAssertChecks();
    socschemaAssertDeclaredIndexes();
    expect(DB::table('community_tags')->count())->toBe(5);
});
