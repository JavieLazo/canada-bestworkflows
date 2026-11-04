<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When a chat message was last edited. A message can be edited with no
 * time limit, and an edited one says so in its bubble.
 *
 * Null means the message was never edited. `updated_at` can't stand in for
 * it, since removing a message writes that too.
 *
 * Safe to run twice: it adds the column only while it's missing, and drops
 * it only while it's there. Rolling back forgets which messages were
 * edited, but their new text stays.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('chat_messages', 'edited_at')) {
            Schema::table('chat_messages', function (Blueprint $t): void {
                $t->dateTime('edited_at', 3)->nullable()->after('removed_at');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('chat_messages', 'edited_at')) {
            Schema::table('chat_messages', function (Blueprint $t): void {
                $t->dropColumn('edited_at');
            });
        }
    }
};
