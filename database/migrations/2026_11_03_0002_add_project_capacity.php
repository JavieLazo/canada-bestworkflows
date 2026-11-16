<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Rollback refuses retained availability and effort so it cannot erase planning inputs.
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('project_availability')) {
            Schema::create('project_availability', function (Blueprint $table): void {
                $table->engine = 'InnoDB';
                $table->char('id', 26)->primary();
                $table->char('employee_id', 26);
                $table->date('week_start');
                $table->unsignedSmallInteger('hours_hundredths')->nullable();
                $table->boolean('shared')->default(false);
                $table->unsignedInteger('revision')->default(1);
                $table->unsignedBigInteger('updated_by');
                $table->dateTime('created_at', 3);
                $table->dateTime('updated_at', 3)->nullable();
                $table->unique(['employee_id', 'week_start'], 'pa_employee_week_unique');
                $table->foreign('employee_id')->references('id')->on('employee_profiles')->restrictOnDelete();
                $table->foreign('updated_by')->references('id')->on('users')->restrictOnDelete();
            });
        }
        if (! Schema::hasTable('project_task_efforts')) {
            Schema::create('project_task_efforts', function (Blueprint $table): void {
                $table->engine = 'InnoDB';
                $table->char('id', 26)->primary();
                $table->char('task_id', 26);
                $table->char('employee_id', 26);
                $table->date('week_start');
                $table->unsignedSmallInteger('hours_hundredths')->nullable();
                $table->unsignedInteger('revision')->default(1);
                $table->unsignedBigInteger('updated_by');
                $table->dateTime('created_at', 3);
                $table->dateTime('updated_at', 3)->nullable();
                $table->unique(['task_id', 'employee_id', 'week_start'], 'pte_task_employee_week_unique');
                $table->index(['employee_id', 'week_start'], 'pte_employee_week_idx');
                $table->foreign('task_id')->references('id')->on('project_tasks')->cascadeOnDelete();
                $table->foreign('employee_id')->references('id')->on('employee_profiles')->restrictOnDelete();
                $table->foreign('updated_by')->references('id')->on('users')->restrictOnDelete();
            });
        }
    }

    public function down(): void
    {
        foreach (['project_task_efforts', 'project_availability'] as $table) {
            if (Schema::hasTable($table) && DB::table($table)->exists()) {
                throw new RuntimeException('Keep capacity planning while its records exist. Back up and explicitly clear them before schema rollback.');
            }
        }
        foreach (['project_task_efforts', 'project_availability'] as $table) {
            if (Schema::hasTable($table)) {
                Schema::drop($table);
            }
        }
    }
};
