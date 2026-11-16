<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Rollback refuses retained plans, accomplishments and evidence so their history cannot disappear.
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('project_outcomes')) {
            Schema::create('project_outcomes', function (Blueprint $table): void {
                $table->engine = 'InnoDB';
                $table->char('id', 26)->primary();
                $table->char('project_id', 26);
                $table->char('parent_id', 26)->nullable();
                $table->string('kind', 16);
                $table->string('name', 200);
                $table->text('description')->nullable();
                $table->string('status', 16)->default('ACTIVE');
                $table->unsignedInteger('revision')->default(1);
                $table->json('history')->nullable();
                $table->unsignedBigInteger('created_by');
                $table->unsignedBigInteger('updated_by');
                $table->dateTime('created_at', 3);
                $table->dateTime('updated_at', 3)->nullable();
                $table->index(['project_id', 'status'], 'po_project_status_idx');
                $table->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();
                $table->foreign('parent_id')->references('id')->on('project_outcomes')->restrictOnDelete();
                $table->foreign('created_by')->references('id')->on('users')->restrictOnDelete();
                $table->foreign('updated_by')->references('id')->on('users')->restrictOnDelete();
            });
        }
        if (! Schema::hasTable('project_indicators')) {
            Schema::create('project_indicators', function (Blueprint $table): void {
                $table->engine = 'InnoDB';
                $table->char('id', 26)->primary();
                $table->char('project_id', 26);
                $table->char('outcome_id', 26)->nullable();
                $table->char('group_id', 26)->nullable();
                $table->char('task_id', 26)->nullable();
                $table->string('outcome_label', 200)->nullable();
                $table->string('work_label', 200)->nullable();
                $table->string('name', 200);
                $table->text('description')->nullable();
                $table->string('kind', 16)->default('NUMERIC');
                $table->string('unit', 60)->nullable();
                $table->string('aggregation', 12)->default('SUM');
                $table->string('direction', 12)->default('AT_LEAST');
                $table->bigInteger('target_scaled')->nullable();
                $table->string('target_text', 1000)->nullable();
                $table->date('starts_on')->nullable();
                $table->date('ends_on')->nullable();
                $table->boolean('evidence_required')->default(false);
                $table->string('status', 16)->default('ACTIVE');
                $table->unsignedInteger('revision')->default(1);
                $table->unsignedInteger('plan_version')->default(1);
                $table->json('history')->nullable();
                $table->unsignedBigInteger('created_by');
                $table->unsignedBigInteger('updated_by');
                $table->dateTime('created_at', 3);
                $table->dateTime('updated_at', 3)->nullable();
                $table->index(['project_id', 'status'], 'pi_project_status_idx');
                $table->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();
                $table->foreign('outcome_id')->references('id')->on('project_outcomes')->nullOnDelete();
                $table->foreign('group_id')->references('id')->on('project_task_groups')->nullOnDelete();
                $table->foreign('created_by')->references('id')->on('users')->restrictOnDelete();
                $table->foreign('updated_by')->references('id')->on('users')->restrictOnDelete();
            });
        }
        if (! Schema::hasTable('project_result_entries')) {
            Schema::create('project_result_entries', function (Blueprint $table): void {
                $table->engine = 'InnoDB';
                $table->char('id', 26)->primary();
                $table->char('project_id', 26);
                $table->char('indicator_id', 26);
                $table->unsignedInteger('plan_version');
                $table->boolean('evidence_required')->default(false);
                $table->date('occurred_on');
                $table->bigInteger('value_scaled')->nullable();
                $table->string('value_text', 1000)->nullable();
                $table->string('state', 20)->nullable();
                $table->text('narrative')->nullable();
                $table->text('issues')->nullable();
                $table->text('corrective_action')->nullable();
                $table->string('reference', 200)->nullable();
                $table->unsignedBigInteger('created_by');
                $table->char('replaces_id', 26)->nullable();
                $table->dateTime('voided_at', 3)->nullable();
                $table->unsignedBigInteger('voided_by')->nullable();
                $table->string('void_reason', 1000)->nullable();
                $table->dateTime('created_at', 3);
                $table->dateTime('updated_at', 3)->nullable();
                $table->index(['indicator_id', 'voided_at', 'occurred_on', 'id'], 'pre_indicator_date_idx');
                $table->index(['project_id', 'occurred_on'], 'pre_project_date_idx');
                $table->foreign('project_id')->references('id')->on('projects')->restrictOnDelete();
                $table->foreign('indicator_id')->references('id')->on('project_indicators')->restrictOnDelete();
                $table->foreign('replaces_id')->references('id')->on('project_result_entries')->restrictOnDelete();
                $table->foreign('created_by')->references('id')->on('users')->restrictOnDelete();
                $table->foreign('voided_by')->references('id')->on('users')->restrictOnDelete();
            });
        }
        if (! Schema::hasTable('project_record_files')) {
            Schema::create('project_record_files', function (Blueprint $table): void {
                $table->engine = 'InnoDB';
                $table->char('id', 26)->primary();
                $table->char('project_id', 26);
                $table->string('entity_type', 32);
                $table->char('record_id', 26);
                $table->string('file_name', 255);
                $table->string('path', 255);
                $table->string('mime_type', 150);
                $table->unsignedBigInteger('file_size');
                $table->char('sha256', 64);
                $table->unsignedBigInteger('uploaded_by');
                $table->dateTime('removed_at', 3)->nullable();
                $table->dateTime('retained_at', 3)->nullable();
                $table->dateTime('created_at', 3);
                $table->dateTime('updated_at', 3)->nullable();
                $table->index(['project_id', 'entity_type', 'record_id'], 'prf_record_idx');
                $table->foreign('project_id')->references('id')->on('projects')->restrictOnDelete();
                $table->foreign('uploaded_by')->references('id')->on('users')->restrictOnDelete();
            });
        }
    }

    public function down(): void
    {
        $tables = ['project_record_files', 'project_result_entries', 'project_indicators', 'project_outcomes'];
        foreach ($tables as $table) {
            if (Schema::hasTable($table) && DB::table($table)->exists()) {
                throw new RuntimeException('Keep results and evidence while their records exist. Back up and explicitly clear them before schema rollback.');
            }
        }
        foreach ($tables as $table) {
            if (Schema::hasTable($table)) {
                Schema::drop($table);
            }
        }
    }
};
