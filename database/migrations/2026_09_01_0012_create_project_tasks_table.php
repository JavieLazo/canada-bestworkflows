<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Port of `model ProjectTask` (prisma/schema.prisma:395-432).
 *
 * The one table with TWO foreign keys to the account table (assigned_by and
 * reviewed_by, the source's "TaskAssignedBy" / "TaskReviewedBy" relations).
 * They need distinct constraint names, which Laravel's convention supplies.
 *
 * `assignee_id` points at employee_profiles, not at an account: the single
 * assignee is an employee.
 *
 * `remarks` and `review_remarks` hold the LATEST message only; the append-only
 * task_comments thread keeps the history. That duplication is in the source and
 * is preserved.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_tasks', function (Blueprint $t): void {
            $t->engine = 'InnoDB';

            $t->char('id', 26)->primary();
            $t->string('code', 40)->unique();
            $t->char('project_id', 26);
            $t->string('title', 300);

            // 8,000-char cap in TaskInput.
            $t->text('description')->nullable();

            $t->char('assignee_id', 26)->nullable();

            // PENDING, IN_PROGRESS, SUBMITTED, RETURNED, COMPLETED, CANCELLED —
            // free text, an open set the UI extends.
            $t->string('status', 40)->default('PENDING');

            // LOW, NORMAL, HIGH, URGENT.
            $t->string('priority', 20)->default('NORMAL');

            $t->date('due_date')->nullable();

            $t->unsignedBigInteger('assigned_by')->nullable();
            $t->dateTime('assigned_at', 3);
            $t->dateTime('submitted_at', 3)->nullable();
            $t->dateTime('completed_at', 3)->nullable();
            $t->unsignedBigInteger('reviewed_by')->nullable();

            // What the assignee wrote when submitting — latest only.
            $t->text('remarks')->nullable();

            // What the reviewer wrote when accepting or returning it — latest
            // only.
            $t->text('review_remarks')->nullable();

            $t->dateTime('created_at', 3);
            $t->dateTime('updated_at', 3)->nullable();

            $t->index('project_id', 'tasks_project_idx');
            $t->index(['assignee_id', 'status'], 'tasks_assignee_status_idx');
            $t->index('due_date', 'tasks_due_idx');

            $t->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();
            $t->foreign('assignee_id')->references('id')->on('employee_profiles')->nullOnDelete();
            $t->foreign('assigned_by')->references('id')->on('users')->nullOnDelete();
            $t->foreign('reviewed_by')->references('id')->on('users')->nullOnDelete();
        });

        $this->dynamicRowFormat('project_tasks');
    }

    public function down(): void
    {
        Schema::dropIfExists('project_tasks');
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
