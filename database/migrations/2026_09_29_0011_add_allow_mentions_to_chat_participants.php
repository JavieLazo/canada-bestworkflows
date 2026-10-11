<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Muting a group chat gets a second option: mute everything, or mute it but
 * still hear when someone mentions you.
 *
 * `allow_mentions` only means something while `is_muted` is on. Everyone
 * muted so far stays muted the way they chose, so it starts off for all.
 *
 * RETRY-SAFE: one ALTER, only while hasColumn() says the column is missing;
 * down() drops it only while it is there.
 *
 * ROLLBACK LOSES every "mute, but tell me about mentions" choice: those
 * chats go back to fully muted.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('chat_participants', 'allow_mentions')) {
            Schema::table('chat_participants', function (Blueprint $t): void {
                $t->boolean('allow_mentions')->default(false)->after('is_muted');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('chat_participants', 'allow_mentions')) {
            Schema::table('chat_participants', function (Blueprint $t): void {
                $t->dropColumn('allow_mentions');
            });
        }
    }
};
