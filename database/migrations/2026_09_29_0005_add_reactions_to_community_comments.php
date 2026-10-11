<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Comments get the same six reactions as posts, instead of Like only.
 *
 * The likes table becomes the reactions table, and every like already in it
 * becomes a Like, so nothing anyone did goes missing. Its indexes and
 * foreign keys keep their old names; renaming them would only churn the
 * schema.
 *
 * The CHECK repeats Vocabulary::REACTIONS, written out so a later edit there
 * can't quietly rewrite what this migration did. MySQL and MariaDB only, like
 * the other checks.
 *
 * RETRY-SAFE, STEP BY STEP: the rename only while the likes table exists and
 * the reactions table does not; `type` and `updated_at` each only while
 * hasColumn() says it is missing (two statements, two guards); the CHECK
 * only while information_schema lists no constraint of that name. down()
 * mirrors each step with the same guards, the CHECK before the column it
 * names.
 *
 * ROLLBACK LOSES every comment reaction other than Like: those rows are
 * deleted, and the rest go back to being likes.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('community_comment_likes') && ! Schema::hasTable('community_comment_reactions')) {
            Schema::rename('community_comment_likes', 'community_comment_reactions');
        }

        if (! Schema::hasColumn('community_comment_reactions', 'type')) {
            Schema::table('community_comment_reactions', function (Blueprint $t): void {
                $t->string('type', 12)->default('LIKE')->after('user_id');
            });
        }

        if (! Schema::hasColumn('community_comment_reactions', 'updated_at')) {
            Schema::table('community_comment_reactions', function (Blueprint $t): void {
                $t->dateTime('updated_at', 3)->nullable()->after('created_at');
            });
        }

        if ($this->isMySql() && ! $this->hasCheck('community_comment_reactions', 'ccreactions_type_chk')) {
            DB::statement("ALTER TABLE `community_comment_reactions` ADD CONSTRAINT `ccreactions_type_chk` CHECK (`type` IN ('LIKE','LOVE','CELEBRATE','SUPPORT','INSIGHTFUL','FUNNY'))");
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('community_comment_reactions')) {
            return;
        }

        if ($this->isMySql() && $this->hasCheck('community_comment_reactions', 'ccreactions_type_chk')) {
            DB::statement('ALTER TABLE `community_comment_reactions` DROP CONSTRAINT `ccreactions_type_chk`');
        }

        if (Schema::hasColumn('community_comment_reactions', 'type')) {
            // Only likes existed before, so everything else goes.
            DB::table('community_comment_reactions')->where('type', '!=', 'LIKE')->delete();

            Schema::table('community_comment_reactions', function (Blueprint $t): void {
                $t->dropColumn('type');
            });
        }

        if (Schema::hasColumn('community_comment_reactions', 'updated_at')) {
            Schema::table('community_comment_reactions', function (Blueprint $t): void {
                $t->dropColumn('updated_at');
            });
        }

        if (! Schema::hasTable('community_comment_likes')) {
            Schema::rename('community_comment_reactions', 'community_comment_likes');
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
