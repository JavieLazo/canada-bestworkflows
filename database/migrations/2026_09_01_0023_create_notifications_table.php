<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Port of `model Notification` (prisma/schema.prisma:776-798).
 *
 * THE NULL-IN-UNIQUE SEMANTICS ARE THE POINT OF THIS TABLE AND MUST NOT BE
 * "IMPROVED". `dedupe_key` is unique per (rule, record, trigger) for the
 * reminders the app derives on load, and NULL for event notifications, which
 * fire from a write that only happens once.
 *
 * MySQL/InnoDB and PostgreSQL AGREE: a UNIQUE index permits unlimited rows
 * where any indexed column is NULL. So the constraint below binds only where a
 * key is actually set — reminders dedupe, events repeat freely — which is
 * exactly the rule the demo store applied in code. Do NOT convert this to a
 * NOT NULL column with a sentinel value.
 *
 * This is also the ONE table where Laravel's upsert() is safe (a single unique
 * index besides the primary key) — provided the ULID is not supplied in the
 * values, or the primary key becomes a second collision target.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $t): void {
            $t->engine = 'InnoDB';

            $t->char('id', 26)->primary();
            $t->unsignedBigInteger('user_id');

            $t->string('kind', 60);
            $t->string('title', 300);

            // 2,000-char cap in the API.
            $t->text('body')->nullable();

            // Where clicking it should go.
            $t->string('href', 500)->nullable();

            // Sized at 191, not the API's 300-char cap: this column is half of a
            // unique index, and 191 keeps the key inside the 767-byte limit that
            // still applies under ROW_FORMAT=COMPACT. Generated dedupe keys are
            // far shorter than either bound; a longer one raises a visible 1406
            // under STRICT_TRANS_TABLES rather than truncating silently.
            $t->string('dedupe_key', 191)->nullable();

            $t->dateTime('read_at', 3)->nullable();
            $t->dateTime('created_at', 3);

            // Not in the source, but mark-as-read UPDATEs this table, so
            // Eloquent needs somewhere to write.
            $t->dateTime('updated_at', 3)->nullable();

            $t->unique(['user_id', 'dedupe_key'], 'notif_user_dedupe_unq');
            $t->index(['user_id', 'created_at'], 'notif_user_created_idx');

            $t->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });

        $this->dynamicRowFormat('notifications');
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }

    private function dynamicRowFormat(string ...$tables): void
    {
        if (! in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }

        foreach ($tables as $table) {
            DB::statement("ALTER TABLE `{$table}` ROW_FORMAT=DYNAMIC");
        }
    }
};
