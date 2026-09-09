<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Port of `model AuditLog` (prisma/schema.prisma:759-772).
 *
 * Append-only, and `actor_id` deliberately carries NO foreign key while
 * `actor_name` is denormalised: the trail has to stay readable after the
 * account that wrote it is gone. Do not add a constraint here — a CASCADE would
 * destroy the trail and a SET NULL would leave it unattributable.
 *
 * `actor_id` is unsignedBigInteger to match `users.id` in this port (see the
 * extend-users migration for why accounts kept the starter kit's bigint key).
 * `actor_name` holds the session email, which src/lib/api/audit.ts:29 writes.
 *
 * No updated_at: the model carries `public $timestamps = false`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $t): void {
            $t->engine = 'InnoDB';

            $t->char('id', 26)->primary();

            // No FK, on purpose. See the docblock.
            $t->unsignedBigInteger('actor_id')->nullable();

            // The session email, or whatever identified a pre-sign-in attempt
            // (a failed sign-in, the first-run bootstrap).
            $t->string('actor_name', 200);

            $t->string('action', 80);
            $t->string('entity', 80)->nullable();

            // Holds a ULID (26) from most tables or a bigint account id.
            $t->string('entity_id', 64)->nullable();

            // Free-form, composed server-side and occasionally long.
            $t->text('details')->nullable();

            $t->dateTime('created_at', 3);

            $t->index('created_at', 'audit_created_idx');
            $t->index('action', 'audit_action_idx');
        });

        $this->dynamicRowFormat('audit_logs');
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
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
