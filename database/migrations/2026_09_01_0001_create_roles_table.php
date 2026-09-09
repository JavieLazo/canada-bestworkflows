<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Port of `model Role` (prisma/schema.prisma:76-89).
 *
 * Conventions that hold across every migration in this port:
 *
 *  - Primary keys are ULID `char(26)`, not `bigint` and not `uuid`. InnoDB
 *    clusters on the primary key, so a random UUIDv4 page-splits on every
 *    insert; PostgreSQL's heap tables did not care, which is why the source
 *    could use cuid().
 *  - Charset and collation are NOT set per table. They are inherited from the
 *    connection (utf8mb4 / utf8mb4_unicode_ci). Setting them per column would
 *    also break foreign keys: MySQL refuses a FK whose columns differ in
 *    charset or collation (ERROR 3780).
 *  - `$t->engine = 'InnoDB'` IS set explicitly. A host defaulting to MyISAM
 *    parses foreign keys and silently discards them.
 *  - Timestamps are DATETIME(3) storing UTC, never `$t->timestamps()`, which
 *    emits MySQL TIMESTAMP and converts against a session time zone shared
 *    hosts set inconsistently.
 *  - Indexes and uniques are declared BEFORE foreign keys, so MySQL reuses an
 *    existing leftmost-prefix index for the constraint instead of creating a
 *    second, redundant one.
 *  - The source declares ON UPDATE CASCADE on all 26 foreign keys; Laravel
 *    emits no ON UPDATE clause at all (RESTRICT). The difference is
 *    unreachable because ULIDs are immutable, so it is stated here rather than
 *    papered over with ->cascadeOnUpdate().
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $t): void {
            $t->engine = 'InnoDB';

            $t->char('id', 26)->primary();

            // Cap from src/app/api/roles/route.ts:19.
            $t->string('name', 80)->unique();

            // Sees every employee's records, not just their own.
            $t->boolean('all_data')->default(false);

            // Seeded roles that should not be deleted.
            $t->boolean('is_system')->default(false);

            $t->dateTime('created_at', 3);

            // The source has no updated_at on any table, but Eloquent writes
            // one on every UPDATE; without it the first rename dies with 42S22.
            // Added to mutable tables only — append-only tables keep
            // $timestamps = false so "edited after the fact" stays meaningful.
            $t->dateTime('updated_at', 3)->nullable();
        });

        $this->dynamicRowFormat('roles');
    }

    public function down(): void
    {
        Schema::dropIfExists('roles');
    }

    /**
     * ROW_FORMAT is set explicitly rather than inherited from
     * innodb_default_row_format, which a shared host may have left at COMPACT.
     * Guarded by driver because the test suite runs on SQLite in memory.
     */
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
