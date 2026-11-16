<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Rollback refuses retained hierarchy; task and review records must not lose their parent context.
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        $this->guardSqliteTransaction();
        if (! Schema::hasColumn('project_tasks', 'parent_id')) {
            Schema::table('project_tasks', fn (Blueprint $table) => $table->char('parent_id', 26)->nullable());
        }
        if (! Schema::hasIndex('project_tasks', 'tasks_id_project_unique')) {
            Schema::table('project_tasks', fn (Blueprint $table) => $table->unique(['id', 'project_id'], 'tasks_id_project_unique'));
        }
        if (! Schema::hasIndex('project_tasks', 'tasks_parent_project_idx')) {
            Schema::table('project_tasks', fn (Blueprint $table) => $table->index(['parent_id', 'project_id'], 'tasks_parent_project_idx'));
        }
        if (! collect(Schema::getForeignKeys('project_tasks'))->contains(static fn (array $key): bool => $key['columns'] === ['parent_id', 'project_id'])) {
            Schema::table('project_tasks', fn (Blueprint $table) => $table->foreign(['parent_id', 'project_id'])
                ->references(['id', 'project_id'])->on('project_tasks')->cascadeOnDelete());
        }
    }

    public function down(): void
    {
        $this->guardSqliteTransaction();
        if (Schema::hasColumn('project_tasks', 'parent_id') && DB::table('project_tasks')->whereNotNull('parent_id')->exists()) {
            throw new RuntimeException('Keep hierarchy-aware task reads while subtasks exist. Back up and agree the hierarchy before schema rollback.');
        }
        if (collect(Schema::getForeignKeys('project_tasks'))->contains(static fn (array $key): bool => $key['columns'] === ['parent_id', 'project_id'])) {
            Schema::table('project_tasks', fn (Blueprint $table) => $table->dropForeign(['parent_id', 'project_id']));
        }
        foreach (['tasks_parent_project_idx', 'tasks_id_project_unique'] as $index) {
            if (Schema::hasIndex('project_tasks', $index)) {
                Schema::table('project_tasks', fn (Blueprint $table) => $table->dropIndex($index));
            }
        }
        if (Schema::hasColumn('project_tasks', 'parent_id')) {
            Schema::table('project_tasks', fn (Blueprint $table) => $table->dropColumn('parent_id'));
        }
    }

    private function guardSqliteTransaction(): void
    {
        if (DB::connection()->getDriverName() === 'sqlite' && DB::transactionLevel() > 0) {
            throw new RuntimeException('Run this schema migration outside an active SQLite transaction to preserve related work.');
        }
    }
};
