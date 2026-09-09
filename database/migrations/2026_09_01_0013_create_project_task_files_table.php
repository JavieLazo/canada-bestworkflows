<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Port of `model ProjectTaskFile` (prisma/schema.prisma:437-454).
 *
 * The brief handed down with a task, or the evidence sent back with a
 * submission. Like employee_documents, the source's `data String @db.Text`
 * base64 data: URL becomes a filesystem path — see that migration for the
 * row-width and 1406-truncation reasoning.
 *
 * Append-only: no updated_at, so the Eloquent model manages timestamps itself.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_task_files', function (Blueprint $t): void {
            $t->engine = 'InnoDB';

            $t->char('id', 26)->primary();
            $t->char('task_id', 26);

            // BRIEF (from management) or SUBMISSION (from the assignee).
            $t->string('kind', 20)->default('BRIEF');

            $t->string('file_name', 300);
            $t->string('mime', 120)->default('application/octet-stream');
            $t->integer('size');

            // Replaces `data` — the bytes live on the filesystem.
            $t->string('path', 255);

            $t->unsignedBigInteger('uploaded_by')->nullable();
            $t->dateTime('created_at', 3);

            $t->index('task_id', 'taskfile_task_idx');

            $t->foreign('task_id')->references('id')->on('project_tasks')->cascadeOnDelete();
            $t->foreign('uploaded_by')->references('id')->on('users')->nullOnDelete();
        });

        $this->dynamicRowFormat('project_task_files');
    }

    public function down(): void
    {
        Schema::dropIfExists('project_task_files');
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
