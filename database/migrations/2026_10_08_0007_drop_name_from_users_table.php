<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Employee records own personal names; accounts retain only their employee link.
 * Historical audit attribution is frozen evidence and is never rewritten.
 *
 * RETRY-SAFE: each direction checks the table and column before its single DDL
 * statement. No copy into employee records is needed or permitted.
 * Rollback restores an empty compatibility column; discarded account names
 * cannot be recovered and the application continues to derive employee names.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('users') && Schema::hasColumn('users', 'name')) {
            Schema::table('users', static fn (Blueprint $table) => $table->dropColumn('name'));
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('users') && ! Schema::hasColumn('users', 'name')) {
            Schema::table('users', static fn (Blueprint $table) => $table->string('name', 255)->default(''));
        }
    }
};
