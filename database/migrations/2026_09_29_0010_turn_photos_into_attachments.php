<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Posts, comments and messages can carry files now, not just photos, so the
 * two photo tables become attachment tables.
 *
 * Every row that's already there stays, as a PHOTO. A FILE row also keeps the
 * name it was uploaded with, since that's what people see and download it
 * as. On disk, photos stay under community-photos/ and chat-photos/, and
 * files go under community-files/ and chat-files/.
 *
 * A comment's attachments carry the post they sit under too, so everything
 * that decides who may see a post also decides who may open them, and
 * deleting a post still takes all of them in one go.
 *
 * Indexes and foreign keys keep their old names; renaming them would only
 * churn the schema. The kind CHECK repeats Vocabulary::ATTACHMENT_KINDS,
 * written out so a later edit there can't quietly rewrite this migration.
 *
 * RETRY-SAFE, STEP BY STEP: each rename only while the old table exists and
 * the new one does not; each added column only while hasColumn() says it is
 * missing; the comment index only while hasIndex() does not list it, and
 * BEFORE the comment key, only while hasForeignKey() finds none, so MySQL
 * reuses the index; each CHECK only while information_schema lists none of
 * that name. down() mirrors every step with the same guards: CHECKs before
 * the columns they name, the key before its index, the data cleanup only
 * while its column exists.
 *
 * ROLLBACK LOSES every FILE attachment and every attachment on a comment:
 * those rows are deleted, since only post and message photos existed before.
 * Their files stay on the disk, and `ems:check-files` reports them as left
 * over.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('community_post_photos') && ! Schema::hasTable('community_attachments')) {
            Schema::rename('community_post_photos', 'community_attachments');
        }

        if (! Schema::hasColumn('community_attachments', 'comment_id')) {
            Schema::table('community_attachments', function (Blueprint $t): void {
                $t->char('comment_id', 26)->nullable()->after('post_id');
            });
        }

        if (! Schema::hasColumn('community_attachments', 'kind')) {
            Schema::table('community_attachments', function (Blueprint $t): void {
                $t->string('kind', 8)->default('PHOTO')->after('comment_id');
            });
        }

        if (! Schema::hasColumn('community_attachments', 'name')) {
            Schema::table('community_attachments', function (Blueprint $t): void {
                $t->string('name', 255)->nullable()->after('path');
            });
        }

        if (! Schema::hasIndex('community_attachments', 'cattach_comment_position_idx')) {
            Schema::table('community_attachments', function (Blueprint $t): void {
                $t->index(['comment_id', 'position'], 'cattach_comment_position_idx');
            });
        }

        if (! Schema::hasForeignKey('community_attachments', ['comment_id'])) {
            Schema::table('community_attachments', function (Blueprint $t): void {
                $t->foreign('comment_id', 'cattach_comment_fk')->references('id')->on('community_comments')->cascadeOnDelete();
            });
        }

        if (Schema::hasTable('chat_message_photos') && ! Schema::hasTable('chat_attachments')) {
            Schema::rename('chat_message_photos', 'chat_attachments');
        }

        if (! Schema::hasColumn('chat_attachments', 'kind')) {
            Schema::table('chat_attachments', function (Blueprint $t): void {
                $t->string('kind', 8)->default('PHOTO')->after('message_id');
            });
        }

        if (! Schema::hasColumn('chat_attachments', 'name')) {
            Schema::table('chat_attachments', function (Blueprint $t): void {
                $t->string('name', 255)->nullable()->after('path');
            });
        }

        if ($this->isMySql() && ! $this->hasCheck('community_attachments', 'cattach_kind_chk')) {
            DB::statement("ALTER TABLE `community_attachments` ADD CONSTRAINT `cattach_kind_chk` CHECK (`kind` IN ('PHOTO','FILE'))");
        }

        if ($this->isMySql() && ! $this->hasCheck('chat_attachments', 'chattach_kind_chk')) {
            DB::statement("ALTER TABLE `chat_attachments` ADD CONSTRAINT `chattach_kind_chk` CHECK (`kind` IN ('PHOTO','FILE'))");
        }
    }

    public function down(): void
    {
        if ($this->isMySql() && $this->hasCheck('chat_attachments', 'chattach_kind_chk')) {
            DB::statement('ALTER TABLE `chat_attachments` DROP CONSTRAINT `chattach_kind_chk`');
        }

        if ($this->isMySql() && $this->hasCheck('community_attachments', 'cattach_kind_chk')) {
            DB::statement('ALTER TABLE `community_attachments` DROP CONSTRAINT `cattach_kind_chk`');
        }

        // Only post and message photos existed before. Files and comment
        // attachments have no place to go back to, so their rows go.
        if (Schema::hasColumn('chat_attachments', 'kind')) {
            DB::table('chat_attachments')->where('kind', '!=', 'PHOTO')->delete();
        }
        if (Schema::hasColumn('community_attachments', 'kind')) {
            DB::table('community_attachments')->where('kind', '!=', 'PHOTO')->delete();
        }
        if (Schema::hasColumn('community_attachments', 'comment_id')) {
            DB::table('community_attachments')->whereNotNull('comment_id')->delete();
        }

        foreach (['name', 'kind'] as $column) {
            if (Schema::hasColumn('chat_attachments', $column)) {
                Schema::table('chat_attachments', function (Blueprint $t) use ($column): void {
                    $t->dropColumn($column);
                });
            }
        }

        if (Schema::hasTable('chat_attachments') && ! Schema::hasTable('chat_message_photos')) {
            Schema::rename('chat_attachments', 'chat_message_photos');
        }

        if (Schema::hasForeignKey('community_attachments', ['comment_id'])) {
            // MySQL drops a key by its name; SQLite only by its column (a rebuild).
            $key = $this->isMySql() ? 'cattach_comment_fk' : ['comment_id'];
            Schema::table('community_attachments', function (Blueprint $t) use ($key): void {
                $t->dropForeign($key);
            });
        }

        if (Schema::hasIndex('community_attachments', 'cattach_comment_position_idx')) {
            Schema::table('community_attachments', function (Blueprint $t): void {
                $t->dropIndex('cattach_comment_position_idx');
            });
        }

        foreach (['name', 'kind', 'comment_id'] as $column) {
            if (Schema::hasColumn('community_attachments', $column)) {
                Schema::table('community_attachments', function (Blueprint $t) use ($column): void {
                    $t->dropColumn($column);
                });
            }
        }

        if (Schema::hasTable('community_attachments') && ! Schema::hasTable('community_post_photos')) {
            Schema::rename('community_attachments', 'community_post_photos');
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
