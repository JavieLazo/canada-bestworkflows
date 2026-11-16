<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Rolling back removes client memberships, but keeps the clients and existing work.
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('project_client_members')) {
            Schema::create('project_client_members', function (Blueprint $table): void {
                $table->engine = 'InnoDB';
                $table->char('id', 26)->primary();
                $table->char('client_id', 26);
                $table->unsignedBigInteger('user_id');
                $table->dateTime('created_at', 3);
                $table->unique(['client_id', 'user_id'], 'project_client_members_client_user_unique');
                $table->index(['user_id', 'client_id'], 'project_client_members_user_client_index');
                $table->foreign('client_id')->references('id')->on('project_clients')->cascadeOnDelete();
                $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('project_client_members')) {
            Schema::drop('project_client_members');
        }
    }
};
