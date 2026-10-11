<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Every group gets its own people in charge, and a photo.
 *
 * A membership now carries a role: OWNER (one per group, whoever made it,
 * and it can be handed on), MODERATOR (picked by the owner), or MEMBER. The
 * owner and the group's moderators answer join requests and manage members.
 * Whoever made each group today becomes its owner, as long as they're still
 * in it.
 *
 * `added_by` remembers who put someone forward. Adding someone to a private
 * group makes a request the owner or a moderator still has to accept, and
 * the list says who asked.
 *
 * The photo is a small square the Social module makes from whatever was
 * picked. Its size is kept so it counts toward the Community and Chat storage
 * budget (`copems.uploads.social_storage_ceiling_bytes`).
 *
 * The role CHECK repeats Vocabulary::GROUP_ROLES, written out so a later edit
 * there can't quietly rewrite this migration. MySQL and MariaDB only.
 *
 * RETRY-SAFE, STEP BY STEP: each of the four columns only while hasColumn()
 * says it is missing, the `added_by` key only while hasForeignKey() finds
 * none, the CHECK only while information_schema lists none of that name.
 * The owner backfill only touches a group that has NO owner yet, so it can
 * run again after an owner handed their group on without making a second
 * one. (The groups that already have one are read first: MySQL refuses an
 * UPDATE whose subquery reads the table it updates.) down() mirrors each
 * step with the same guards, the CHECK and the key before their columns.
 *
 * ROLLBACK LOSES every role and every "added by", and each group's photo; the
 * photo files stay on the disk, and `ems:check-files` reports them as left
 * over.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('community_groups', 'photo_path')) {
            Schema::table('community_groups', function (Blueprint $t): void {
                // Relative to the private `local` disk, under community-photos/.
                $t->string('photo_path', 255)->nullable()->after('is_private');
            });
        }

        if (! Schema::hasColumn('community_groups', 'photo_size')) {
            Schema::table('community_groups', function (Blueprint $t): void {
                $t->unsignedInteger('photo_size')->nullable()->after('photo_path');
            });
        }

        if (! Schema::hasColumn('community_group_members', 'role')) {
            Schema::table('community_group_members', function (Blueprint $t): void {
                $t->string('role', 12)->default('MEMBER')->after('status');
            });
        }

        if (! Schema::hasColumn('community_group_members', 'added_by')) {
            Schema::table('community_group_members', function (Blueprint $t): void {
                $t->unsignedBigInteger('added_by')->nullable()->after('role');
            });
        }

        if (! Schema::hasForeignKey('community_group_members', ['added_by'])) {
            Schema::table('community_group_members', function (Blueprint $t): void {
                $t->foreign('added_by', 'cgmembers_added_by_fk')->references('id')->on('users')->nullOnDelete();
            });
        }

        // Whoever made a group owns it, if they're still a member — in a group
        // with no owner yet.
        $owned = DB::table('community_group_members')->where('role', 'OWNER')->distinct()->pluck('group_id')->all();
        DB::table('community_group_members')
            ->where('status', 'MEMBER')
            ->whereNotIn('group_id', $owned)
            ->whereExists(static function ($q): void {
                $q->selectRaw('1')
                    ->from('community_groups')
                    ->whereColumn('community_groups.id', 'community_group_members.group_id')
                    ->whereColumn('community_groups.created_by', 'community_group_members.user_id');
            })
            ->update(['role' => 'OWNER']);

        if ($this->isMySql() && ! $this->hasCheck('community_group_members', 'cgmembers_role_chk')) {
            DB::statement("ALTER TABLE `community_group_members` ADD CONSTRAINT `cgmembers_role_chk` CHECK (`role` IN ('OWNER','MODERATOR','MEMBER'))");
        }
    }

    public function down(): void
    {
        if ($this->isMySql() && $this->hasCheck('community_group_members', 'cgmembers_role_chk')) {
            DB::statement('ALTER TABLE `community_group_members` DROP CONSTRAINT `cgmembers_role_chk`');
        }

        if (Schema::hasForeignKey('community_group_members', ['added_by'])) {
            // MySQL drops a key by its name; SQLite only by its column (a rebuild).
            $key = $this->isMySql() ? 'cgmembers_added_by_fk' : ['added_by'];
            Schema::table('community_group_members', function (Blueprint $t) use ($key): void {
                $t->dropForeign($key);
            });
        }

        foreach ([['community_group_members', 'added_by'], ['community_group_members', 'role'], ['community_groups', 'photo_size'], ['community_groups', 'photo_path']] as [$table, $column]) {
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
