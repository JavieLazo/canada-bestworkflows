<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Rollback refuses retained definitions, answers and report submissions rather than losing their versions.
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('project_reporting_periods')) {
            Schema::create('project_reporting_periods', function (Blueprint $table): void {
                $table->engine = 'InnoDB';
                $table->char('id', 26)->primary();
                $table->char('project_id', 26);
                $table->string('name', 120);
                $table->date('starts_on');
                $table->date('ends_on');
                $table->date('due_on')->nullable();
                $table->boolean('archived')->default(false);
                $table->unsignedInteger('revision')->default(1);
                $table->json('history')->nullable();
                $table->unsignedBigInteger('created_by');
                $table->unsignedBigInteger('updated_by');
                $table->dateTime('created_at', 3);
                $table->dateTime('updated_at', 3)->nullable();
                $table->index(['project_id', 'starts_on'], 'prp_project_date_idx');
                $table->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();
                $table->foreign('created_by')->references('id')->on('users')->restrictOnDelete();
                $table->foreign('updated_by')->references('id')->on('users')->restrictOnDelete();
            });
        }
        if (! Schema::hasTable('project_report_fields')) {
            Schema::create('project_report_fields', function (Blueprint $table): void {
                $table->engine = 'InnoDB';
                $table->char('id', 26)->primary();
                $table->char('project_id', 26);
                $table->string('entity_type', 24);
                $table->string('name', 120);
                $table->string('type', 16);
                $table->string('unit', 60)->nullable();
                $table->string('visibility', 16)->default('PROJECT');
                $table->boolean('required')->default(false);
                $table->boolean('total')->default(false);
                $table->boolean('archived')->default(false);
                $table->json('options')->nullable();
                $table->unsignedInteger('revision')->default(1);
                $table->json('history')->nullable();
                $table->unsignedBigInteger('created_by');
                $table->unsignedBigInteger('updated_by');
                $table->dateTime('created_at', 3);
                $table->dateTime('updated_at', 3)->nullable();
                $table->index(['project_id', 'entity_type', 'archived'], 'prfields_project_entity_idx');
                $table->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();
                $table->foreign('created_by')->references('id')->on('users')->restrictOnDelete();
                $table->foreign('updated_by')->references('id')->on('users')->restrictOnDelete();
            });
        }
        if (! Schema::hasTable('project_record_values')) {
            Schema::create('project_record_values', function (Blueprint $table): void {
                $table->engine = 'InnoDB';
                $table->char('id', 26)->primary();
                $table->char('project_id', 26);
                $table->char('field_id', 26);
                $table->char('record_id', 26);
                $table->json('value')->nullable();
                $table->unsignedInteger('revision')->default(1);
                $table->unsignedBigInteger('updated_by');
                $table->dateTime('created_at', 3);
                $table->dateTime('updated_at', 3)->nullable();
                $table->unique(['field_id', 'record_id'], 'prv_field_record_unique');
                $table->index(['project_id', 'record_id'], 'prv_project_record_idx');
                $table->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();
                $table->foreign('field_id')->references('id')->on('project_report_fields')->restrictOnDelete();
                $table->foreign('updated_by')->references('id')->on('users')->restrictOnDelete();
            });
        }
        if (! Schema::hasTable('project_report_templates')) {
            Schema::create('project_report_templates', function (Blueprint $table): void {
                $table->engine = 'InnoDB';
                $table->char('id', 26)->primary();
                $table->char('project_id', 26);
                $table->string('code', 50)->nullable();
                $table->string('name', 160);
                $table->string('kind', 24);
                $table->text('description')->nullable();
                $table->json('definition');
                $table->boolean('work')->default(true);
                $table->boolean('financial')->default(false);
                $table->boolean('archived')->default(false);
                $table->unsignedInteger('revision')->default(1);
                $table->json('history')->nullable();
                $table->unsignedBigInteger('created_by');
                $table->unsignedBigInteger('updated_by');
                $table->dateTime('created_at', 3);
                $table->dateTime('updated_at', 3)->nullable();
                $table->unique(['project_id', 'code'], 'prt_project_code_unique');
                $table->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();
                $table->foreign('created_by')->references('id')->on('users')->restrictOnDelete();
                $table->foreign('updated_by')->references('id')->on('users')->restrictOnDelete();
            });
        }
        if (! Schema::hasTable('project_reports')) {
            Schema::create('project_reports', function (Blueprint $table): void {
                $table->engine = 'InnoDB';
                $table->char('id', 26)->primary();
                $table->char('project_id', 26);
                $table->char('template_id', 26);
                $table->char('period_id', 26);
                $table->char('budget_id', 26)->nullable();
                $table->string('title', 200);
                $table->string('status', 16)->default('DRAFT');
                $table->boolean('work')->default(true);
                $table->boolean('financial')->default(false);
                $table->text('summary')->nullable();
                $table->text('issues')->nullable();
                $table->text('corrective_action')->nullable();
                $table->text('amendment_reason')->nullable();
                $table->unsignedInteger('revision')->default(1);
                $table->char('current_submission_id', 26)->nullable();
                $table->unsignedBigInteger('created_by');
                $table->unsignedBigInteger('updated_by');
                $table->dateTime('created_at', 3);
                $table->dateTime('updated_at', 3)->nullable();
                $table->index(['project_id', 'status'], 'pr_project_status_idx');
                $table->foreign('project_id')->references('id')->on('projects')->restrictOnDelete();
                $table->foreign('template_id')->references('id')->on('project_report_templates')->restrictOnDelete();
                $table->foreign('period_id')->references('id')->on('project_reporting_periods')->restrictOnDelete();
                $table->foreign('budget_id')->references('id')->on('project_budgets')->restrictOnDelete();
                $table->foreign('created_by')->references('id')->on('users')->restrictOnDelete();
                $table->foreign('updated_by')->references('id')->on('users')->restrictOnDelete();
            });
        }
        if (! Schema::hasTable('project_report_submissions')) {
            Schema::create('project_report_submissions', function (Blueprint $table): void {
                $table->engine = 'InnoDB';
                $table->char('id', 26)->primary();
                $table->char('project_id', 26);
                $table->char('report_id', 26);
                $table->unsignedInteger('version');
                $table->unsignedInteger('template_version');
                $table->boolean('work')->default(true);
                $table->boolean('financial')->default(false);
                $table->json('field_ids');
                $table->longText('payload');
                $table->char('sha256', 64);
                $table->string('status', 16)->default('SUBMITTED');
                $table->unsignedBigInteger('submitted_by');
                $table->unsignedBigInteger('reviewer_id');
                $table->json('reviewer_history')->nullable();
                $table->unsignedBigInteger('reviewed_by')->nullable();
                $table->dateTime('reviewed_at', 3)->nullable();
                $table->text('review_notes')->nullable();
                $table->dateTime('created_at', 3);
                $table->dateTime('updated_at', 3)->nullable();
                $table->unique(['report_id', 'version'], 'prs_report_version_unique');
                $table->index(['reviewer_id', 'status'], 'prs_reviewer_status_idx');
                $table->foreign('project_id')->references('id')->on('projects')->restrictOnDelete();
                $table->foreign('report_id')->references('id')->on('project_reports')->restrictOnDelete();
                $table->foreign('submitted_by')->references('id')->on('users')->restrictOnDelete();
                $table->foreign('reviewer_id')->references('id')->on('users')->restrictOnDelete();
                $table->foreign('reviewed_by')->references('id')->on('users')->restrictOnDelete();
            });
        }
    }

    public function down(): void
    {
        $tables = ['project_report_submissions', 'project_reports', 'project_report_templates', 'project_record_values', 'project_report_fields', 'project_reporting_periods'];
        foreach ($tables as $table) {
            if (Schema::hasTable($table) && DB::table($table)->exists()) {
                throw new RuntimeException('Keep reporting definitions and submitted history while records exist. Back up and explicitly clear them before rollback.');
            }
        }
        foreach ($tables as $table) {
            if (Schema::hasTable($table)) {
                Schema::drop($table);
            }
        }
    }
};
