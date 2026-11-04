<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The five Social edit-column migrations (2026_10_15_0001 … 0005) — when a
 * post, a comment or a chat message was last edited, a post's earlier
 * versions, and when an edit took a photo or file off its post — survive a
 * repeated down() and a repeated up(), the cPanel cron re-running a
 * half-applied deploy. A rollback drops the column, a second rollback finds
 * nothing to undo and sends no DDL, a re-apply brings the column back, and a
 * second re-apply finds it there and sends no DDL.
 *
 * THIS FILE ALWAYS ENDS MIGRATED, pass or fail: each `finally` re-runs all
 * five up() in order (0004's column sits after 0001's) before anything else.
 * On MySQL/MariaDB DDL commits implicitly, so RefreshDatabase's transaction
 * cannot roll it back (the SocialMigrationsTest pattern). Nothing is seeded,
 * because on sqlite a column drop may rebuild the table and, inside
 * RefreshDatabase's transaction, that DROP TABLE cascades into children.
 */

/** @return list<string> the five files, oldest first */
function soceditFiles(): array
{
    return [
        '2026_10_15_0001_add_edited_at_to_community_posts_table',
        '2026_10_15_0002_add_edited_at_to_community_comments_table',
        '2026_10_15_0003_add_edited_at_to_chat_messages_table',
        '2026_10_15_0004_add_earlier_versions_to_community_posts_table',
        '2026_10_15_0005_add_removed_at_to_community_attachments_table',
    ];
}

/** One of the five by file name — an anonymous class, so untyped here. */
function soceditMigration(string $file): mixed
{
    return require base_path("database/migrations/{$file}.php");
}

function soceditUpAll(): void
{
    foreach (soceditFiles() as $file) {
        soceditMigration($file)->up();
    }
}

/**
 * Every CREATE, ALTER or DROP the callback sends, in order.
 *
 * @return list<string>
 */
function soceditDdlDuring(callable $callback): array
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

it('drops its column on a rollback, tolerates a second rollback, and re-applies it twice', function (string $file, string $table, string $column) {
    $migration = soceditMigration($file);

    try {
        // migrate:fresh already ran it through the migrator.
        expect(Schema::hasColumn($table, $column))->toBeTrue();

        $migration->down();
        expect(Schema::hasColumn($table, $column))->toBeFalse();

        // A repeated rollback finds nothing to undo.
        expect(soceditDdlDuring(static fn () => $migration->down()))->toBe([])
            ->and(Schema::hasColumn($table, $column))->toBeFalse();

        $migration->up();
        expect(Schema::hasColumn($table, $column))->toBeTrue();

        // The retry: the column is there, so the step is skipped.
        expect(soceditDdlDuring(static fn () => $migration->up()))->toBe([])
            ->and(Schema::hasColumn($table, $column))->toBeTrue();
    } finally {
        // Re-migrate FIRST, even when an expectation failed between the
        // rollback and the re-apply above.
        soceditUpAll();
    }
})->with([
    'community_posts.edited_at' => ['2026_10_15_0001_add_edited_at_to_community_posts_table', 'community_posts', 'edited_at'],
    'community_comments.edited_at' => ['2026_10_15_0002_add_edited_at_to_community_comments_table', 'community_comments', 'edited_at'],
    'chat_messages.edited_at' => ['2026_10_15_0003_add_edited_at_to_chat_messages_table', 'chat_messages', 'edited_at'],
    'community_posts.earlier_versions' => ['2026_10_15_0004_add_earlier_versions_to_community_posts_table', 'community_posts', 'earlier_versions'],
    'community_attachments.removed_at' => ['2026_10_15_0005_add_removed_at_to_community_attachments_table', 'community_attachments', 'removed_at'],
]);
