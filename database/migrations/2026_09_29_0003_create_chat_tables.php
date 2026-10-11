<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The Chat module's tables: conversations, who is in each, messages with
 * their photos, and message reactions.
 *
 * One direct chat per pair. `direct_key` holds the two account ids, lower
 * first ("12:57"), under a unique index, so two people opening a chat with
 * each other at the same moment still end up with one. Group chats leave it
 * NULL, which a unique index never compares.
 *
 * Read state is one column. `chat_participants.last_read_at` is the newest
 * message each person has seen. Unread counts and "Seen by" both come from
 * comparing it with `chat_messages.created_at`, which is why both are
 * DATETIME(3).
 *
 * A removed message keeps its row (`removed_at` set, body cleared), so the
 * thread shows "removed a message" and a reply that quoted it still makes
 * sense. `sender_id` is NULL on SYSTEM rows such as "created the group".
 *
 * RETRY-SAFE, STEP BY STEP, exactly as 2026_09_29_0002 describes: each
 * CREATE carries only the columns and the primary key, and every index,
 * foreign key and CHECK is its own guarded ALTER, indexes before the keys
 * that use them. 2026_09_29_0010 renames chat_message_photos to
 * chat_attachments (indexes and keys keep their names), so once that exists
 * the photo table's steps stand down instead of recreating it.
 *
 * ROLLBACK LOSES every conversation, participant, message, reaction and
 * photo row; the photo files stay on the disk, and `ems:check-files` reports
 * them as left over. down() drops each table only while it exists.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('chat_conversations')) {
            Schema::create('chat_conversations', function (Blueprint $t): void {
                $t->engine = 'InnoDB';

                $t->char('id', 26)->primary();

                // DIRECT or GROUP.
                $t->string('kind', 10);

                // Group chats only; a direct chat is named after the other person.
                $t->string('title', 60)->nullable();

                // "lowerId:higherId" for DIRECT, NULL for GROUP.
                $t->string('direct_key', 41)->nullable();

                $t->unsignedBigInteger('created_by')->nullable();

                // Denormalised so the conversation list sorts without a subquery.
                $t->dateTime('last_message_at', 3)->nullable();

                $t->dateTime('created_at', 3);
                $t->dateTime('updated_at', 3)->nullable();
            });
        }
        $this->index('chat_conversations', 'direct_key', 'cconv_direct_key_unq', unique: true);
        $this->index('chat_conversations', 'last_message_at', 'cconv_last_message_idx');
        $this->foreign('chat_conversations', 'created_by', 'users', cascade: false);

        if (! Schema::hasTable('chat_participants')) {
            Schema::create('chat_participants', function (Blueprint $t): void {
                $t->engine = 'InnoDB';

                $t->char('id', 26)->primary();
                $t->char('conversation_id', 26);
                $t->unsignedBigInteger('user_id');
                $t->boolean('is_muted')->default(false);
                $t->dateTime('last_read_at', 3)->nullable();
                $t->dateTime('created_at', 3);
                $t->dateTime('updated_at', 3)->nullable();
            });
        }
        $this->index('chat_participants', ['conversation_id', 'user_id'], 'cpart_conv_user_unq', unique: true);
        $this->index('chat_participants', 'user_id', 'cpart_user_idx');
        $this->foreign('chat_participants', 'conversation_id', 'chat_conversations', cascade: true);
        $this->foreign('chat_participants', 'user_id', 'users', cascade: true);

        if (! Schema::hasTable('chat_messages')) {
            Schema::create('chat_messages', function (Blueprint $t): void {
                $t->engine = 'InnoDB';

                $t->char('id', 26)->primary();
                $t->char('conversation_id', 26);
                $t->unsignedBigInteger('sender_id')->nullable();

                // TEXT, or SYSTEM for lines like "created the group".
                $t->string('kind', 10)->default('TEXT');

                // 2,000-character cap in the Social module; NULL once removed.
                $t->text('body')->nullable();

                $t->char('reply_to_id', 26)->nullable();
                $t->dateTime('removed_at', 3)->nullable();
                $t->dateTime('created_at', 3);
                $t->dateTime('updated_at', 3)->nullable();
            });
        }
        $this->index('chat_messages', ['conversation_id', 'created_at'], 'cmsg_conv_created_idx');
        $this->index('chat_messages', 'sender_id', 'cmsg_sender_idx');
        $this->index('chat_messages', 'updated_at', 'cmsg_updated_idx');
        // An index, not a foreign key. A reply always quotes a message in its
        // own conversation (the Social module checks), and messages are only
        // ever hard-deleted with their whole conversation. A self-referencing
        // cascade would also stop at InnoDB's 15-level limit on a long chain
        // of replies to replies.
        $this->index('chat_messages', 'reply_to_id', 'cmsg_reply_to_idx');
        $this->foreign('chat_messages', 'conversation_id', 'chat_conversations', cascade: true);
        $this->foreign('chat_messages', 'sender_id', 'users', cascade: false);

        // chat_attachments since 0010: not recreated once that exists.
        if (! Schema::hasTable('chat_message_photos') && ! Schema::hasTable('chat_attachments')) {
            Schema::create('chat_message_photos', function (Blueprint $t): void {
                $t->engine = 'InnoDB';

                $t->char('id', 26)->primary();
                $t->char('message_id', 26);

                // Relative to the private `local` disk, under chat-photos/.
                $t->string('path', 255);
                $t->string('mime', 120);
                $t->unsignedInteger('size');
                $t->unsignedTinyInteger('position')->default(0);
                $t->dateTime('created_at', 3);
            });
        }
        $this->index('chat_message_photos', ['message_id', 'position'], 'cmphotos_message_position_idx');
        $this->foreign('chat_message_photos', 'message_id', 'chat_messages', cascade: true);

        if (! Schema::hasTable('chat_reactions')) {
            Schema::create('chat_reactions', function (Blueprint $t): void {
                $t->engine = 'InnoDB';

                $t->char('id', 26)->primary();
                $t->char('message_id', 26);
                $t->unsignedBigInteger('user_id');
                $t->string('type', 12);
                $t->dateTime('created_at', 3);
                $t->dateTime('updated_at', 3)->nullable();
            });
        }
        // One reaction per person per message; a different one replaces it.
        $this->index('chat_reactions', ['message_id', 'user_id'], 'creact_message_user_unq', unique: true);
        $this->index('chat_reactions', 'updated_at', 'creact_updated_idx');
        $this->foreign('chat_reactions', 'message_id', 'chat_messages', cascade: true);
        $this->foreign('chat_reactions', 'user_id', 'users', cascade: true);

        $this->dynamicRowFormat('chat_conversations', 'chat_participants', 'chat_messages', 'chat_message_photos', 'chat_reactions');

        $this->check('chat_conversations', 'cconv_kind_chk', "`kind` IN ('DIRECT','GROUP')");
        $this->check('chat_messages', 'cmsg_kind_chk', "`kind` IN ('TEXT','SYSTEM')");
        $this->check('chat_reactions', 'creact_type_chk', "`type` IN ('LIKE','LOVE','CELEBRATE','SUPPORT','INSIGHTFUL','FUNNY')");
    }

    public function down(): void
    {
        // Children first; guarded like every step in up().
        foreach (['chat_reactions', 'chat_message_photos', 'chat_messages', 'chat_participants', 'chat_conversations'] as $table) {
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
