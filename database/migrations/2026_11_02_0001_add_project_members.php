<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Rollback refuses retained memberships so removing the UI cannot erase project access decisions.
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('project_members')) {
            Schema::create('project_members', function (Blueprint $table): void {
                $table->engine = 'InnoDB';
                $table->char('id', 26)->primary();
                $table->char('project_id', 26);
                $table->unsignedBigInteger('user_id');
                $table->string('role', 20);
                $table->string('position', 80)->nullable();
                $table->dateTime('created_at', 3);
                $table->dateTime('updated_at', 3)->nullable();
                $table->unique(['project_id', 'user_id'], 'pm_project_user_unique');
                $table->index(['user_id', 'role', 'project_id'], 'pm_user_role_project_idx');
                $table->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();
                $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('project_members')) {
            if (DB::table('project_members')->exists()) {
                throw new RuntimeException('Keep project memberships while their data exists. Back up and explicitly clear them before a schema rollback.');
            }
            Schema::drop('project_members');
        }
    }
};
