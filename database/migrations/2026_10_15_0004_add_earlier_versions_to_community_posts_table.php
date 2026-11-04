<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A post's earlier versions, so anyone who can see it can look back at what
 * it said before each edit. They sit on the post itself, each earlier text
 * with when it went up, so they go when the post goes.
 *
 * Null means no edit has saved an earlier version.
 *
 * Safe to run twice: it adds the column only while it's missing, and drops
 * it only while it's there. Rolling back loses every earlier version, but
 * each post keeps its current text.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('community_posts', 'earlier_versions')) {
            Schema::table('community_posts', function (Blueprint $t): void {
                $t->json('earlier_versions')->nullable()->after('edited_at');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('community_posts', 'earlier_versions')) {
            Schema::table('community_posts', function (Blueprint $t): void {
                $t->dropColumn('earlier_versions');
            });
        }
    }
};
