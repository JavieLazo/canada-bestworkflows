<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Rolling back removes client names and ownership, but leaves existing project work alone.
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('project_clients')) {
            Schema::create('project_clients', function (Blueprint $table): void {
                $table->engine = 'InnoDB';
                $table->char('id', 26)->primary();
                $table->unsignedBigInteger('owner_id');
                $table->string('name', 200);
                $table->dateTime('created_at', 3);
                $table->dateTime('updated_at', 3)->nullable();
                $table->unique(['owner_id', 'name'], 'project_clients_owner_name_unique');
                $table->foreign('owner_id')->references('id')->on('users')->restrictOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('project_clients')) {
            Schema::drop('project_clients');
        }
    }
};
