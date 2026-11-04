<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When a post was last edited. An edited post says so beside its time, so
 * anyone who reacted or replied to an earlier version can tell the words
 * changed since.
 *
 * Null means the post was never edited.
 *
 * Safe to run twice: it adds the column only while it's missing, and drops
 * it only while it's there. Rolling back forgets which posts were edited,
 * but their new text stays.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('community_posts', 'edited_at')) {
            Schema::table('community_posts', function (Blueprint $t): void {
                $t->dateTime('edited_at', 3)->nullable()->after('pinned_by');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('community_posts', 'edited_at')) {
            Schema::table('community_posts', function (Blueprint $t): void {
                $t->dropColumn('edited_at');
            });
        }
    }
};
