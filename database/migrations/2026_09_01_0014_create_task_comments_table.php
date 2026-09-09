<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Port of `model TaskComment` (prisma/schema.prisma:459-478).
 *
 * One message on a task's thread. Append-only, which is the whole point: a task
 * that bounces twice keeps both exchanges, where project_tasks' single
 * `remarks` / `review_remarks` columns only ever hold the latest. No updated_at
 * — the Eloquent model carries `public $timestamps = false` and writes
 * created_at itself.
 *
 * `author_name` is denormalised on purpose so a deleted account still leaves a
 * readable thread — which is why `author_id` is SET NULL rather than CASCADE.
 *
 * The (task_id, created_at) index is what orders the thread, and created_at is
 * DATETIME(3): two comments posted in the same second must not reorder.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_comments', function (Blueprint $t): void {
            $t->engine = 'InnoDB';

            $t->char('id', 26)->primary();
            $t->char('task_id', 26);
            $t->unsignedBigInteger('author_id')->nullable();

            // Cap from the account full_name cap (200).
            $t->string('author_name', 200);

            // MANAGEMENT or ASSIGNEE — which side of the task spoke.
            $t->string('side', 20)->default('MANAGEMENT');

            // 8,000-char cap in the API.
            $t->text('body');

            // Set when the comment accompanies a status change, e.g. "RETURNED".
            $t->string('event', 60)->nullable();

            $t->dateTime('created_at', 3);

            $t->index(['task_id', 'created_at'], 'comments_task_created_idx');

            $t->foreign('task_id')->references('id')->on('project_tasks')->cascadeOnDelete();
            $t->foreign('author_id')->references('id')->on('users')->nullOnDelete();
        });

        $this->dynamicRowFormat('task_comments');
    }

    public function down(): void
    {
        Schema::dropIfExists('task_comments');
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
