<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Group chats get admins and a photo.
 *
 * Anyone in a group chat can add people, rename it and change its photo, the
 * way Messenger works. Removing someone takes an admin. Whoever started each
 * group chat becomes its admin, as long as they're still in it.
 *
 * The photo is a small square the Social module makes from whatever was
 * picked. Its size is kept so it counts toward the Community and Chat storage
 * budget (`copems.uploads.social_storage_ceiling_bytes`).
 *
 * The role CHECK repeats Vocabulary::CHAT_ROLES, written out so a later edit
 * there can't quietly rewrite this migration. MySQL and MariaDB only.
 *
 * RETRY-SAFE, STEP BY STEP: each of the three columns only while hasColumn()
 * says it is missing, the CHECK only while information_schema lists none of
 * that name. The admin backfill only touches a group chat that has NO admin
 * yet, so it can run again after its admins changed without promoting anyone
 * a second time. (The chats that already have one are read first: MySQL
 * refuses an UPDATE whose subquery reads the table it updates.) down()
 * mirrors each step with the same guards, the CHECK before its column.
 *
 * ROLLBACK LOSES who is an admin of which chat, and each chat's photo; the
 * photo files stay on the disk, and `ems:check-files` reports them as left
 * over.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('chat_conversations', 'photo_path')) {
            Schema::table('chat_conversations', function (Blueprint $t): void {
                // Relative to the private `local` disk, under chat-photos/.
                $t->string('photo_path', 255)->nullable()->after('title');
            });
        }

        if (! Schema::hasColumn('chat_conversations', 'photo_size')) {
            Schema::table('chat_conversations', function (Blueprint $t): void {
                $t->unsignedInteger('photo_size')->nullable()->after('photo_path');
            });
        }

        if (! Schema::hasColumn('chat_participants', 'role')) {
            Schema::table('chat_participants', function (Blueprint $t): void {
                $t->string('role', 12)->default('MEMBER')->after('user_id');
            });
        }

        // Whoever started a group chat is its admin, if they're still in it —
        // in a chat with no admin yet.
        $administered = DB::table('chat_participants')->where('role', 'ADMIN')->distinct()->pluck('conversation_id')->all();
        DB::table('chat_participants')
            ->whereNotIn('conversation_id', $administered)
            ->whereExists(static function ($q): void {
                $q->selectRaw('1')
                    ->from('chat_conversations')
                    ->whereColumn('chat_conversations.id', 'chat_participants.conversation_id')
                    ->whereColumn('chat_conversations.created_by', 'chat_participants.user_id')
                    ->where('chat_conversations.kind', 'GROUP');
            })
            ->update(['role' => 'ADMIN']);

        if ($this->isMySql() && ! $this->hasCheck('chat_participants', 'cpart_role_chk')) {
            DB::statement("ALTER TABLE `chat_participants` ADD CONSTRAINT `cpart_role_chk` CHECK (`role` IN ('ADMIN','MEMBER'))");
        }
    }

    public function down(): void
    {
        if ($this->isMySql() && $this->hasCheck('chat_participants', 'cpart_role_chk')) {
            DB::statement('ALTER TABLE `chat_participants` DROP CONSTRAINT `cpart_role_chk`');
        }

        foreach ([['chat_participants', 'role'], ['chat_conversations', 'photo_size'], ['chat_conversations', 'photo_path']] as [$table, $column]) {
            if (Schema::hasColumn($table, $column)) {
                Schema::table($table, function (Blueprint $t) use ($column): void {
                    $t->dropColumn($column);
                });
            }
        }
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

    private function isMySql(): bool
    {
        return in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true);
    }
};
