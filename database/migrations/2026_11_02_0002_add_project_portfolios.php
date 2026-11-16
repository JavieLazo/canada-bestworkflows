<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Rollback refuses retained portfolios or associations so it cannot discard people's organization and access.
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('project_portfolios')) {
            Schema::create('project_portfolios', function (Blueprint $table): void {
                $table->engine = 'InnoDB';
                $table->char('id', 26)->primary();
                $table->unsignedBigInteger('owner_id');
                $table->string('name', 200);
                $table->text('description')->nullable();
                $table->dateTime('archived_at', 3)->nullable();
                $table->dateTime('created_at', 3);
                $table->dateTime('updated_at', 3)->nullable();
                $table->unique(['owner_id', 'name'], 'pp_owner_name_unique');
                $table->foreign('owner_id')->references('id')->on('users')->restrictOnDelete();
            });
        }
        if (! Schema::hasTable('project_portfolio_members')) {
            Schema::create('project_portfolio_members', function (Blueprint $table): void {
                $table->engine = 'InnoDB';
                $table->char('id', 26)->primary();
                $table->char('portfolio_id', 26);
                $table->unsignedBigInteger('user_id');
                $table->dateTime('created_at', 3);
                $table->unique(['portfolio_id', 'user_id'], 'ppm_portfolio_user_unique');
                $table->index(['user_id', 'portfolio_id'], 'ppm_user_portfolio_idx');
                $table->foreign('portfolio_id')->references('id')->on('project_portfolios')->cascadeOnDelete();
                $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            });
        }
        if (! Schema::hasColumn('project_clients', 'portfolio_id')) {
            Schema::table('project_clients', function (Blueprint $table): void {
                $table->char('portfolio_id', 26)->nullable();
                $table->foreign('portfolio_id')->references('id')->on('project_portfolios')->restrictOnDelete();
            });
        }
    }

    public function down(): void
    {
        if ((Schema::hasTable('project_portfolios') && DB::table('project_portfolios')->exists())
            || (Schema::hasTable('project_portfolio_members') && DB::table('project_portfolio_members')->exists())
            || (Schema::hasColumn('project_clients', 'portfolio_id') && DB::table('project_clients')->whereNotNull('portfolio_id')->exists())) {
            throw new RuntimeException('Keep portfolios while their data exists. Back up and explicitly clear them before a schema rollback.');
        }
        if (Schema::hasColumn('project_clients', 'portfolio_id')) {
            Schema::table('project_clients', function (Blueprint $table): void {
                $table->dropForeign(['portfolio_id']);
                $table->dropColumn('portfolio_id');
            });
        }
        if (Schema::hasTable('project_portfolio_members')) {
            Schema::drop('project_portfolio_members');
        }
        if (Schema::hasTable('project_portfolios')) {
            Schema::drop('project_portfolios');
        }
    }
};
