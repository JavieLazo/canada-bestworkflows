<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What each person chose to show about themselves in Community and Chat.
 *
 * One row per account, and only the switches they flipped. No row means the
 * defaults: job title shown, birthday hidden. That way a fresh install needs
 * no backfill, and a new account starts out the same as everyone else.
 *
 * It lives in its own table instead of a column on theirs, so the Social
 * module owns its own data and the accounts table stays as they wrote it.
 *
 * RETRY-SAFE, STEP BY STEP: the create carries only the columns and the
 * primary key; the unique and the foreign key are separate ALTERs, each only
 * while hasIndex() / hasForeignKey() does not find it, the unique first so
 * MySQL reuses it for the key. ROW_FORMAT=DYNAMIC restates the same format.
 * `show_anniversary` arrives in 2026_09_29_0007, not here.
 *
 * ROLLBACK LOSES every privacy choice: everyone goes back to the defaults.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('social_preferences')) {
            Schema::create('social_preferences', function (Blueprint $t): void {
                $t->engine = 'InnoDB';

                $t->char('id', 26)->primary();
                $t->unsignedBigInteger('user_id');

                // Their job title beside their name. On unless they say otherwise.
                $t->boolean('show_position')->default(true);

                // Day and month in the "Coming up" card. Off until they ask for it,
                // never the year and never an age.
                $t->boolean('show_birthday')->default(false);

                $t->dateTime('created_at', 3);
                $t->dateTime('updated_at', 3)->nullable();
            });
        }

        if (! Schema::hasIndex('social_preferences', 'sprefs_user_unq')) {
            Schema::table('social_preferences', function (Blueprint $t): void {
                $t->unique('user_id', 'sprefs_user_unq');
            });
        }

        if (! Schema::hasForeignKey('social_preferences', ['user_id'])) {
            Schema::table('social_preferences', function (Blueprint $t): void {
                $t->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            });
        }

        $this->dynamicRowFormat('social_preferences');
    }

    public function down(): void
    {
        // The unique and the key go with the table; guarded like up().
        if (Schema::hasTable('social_preferences')) {
            Schema::drop('social_preferences');
        }
    }

    /** The row format the rest of this app's tables use, on MySQL and MariaDB only. */
    private function dynamicRowFormat(string $table): void
    {
        if (! in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }

        DB::statement("ALTER TABLE `{$table}` ROW_FORMAT=DYNAMIC");
    }
};
