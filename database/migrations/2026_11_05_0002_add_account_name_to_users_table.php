<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A name for an account that has NO employee record — an outside auditor, a
 * consultant, a shared service sign-in. An account linked to an employee keeps
 * taking its name from that record, so this column is NULL for it and is
 * cleared when a link is made. It is not the dropped `users.name`: nothing is
 * copied into it, and a blank one still reads "Account #<id>".
 *
 * RETRY-SAFE: each direction checks the table and the column before its single
 * DDL statement. Rollback loses every typed account name; those accounts read
 * "Account #<id>" again.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('users') && ! Schema::hasColumn('users', 'account_name')) {
            Schema::table('users', static fn (Blueprint $table) => $table->string('account_name', 150)->nullable());
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('users') && Schema::hasColumn('users', 'account_name')) {
            Schema::table('users', static fn (Blueprint $table) => $table->dropColumn('account_name'));
        }
    }
};
