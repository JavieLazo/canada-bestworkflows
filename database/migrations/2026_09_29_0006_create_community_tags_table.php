<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Tags become something a moderator can add, rename, recolour and remove,
 * instead of a fixed list of five.
 *
 * A post keeps storing the tag's code, so nothing on the posts moves. The code
 * never changes once a tag exists, which is why renaming a tag leaves its
 * posts alone. The five tags the app started with are created here, so every
 * install has them from its first migrate. Announcement is one of them, and
 * an ordinary tag like the rest.
 *
 * The fixed list the posts table was checked against goes, since the tags
 * table is the list now. The tone CHECK repeats Vocabulary::TAG_TONES,
 * written out so a later edit there can't quietly rewrite this migration.
 *
 * RETRY-SAFE, STEP BY STEP: the create carries only the columns and the
 * primary key; each unique and the foreign key are separate ALTERs behind
 * hasIndex() / hasForeignKey(), the uniques first. The five starters go in
 * as ONE multi-row INSERT, so a run stops either before it or after all
 * five, and only while the table is still empty: a retry adds nothing, and a
 * starter a moderator has since renamed or removed stays that way — unless
 * every tag is gone, which reads as a table never seeded. Ids are
 * minted here and timestamps written with milliseconds, like BaseModel's. The
 * tone CHECK is added only while it is absent, and `cposts_tag_chk` dropped
 * only while it is present.
 *
 * ROLLBACK LOSES every tag a moderator added, renamed or recoloured: posts
 * carrying a code outside the five starters lose their tag, and the fixed
 * five-tag CHECK comes back on the posts table.
 */
return new class extends Migration
{
    /** @var list<array{string, string, string}> code, name, tone */
    private const array STARTERS = [
        ['ANNOUNCEMENT', 'Announcement', 'primary'],
        ['KUDOS', 'Kudos', 'success'],
        ['QUESTION', 'Question', 'caution'],
        ['SOCIAL', 'Social', 'muted'],
        ['LOST_FOUND', 'Lost & found', 'muted'],
    ];

    public function up(): void
    {
        if (! Schema::hasTable('community_tags')) {
            Schema::create('community_tags', function (Blueprint $t): void {
                $t->engine = 'InnoDB';

                $t->char('id', 26)->primary();

                // What a post stores. Set once, when the tag is made.
                $t->string('code', 20);
                $t->string('name', 30);

                // One of the app's pill tones: muted, primary, info, success, caution, danger.
                $t->string('tone', 12)->default('muted');

                // Where it sits in the tag menu.
                $t->unsignedSmallInteger('position')->default(0);

                $t->unsignedBigInteger('created_by')->nullable();
                $t->dateTime('created_at', 3);
                $t->dateTime('updated_at', 3)->nullable();
            });
        }

        if (! Schema::hasIndex('community_tags', 'ctags_code_unq')) {
            Schema::table('community_tags', function (Blueprint $t): void {
                $t->unique('code', 'ctags_code_unq');
            });
        }

        if (! Schema::hasIndex('community_tags', 'ctags_name_unq')) {
            Schema::table('community_tags', function (Blueprint $t): void {
                $t->unique('name', 'ctags_name_unq');
            });
        }

        if (! Schema::hasForeignKey('community_tags', ['created_by'])) {
            Schema::table('community_tags', function (Blueprint $t): void {
                $t->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            });
        }

        if (DB::table('community_tags')->doesntExist()) {
            $now = now()->utc()->format('Y-m-d H:i:s.v');
            $rows = [];
            foreach (self::STARTERS as $i => [$code, $name, $tone]) {
                $rows[] = [
                    'id' => (string) Str::ulid(),
                    'code' => $code,
                    'name' => $name,
                    'tone' => $tone,
                    'position' => $i + 1,
                    'created_at' => $now,
                ];
            }
            DB::table('community_tags')->insert($rows);
        }

        if ($this->isMySql()) {
            DB::statement('ALTER TABLE `community_tags` ROW_FORMAT=DYNAMIC');
        }

        if ($this->isMySql() && ! $this->hasCheck('community_tags', 'ctags_tone_chk')) {
            DB::statement("ALTER TABLE `community_tags` ADD CONSTRAINT `ctags_tone_chk` CHECK (`tone` IN ('muted','primary','info','success','caution','danger'))");
        }

        if ($this->isMySql() && $this->hasCheck('community_posts', 'cposts_tag_chk')) {
            DB::statement('ALTER TABLE `community_posts` DROP CONSTRAINT `cposts_tag_chk`');
        }
    }

    public function down(): void
    {
        $codes = array_column(self::STARTERS, 0);

        // A post tagged with a tag that didn't exist before loses it, so the
        // old check fits again. A repeated rollback finds nothing to clear,
        // and none at all once 2026_09_29_0002's own rollback took the posts.
        if (Schema::hasTable('community_posts')) {
            DB::table('community_posts')->whereNotNull('tag')->whereNotIn('tag', $codes)->update(['tag' => null]);

            if ($this->isMySql() && ! $this->hasCheck('community_posts', 'cposts_tag_chk')) {
                DB::statement("ALTER TABLE `community_posts` ADD CONSTRAINT `cposts_tag_chk` CHECK (`tag` IS NULL OR `tag` IN ('".implode("','", $codes)."'))");
            }
        }

        // The uniques, the key and the tone CHECK go with the table.
        if (Schema::hasTable('community_tags')) {
            Schema::drop('community_tags');
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
