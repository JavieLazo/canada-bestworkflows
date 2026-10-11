<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Retains replaced hashes for per-account password reuse checks — NEVER plaintext.
 * The current hash remains in users and is archived on each successful change,
 * so no credential rewrite or backfill is needed. Table, index and foreign key
 * are guarded independently so an interrupted MariaDB DDL run can resume.
 * Rollback loses password history; current credentials and sessions remain intact.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('user_password_histories')) {
            Schema::create('user_password_histories', static function (Blueprint $table): void {
                $table->engine = 'InnoDB';
                $table->char('id', 26)->primary();
                $table->unsignedBigInteger('user_id');
                $table->string('password_hash', 255);
                $table->dateTime('created_at', 3);
            });
        }

        if (! Schema::hasIndex('user_password_histories', 'uph_user_idx')) {
            Schema::table('user_password_histories', static function (Blueprint $table): void {
                $table->index('user_id', 'uph_user_idx');
            });
        }

        if (! Schema::hasForeignKey('user_password_histories', ['user_id'])) {
            Schema::table('user_password_histories', static function (Blueprint $table): void {
                $table->foreign('user_id', 'uph_user_fk')->references('id')->on('users')->cascadeOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('user_password_histories')) {
            Schema::drop('user_password_histories');
        }
    }
};
