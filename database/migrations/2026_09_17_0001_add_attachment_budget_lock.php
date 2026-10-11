<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** RETRY-SAFE: inserts only the missing sentinel. Rollback removes no attachment data. */
return new class extends Migration
{
    public function up(): void
    {
        if (! DB::table('locks')->where('name', 'copems:attachment-budget')->exists()) {
            DB::table('locks')->insert(['name' => 'copems:attachment-budget', 'created_at' => now()->format('Y-m-d H:i:s.v')]);
        }
    }

    public function down(): void
    {
        DB::table('locks')->where('name', 'copems:attachment-budget')->delete();
    }
};
