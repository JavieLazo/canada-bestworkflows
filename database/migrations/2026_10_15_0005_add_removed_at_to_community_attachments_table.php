<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When an edit took a photo or file off its post. It isn't deleted: the
 * post's edit history still shows it, so it stays until the post itself is
 * deleted.
 *
 * Null means it's on the post.
 *
 * Safe to run twice: it adds the column only while it's missing, and drops
 * it only while it's there. Rolling back puts every photo and file that was
 * taken off back on its post.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('community_attachments', 'removed_at')) {
            Schema::table('community_attachments', function (Blueprint $t): void {
                $t->dateTime('removed_at', 3)->nullable()->after('position');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('community_attachments', 'removed_at')) {
            Schema::table('community_attachments', function (Blueprint $t): void {
                $t->dropColumn('removed_at');
            });
        }
    }
};
