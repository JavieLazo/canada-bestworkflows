<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The Community module's tables: groups and their members, posts with their
 * photos, comments and their replies, post reactions and comment likes.
 * Later migrations build on these: comment likes became reactions
 * (2026_09_29_0005), tags got a table (0006), groups got roles and a photo
 * (0008), and post photos became attachments that comments can carry too
 * (0010).
 *
 * AUTHORSHIP IS AN ACCOUNT (`users.id`), like `task_comments.author_id`: an
 * account is what signs in and posts, and an account with no employee record
 * can still take part. Author columns are SET NULL rather than CASCADE, so a
 * post outlives an account that is somehow removed; in practice the Social
 * module's AccountReferences keeps an author's account from being deleted at
 * all. A reaction, like or membership means nothing without its account and
 * cascades with it.
 *
 * A post is to Everyone (`group_id` NULL) or to one group. Deleting a group
 * deletes its posts; deleting a post deletes its photos' rows (the files are
 * removed by the command after commit), comments, reactions and likes.
 *
 * Every timestamp is DATETIME(3): the feed and every thread order by
 * `created_at`, and two posts in the same second must not reorder.
 *
 * The lone `updated_at` / `created_at` indexes serve the 60-second change
 * check (CommunityFeed::stamp), which reads MAX() of each: an index answers
 * that without touching the rows.
 *
 * RETRY-SAFE, STEP BY STEP. Production migrates from a temporary cPanel cron
 * with no SSH and MySQL DDL does not roll back, so a run can stop between any
 * two statements and is simply run again. Each CREATE carries only the
 * columns and the primary key; every other piece is its own ALTER behind its
 * own guard, so a retry resumes wherever the last run stopped: a table only
 * while hasTable() is false, an index only while hasIndex() does not list its
 * name, a foreign key only while hasForeignKey() finds none on its column,
 * and a CHECK only while information_schema lists no constraint of that
 * name. Indexes come BEFORE the foreign keys that use them, so MySQL reuses
 * them instead of adding its own. ROW_FORMAT=DYNAMIC restates the same
 * format, so it needs no guard. On SQLite (tests) each foreign key is a table
 * rebuild and no CHECK is created.
 *
 * THE CHAIN'S LATER STATE COUNTS AS DONE. 0005 renames community_comment_likes
 * to community_comment_reactions, 0010 renames community_post_photos to
 * community_attachments (indexes and keys keep their names), and 0006
 * replaces the fixed tag list with the community_tags table and drops
 * `cposts_tag_chk`. An up() that runs again after those — a retry test, or a
 * database that applied the chain under its pre-2026-09-29 file names — must
 * not bring the old tables or the old CHECK back, so those steps stand down
 * once the later state exists.
 *
 * ROLLBACK LOSES every group, membership, post, comment, reaction and like,
 * and every photo's row; the photo files stay on the disk, and
 * `ems:check-files` reports them as left over. down() drops each table, and
 * every index, key and CHECK with it, only while it exists.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('community_groups')) {
            Schema::create('community_groups', function (Blueprint $t): void {
                $t->engine = 'InnoDB';

                $t->char('id', 26)->primary();
                $t->string('name', 80);
                $t->string('description', 280)->nullable();
                $t->boolean('is_private')->default(false);
                $t->unsignedBigInteger('created_by')->nullable();
                $t->dateTime('created_at', 3);
                $t->dateTime('updated_at', 3)->nullable();
            });
        }
        $this->index('community_groups', 'name', 'cgroups_name_unq', unique: true);
        $this->foreign('community_groups', 'created_by', 'users', cascade: false);

        if (! Schema::hasTable('community_group_members')) {
            Schema::create('community_group_members', function (Blueprint $t): void {
                $t->engine = 'InnoDB';

                $t->char('id', 26)->primary();
                $t->char('group_id', 26);
                $t->unsignedBigInteger('user_id');

                // MEMBER, or REQUESTED while a private group has not admitted them.
                $t->string('status', 12)->default('MEMBER');

                $t->dateTime('created_at', 3);
                $t->dateTime('updated_at', 3)->nullable();
            });
        }
        $this->index('community_group_members', ['group_id', 'user_id'], 'cgmembers_group_user_unq', unique: true);
        $this->index('community_group_members', ['user_id', 'status'], 'cgmembers_user_status_idx');
        $this->index('community_group_members', 'updated_at', 'cgmembers_updated_idx');
        $this->foreign('community_group_members', 'group_id', 'community_groups', cascade: true);
        $this->foreign('community_group_members', 'user_id', 'users', cascade: true);

        if (! Schema::hasTable('community_posts')) {
            Schema::create('community_posts', function (Blueprint $t): void {
                $t->engine = 'InnoDB';

                $t->char('id', 26)->primary();
                $t->unsignedBigInteger('author_id')->nullable();

                // NULL is Everyone.
                $t->char('group_id', 26)->nullable();

                $t->string('tag', 20)->nullable();

                // 5,000-character cap in the Social module.
                $t->text('body');

                $t->dateTime('pinned_at', 3)->nullable();
                $t->unsignedBigInteger('pinned_by')->nullable();
                $t->dateTime('created_at', 3);
                $t->dateTime('updated_at', 3)->nullable();
            });
        }
        $this->index('community_posts', 'created_at', 'cposts_created_idx');
        $this->index('community_posts', ['group_id', 'created_at'], 'cposts_group_created_idx');
        $this->index('community_posts', 'pinned_at', 'cposts_pinned_idx');
        $this->index('community_posts', 'author_id', 'cposts_author_idx');
        $this->index('community_posts', 'updated_at', 'cposts_updated_idx');
        $this->foreign('community_posts', 'author_id', 'users', cascade: false);
        $this->foreign('community_posts', 'group_id', 'community_groups', cascade: true);
        $this->foreign('community_posts', 'pinned_by', 'users', cascade: false);

        // community_attachments since 0010: not recreated once that exists.
        if (! Schema::hasTable('community_post_photos') && ! Schema::hasTable('community_attachments')) {
            Schema::create('community_post_photos', function (Blueprint $t): void {
                $t->engine = 'InnoDB';

                $t->char('id', 26)->primary();
                $t->char('post_id', 26);

                // Relative to the private `local` disk, under community-photos/.
                $t->string('path', 255);
                $t->string('mime', 120);
                $t->unsignedInteger('size');
                $t->unsignedTinyInteger('position')->default(0);
                $t->dateTime('created_at', 3);
            });
        }
        $this->index('community_post_photos', ['post_id', 'position'], 'cphotos_post_position_idx');
        $this->foreign('community_post_photos', 'post_id', 'community_posts', cascade: true);

        if (! Schema::hasTable('community_comments')) {
            Schema::create('community_comments', function (Blueprint $t): void {
                $t->engine = 'InnoDB';

                $t->char('id', 26)->primary();
                $t->char('post_id', 26);

                // NULL for a comment; the comment it answers for a reply. The
                // Social module keeps threads three levels deep.
                $t->char('parent_id', 26)->nullable();

                $t->unsignedBigInteger('author_id')->nullable();

                // 2,000-character cap in the Social module.
                $t->text('body');

                $t->dateTime('created_at', 3);
            });
        }
        $this->index('community_comments', ['post_id', 'created_at'], 'ccomments_post_created_idx');
        $this->index('community_comments', 'parent_id', 'ccomments_parent_idx');
        $this->index('community_comments', 'author_id', 'ccomments_author_idx');
        $this->index('community_comments', 'created_at', 'ccomments_created_idx');
        $this->foreign('community_comments', 'post_id', 'community_posts', cascade: true);
        $this->foreign('community_comments', 'parent_id', 'community_comments', cascade: true);
        $this->foreign('community_comments', 'author_id', 'users', cascade: false);

        if (! Schema::hasTable('community_reactions')) {
            Schema::create('community_reactions', function (Blueprint $t): void {
                $t->engine = 'InnoDB';

                $t->char('id', 26)->primary();
                $t->char('post_id', 26);
                $t->unsignedBigInteger('user_id');
                $t->string('type', 12);
                $t->dateTime('created_at', 3);
                $t->dateTime('updated_at', 3)->nullable();
            });
        }
        // One reaction per person per post; a different one replaces it.
        $this->index('community_reactions', ['post_id', 'user_id'], 'creactions_post_user_unq', unique: true);
        $this->index('community_reactions', 'updated_at', 'creactions_updated_idx');
        $this->foreign('community_reactions', 'post_id', 'community_posts', cascade: true);
        $this->foreign('community_reactions', 'user_id', 'users', cascade: true);

        // community_comment_reactions since 0005: not recreated once that exists.
        if (! Schema::hasTable('community_comment_likes') && ! Schema::hasTable('community_comment_reactions')) {
            Schema::create('community_comment_likes', function (Blueprint $t): void {
                $t->engine = 'InnoDB';

                $t->char('id', 26)->primary();
                $t->char('comment_id', 26);
                $t->unsignedBigInteger('user_id');
                $t->dateTime('created_at', 3);
            });
        }
        $this->index('community_comment_likes', ['comment_id', 'user_id'], 'clikes_comment_user_unq', unique: true);
        $this->index('community_comment_likes', 'created_at', 'clikes_created_idx');
        $this->foreign('community_comment_likes', 'comment_id', 'community_comments', cascade: true);
        $this->foreign('community_comment_likes', 'user_id', 'users', cascade: true);

        $this->dynamicRowFormat(
            'community_groups', 'community_group_members', 'community_posts', 'community_post_photos',
            'community_comments', 'community_reactions', 'community_comment_likes',
        );

        // Enforced on MySQL 8.0.16+ / MariaDB 10.2.1+. The Social module's
        // lists are the real gate; these keep a hand-written row honest.
        $this->check('community_group_members', 'cgmembers_status_chk', "`status` IN ('MEMBER','REQUESTED')");
        // 0006 drops this one when the community_tags table takes over the list.
        if (! Schema::hasTable('community_tags')) {
            $this->check('community_posts', 'cposts_tag_chk', "`tag` IS NULL OR `tag` IN ('ANNOUNCEMENT','KUDOS','QUESTION','SOCIAL','LOST_FOUND')");
        }
        $this->check('community_reactions', 'creactions_type_chk', "`type` IN ('LIKE','LOVE','CELEBRATE','SUPPORT','INSIGHTFUL','FUNNY')");
    }

    public function down(): void
    {
        // Children first. Each drop takes the table's indexes, keys and CHECKs
        // with it, and is guarded like every step in up(), so a repeated
        // rollback runs no DDL at all.
        foreach ([
            'community_comment_likes', 'community_reactions', 'community_comments', 'community_post_photos',
            'community_posts', 'community_group_members', 'community_groups',
        ] as $table) {
            if (Schema::hasTable($table)) {
                Schema::drop($table);
            }
        }
    }

    /**
     * Adds a named index, or unique, unless the table already has one of that
     * name — or is gone, because a later migration in this chain renamed it.
     *
     * @param  string|list<string>  $columns
     */
    private function index(string $table, string|array $columns, string $name, bool $unique = false): void
    {
        if (! Schema::hasTable($table) || Schema::hasIndex($table, $name)) {
            return;
        }

        Schema::table($table, function (Blueprint $t) use ($columns, $name, $unique): void {
            $unique ? $t->unique($columns, $name) : $t->index($columns, $name);
        });
    }

    /** Adds the foreign key on $column to $on.id unless one is already there (CASCADE, or SET NULL). */
    private function foreign(string $table, string $column, string $on, bool $cascade): void
    {
        if (! Schema::hasTable($table) || Schema::hasForeignKey($table, [$column])) {
            return;
        }

        Schema::table($table, function (Blueprint $t) use ($column, $on, $cascade): void {
            $key = $t->foreign($column)->references('id')->on($on);
            $cascade ? $key->cascadeOnDelete() : $key->nullOnDelete();
        });
    }

    /** Adds the named CHECK unless it is already there — MySQL/MariaDB only. */
    private function check(string $table, string $name, string $expression): void
    {
        if (! $this->isMySql() || ! Schema::hasTable($table) || $this->hasCheck($table, $name)) {
            return;
        }

        DB::statement("ALTER TABLE `{$table}` ADD CONSTRAINT `{$name}` CHECK ({$expression})");
    }

    /** Whether the named CHECK exists — information_schema, so MySQL/MariaDB only. */
    private function hasCheck(string $table, string $name): bool
    {
        $row = DB::selectOne(
            "SELECT COUNT(*) AS n FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ? AND CONSTRAINT_TYPE = 'CHECK'",
            [$table, $name],
        );

        return (int) ($row->n ?? 0) > 0;
    }

    private function dynamicRowFormat(string ...$tables): void
    {
        if (! $this->isMySql()) {
            return;
        }

        foreach ($tables as $table) {
            if (Schema::hasTable($table)) {
                DB::statement("ALTER TABLE `{$table}` ROW_FORMAT=DYNAMIC");
            }
        }
    }

    private function isMySql(): bool
    {
        return in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true);
    }
};
