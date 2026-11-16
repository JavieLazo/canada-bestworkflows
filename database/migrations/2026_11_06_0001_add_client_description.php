<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Rollback refuses retained descriptions instead of silently losing client briefs.
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('project_clients') && ! Schema::hasColumn('project_clients', 'description')) {
            Schema::table('project_clients', static fn (Blueprint $table) => $table->text('description')->nullable());
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('project_clients') && Schema::hasColumn('project_clients', 'description')) {
            if (DB::table('project_clients')->whereNotNull('description')->exists()) {
                throw new RuntimeException('Client descriptions are retained. Keep the column while they exist.');
            }
            Schema::table('project_clients', static fn (Blueprint $table) => $table->dropColumn('description'));
        }
    }
};
