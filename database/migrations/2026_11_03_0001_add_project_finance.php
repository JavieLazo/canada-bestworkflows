<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Rollback refuses retained financial records, settings and grants so it cannot erase their history.
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('project_finances')) {
            Schema::create('project_finances', function (Blueprint $table): void {
                $table->engine = 'InnoDB';
                $table->char('id', 26)->primary();
                $table->char('project_id', 26)->unique();
                $table->char('currency', 3);
                $table->dateTime('created_at', 3);
                $table->dateTime('updated_at', 3)->nullable();
                $table->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();
            });
        }
        if (! Schema::hasTable('project_finance_members')) {
            Schema::create('project_finance_members', function (Blueprint $table): void {
                $table->engine = 'InnoDB';
                $table->char('id', 26)->primary();
                $table->char('project_id', 26);
                $table->unsignedBigInteger('user_id');
                $table->string('role', 10);
                $table->dateTime('created_at', 3);
                $table->dateTime('updated_at', 3)->nullable();
                $table->unique(['project_id', 'user_id'], 'pfm_project_user_unique');
                $table->index(['user_id', 'role', 'project_id'], 'pfm_user_role_project_idx');
                $table->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();
                $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            });
        }
        if (! Schema::hasTable('project_cost_categories')) {
            Schema::create('project_cost_categories', function (Blueprint $table): void {
                $table->engine = 'InnoDB';
                $table->char('id', 26)->primary();
                $table->char('project_id', 26);
                $table->string('name', 100);
                $table->dateTime('created_at', 3);
                $table->dateTime('updated_at', 3)->nullable();
                $table->unique(['project_id', 'name'], 'pcc_project_name_unique');
                $table->unique(['id', 'project_id'], 'pcc_id_project_unique');
                $table->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();
            });
        }
        if (! Schema::hasTable('project_budgets')) {
            Schema::create('project_budgets', function (Blueprint $table): void {
                $table->engine = 'InnoDB';
                $table->char('id', 26)->primary();
                $table->char('project_id', 26);
                $table->unsignedInteger('version');
                $table->unsignedInteger('revision')->default(1);
                $table->string('status', 10)->default('DRAFT');
                $table->string('reason', 1000)->nullable();
                $table->unsignedBigInteger('total_minor')->nullable();
                $table->unsignedBigInteger('created_by');
                $table->unsignedBigInteger('approved_by')->nullable();
                $table->dateTime('approved_at', 3)->nullable();
                $table->dateTime('created_at', 3);
                $table->dateTime('updated_at', 3)->nullable();
                $table->unique(['project_id', 'version'], 'pb_project_version_unique');
                $table->unique(['id', 'project_id'], 'pb_id_project_unique');
                $table->foreign('project_id')->references('id')->on('projects')->restrictOnDelete();
                $table->foreign('created_by')->references('id')->on('users')->restrictOnDelete();
                $table->foreign('approved_by')->references('id')->on('users')->restrictOnDelete();
            });
        }
        if (! Schema::hasTable('project_budget_lines')) {
            Schema::create('project_budget_lines', function (Blueprint $table): void {
                $table->engine = 'InnoDB';
                $table->char('id', 26)->primary();
                $table->char('project_id', 26);
                $table->char('budget_id', 26);
                $table->char('category_id', 26);
                $table->char('group_id', 26)->nullable();
                $table->string('category_label', 100);
                $table->string('group_label', 200)->nullable();
                $table->string('label', 200);
                $table->string('basis', 12);
                $table->unsignedBigInteger('quantity_scaled')->nullable();
                $table->unsignedInteger('frequency')->nullable();
                $table->unsignedBigInteger('unit_minor')->nullable();
                $table->unsignedBigInteger('total_minor');
                $table->text('notes')->nullable();
                $table->dateTime('deleted_at', 3)->nullable();
                $table->dateTime('created_at', 3);
                $table->dateTime('updated_at', 3)->nullable();
                $table->foreign(['budget_id', 'project_id'], 'pbl_budget_project_fk')->references(['id', 'project_id'])->on('project_budgets')->cascadeOnDelete();
                $table->foreign(['category_id', 'project_id'], 'pbl_category_project_fk')->references(['id', 'project_id'])->on('project_cost_categories')->restrictOnDelete();
                $table->foreign('group_id')->references('id')->on('project_task_groups')->nullOnDelete();
            });
        }
        if (! Schema::hasTable('project_cash_entries')) {
            Schema::create('project_cash_entries', function (Blueprint $table): void {
                $table->engine = 'InnoDB';
                $table->char('id', 26)->primary();
                $table->char('project_id', 26);
                $table->char('category_id', 26);
                $table->char('group_id', 26)->nullable();
                $table->string('category_label', 100);
                $table->string('group_label', 200)->nullable();
                $table->string('kind', 10);
                $table->date('occurred_on');
                $table->unsignedBigInteger('amount_minor');
                $table->string('description', 200);
                $table->string('party', 200)->nullable();
                $table->string('reference', 200)->nullable();
                $table->text('notes')->nullable();
                $table->unsignedBigInteger('created_by');
                $table->char('replaces_id', 26)->nullable()->unique();
                $table->unsignedBigInteger('voided_by')->nullable();
                $table->dateTime('voided_at', 3)->nullable();
                $table->string('void_reason', 1000)->nullable();
                $table->dateTime('created_at', 3);
                $table->dateTime('updated_at', 3)->nullable();
                $table->index(['project_id', 'voided_at', 'occurred_on'], 'pce_project_live_date_idx');
                $table->foreign('project_id')->references('id')->on('projects')->restrictOnDelete();
                $table->foreign(['category_id', 'project_id'], 'pce_category_project_fk')->references(['id', 'project_id'])->on('project_cost_categories')->restrictOnDelete();
                $table->foreign('group_id')->references('id')->on('project_task_groups')->nullOnDelete();
                $table->foreign('replaces_id')->references('id')->on('project_cash_entries')->restrictOnDelete();
                $table->foreign('created_by')->references('id')->on('users')->restrictOnDelete();
                $table->foreign('voided_by')->references('id')->on('users')->restrictOnDelete();
            });
        }
    }

    public function down(): void
    {
        $tables = ['project_cash_entries', 'project_budget_lines', 'project_budgets', 'project_cost_categories', 'project_finance_members', 'project_finances'];
        foreach ($tables as $table) {
            if (Schema::hasTable($table) && DB::table($table)->exists()) {
                throw new RuntimeException('Keep project finance while its records exist. Back up and explicitly clear them before a schema rollback.');
            }
        }
        foreach ($tables as $table) {
            if (Schema::hasTable($table)) {
                Schema::drop($table);
            }
        }
    }
};
