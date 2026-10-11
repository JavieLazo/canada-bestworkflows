<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Technical identities let configuration and demo seeders reuse generated
 * primary keys even after a catalog's display name changes. This registry
 * has no business model or foreign key: it spans independent tables and a
 * deleted seed row may legitimately be recreated with a different key.
 *
 * RETRY-SAFE: table creation is guarded; the independent row-format step can
 * run again after an interrupted migration. No business rows are changed.
 * Rollback loses the registry, so renamed generated rows can no longer be
 * recognized by their seed identity. Restore it before reseeding that data.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('seed_identities')) {
            Schema::create('seed_identities', function (Blueprint $table): void {
                $table->engine = 'InnoDB';
                $table->string('table_name', 64);
                $table->string('seed_key', 128);
                $table->char('row_id', 26);
                $table->primary(['table_name', 'seed_key'], 'seed_identities_pk');
            });
        }

        $this->dynamicRowFormat();
    }

    public function down(): void
    {
        Schema::dropIfExists('seed_identities');
    }

    private function dynamicRowFormat(): void
    {
        if (in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement('ALTER TABLE seed_identities ROW_FORMAT=DYNAMIC');
        }
    }
};
