<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When a comment was last edited. Like an edited post, an edited comment
 * says so beside its time.
 *
 * Null means the comment was never edited. Comments have no `updated_at`,
 * so this is also how other people's feeds notice that a comment changed.
 *
 * Safe to run twice: it adds the column only while it's missing, and drops
 * it only while it's there. Rolling back forgets which comments were edited,
 * but their new text stays.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('community_comments', 'edited_at')) {
            Schema::table('community_comments', function (Blueprint $t): void {
                $t->dateTime('edited_at', 3)->nullable()->after('body');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('community_comments', 'edited_at')) {
            Schema::table('community_comments', function (Blueprint $t): void {
                $t->dropColumn('edited_at');
            });
        }
    }
};
