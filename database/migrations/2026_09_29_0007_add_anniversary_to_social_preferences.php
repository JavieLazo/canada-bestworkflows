<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Work anniversaries become a switch on My Profile too, off by default like
 * birthdays. A hire date isn't secret, but not everyone wants theirs
 * celebrated in front of the whole company, so it's their call.
 *
 * Off for everyone to start, rows that already exist included: nobody has
 * said yes yet.
 *
 * RETRY-SAFE: one ALTER, only while hasColumn() says the column is missing;
 * down() drops it only while it is there.
 *
 * ROLLBACK LOSES every anniversary choice; birthdays and job titles stay.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('social_preferences', 'show_anniversary')) {
            Schema::table('social_preferences', function (Blueprint $t): void {
                $t->boolean('show_anniversary')->default(false)->after('show_birthday');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('social_preferences', 'show_anniversary')) {
            Schema::table('social_preferences', function (Blueprint $t): void {
                $t->dropColumn('show_anniversary');
            });
        }
    }
};
