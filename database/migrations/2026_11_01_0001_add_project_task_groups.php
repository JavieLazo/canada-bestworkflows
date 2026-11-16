<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Rollback refuses retained groups; task records and their review history must stay intact.
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        $this->guardSqliteTransaction();
        if (! Schema::hasTable('project_task_groups')) {
            Schema::create('project_task_groups', function (Blueprint $table): void {
                $table->engine = 'InnoDB';
                $table->char('id', 26)->primary();
                $table->char('project_id', 26);
                $table->string('name', 120);
                $table->dateTime('created_at', 3);
                $table->dateTime('updated_at', 3)->nullable();
                $table->unique(['project_id', 'name'], 'ptg_project_name_unique');
                $table->unique(['id', 'project_id'], 'ptg_id_project_unique');
                $table->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();
            });
        }
        if (! Schema::hasColumn('project_tasks', 'group_id')) {
            Schema::table('project_tasks', fn (Blueprint $table) => $table->char('group_id', 26)->nullable());
        }
        if (! Schema::hasIndex('project_tasks', 'ptg_task_group_project_idx')) {
            Schema::table('project_tasks', fn (Blueprint $table) => $table->index(['group_id', 'project_id'], 'ptg_task_group_project_idx'));
        }
        if (! collect(Schema::getForeignKeys('project_tasks'))->contains(static fn (array $key): bool => $key['columns'] === ['group_id', 'project_id'])) {
            Schema::table('project_tasks', fn (Blueprint $table) => $table->foreign(['group_id', 'project_id'])
                ->references(['id', 'project_id'])->on('project_task_groups')->noActionOnDelete());
        }
    }

    public function down(): void
    {
        $this->guardSqliteTransaction();
        if ((Schema::hasTable('project_task_groups') && DB::table('project_task_groups')->exists())
            || (Schema::hasColumn('project_tasks', 'group_id') && DB::table('project_tasks')->whereNotNull('group_id')->exists())) {
            throw new RuntimeException('Keep task groups while their data exists. Back up and explicitly clear the grouping before a schema rollback.');
        }
        if (collect(Schema::getForeignKeys('project_tasks'))->contains(static fn (array $key): bool => $key['columns'] === ['group_id', 'project_id'])) {
            Schema::table('project_tasks', fn (Blueprint $table) => $table->dropForeign(['group_id', 'project_id']));
        }
        if (Schema::hasIndex('project_tasks', 'ptg_task_group_project_idx')) {
            Schema::table('project_tasks', fn (Blueprint $table) => $table->dropIndex('ptg_task_group_project_idx'));
        }
        if (Schema::hasColumn('project_tasks', 'group_id')) {
            Schema::table('project_tasks', fn (Blueprint $table) => $table->dropColumn('group_id'));
        }
        if (Schema::hasTable('project_task_groups')) {
            Schema::drop('project_task_groups');
        }
    }

    private function guardSqliteTransaction(): void
    {
        if (DB::connection()->getDriverName() === 'sqlite' && DB::transactionLevel() > 0) {
            throw new RuntimeException('Run this schema migration outside an active SQLite transaction to preserve related work.');
        }
    }
};
