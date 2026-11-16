<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Rollback refuses linked projects; removing their client boundary needs an explicit data plan.
return new class extends Migration
{
    // SQLite rebuilds foreign keys, so schema changes must not share an application transaction.
    public $withinTransaction = false;

    public function up(): void
    {
        $this->guardSqliteTransaction();
        if (! Schema::hasColumn('projects', 'client_id')) {
            Schema::table('projects', function (Blueprint $table): void {
                $table->char('client_id', 26)->nullable();
            });
        }
        if (! collect(Schema::getForeignKeys('projects'))->contains(static fn (array $key): bool => $key['columns'] === ['client_id'])) {
            Schema::table('projects', fn (Blueprint $table) => $table->foreign('client_id')->references('id')->on('project_clients')->restrictOnDelete());
        }
        if (! Schema::hasColumn('projects', 'internal_name')) {
            Schema::table('projects', function (Blueprint $table): void {
                // Trimming the CHAR input keeps the index stable across MariaDB padding modes.
                $table->string('internal_name', 200)->nullable()->virtualAs('CASE WHEN RTRIM(client_id) IS NULL THEN name ELSE NULL END');
            });
        }
        if (! Schema::hasIndex('projects', 'projects_internal_name_unique')) {
            Schema::table('projects', fn (Blueprint $table) => $table->unique('internal_name', 'projects_internal_name_unique'));
        }
        if (! Schema::hasIndex('projects', 'projects_client_name_unique')) {
            Schema::table('projects', fn (Blueprint $table) => $table->unique(['client_id', 'name'], 'projects_client_name_unique'));
        }
        if (Schema::hasIndex('projects', 'projects_name_unique')) {
            Schema::table('projects', fn (Blueprint $table) => $table->dropUnique('projects_name_unique'));
        }
    }

    public function down(): void
    {
        $this->guardSqliteTransaction();
        if (Schema::hasColumn('projects', 'client_id') && DB::table('projects')->whereNotNull('client_id')->exists()) {
            throw new RuntimeException('Keep the client-aware application while client projects exist. Back up and agree their mapping before rollback.');
        }
        if (collect(Schema::getForeignKeys('projects'))->contains(static fn (array $key): bool => $key['columns'] === ['client_id'])) {
            Schema::table('projects', fn (Blueprint $table) => $table->dropForeign(['client_id']));
        }
        if (! Schema::hasIndex('projects', 'projects_name_unique')) {
            Schema::table('projects', fn (Blueprint $table) => $table->unique('name', 'projects_name_unique'));
        }
        foreach (['projects_client_name_unique', 'projects_internal_name_unique'] as $index) {
            if (Schema::hasIndex('projects', $index)) {
                Schema::table('projects', fn (Blueprint $table) => $table->dropUnique($index));
            }
        }
        if (Schema::hasColumn('projects', 'internal_name')) {
            Schema::table('projects', fn (Blueprint $table) => $table->dropColumn('internal_name'));
        }
        if (Schema::hasColumn('projects', 'client_id')) {
            Schema::table('projects', function (Blueprint $table): void {
                $table->dropColumn('client_id');
            });
        }
    }

    private function guardSqliteTransaction(): void
    {
        if (DB::connection()->getDriverName() === 'sqlite' && DB::transactionLevel() > 0) {
            throw new RuntimeException('Run this schema migration outside an active SQLite transaction to preserve related work.');
        }
    }
};
