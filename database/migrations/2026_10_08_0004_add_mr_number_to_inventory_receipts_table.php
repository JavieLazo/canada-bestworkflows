<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Separates the office's MR number from the generated record code without changing issued paper.
 * Each DDL step is independently guarded; only missing numbers are copied before enforcing
 * uniqueness and NOT NULL. Rollback loses entered MR numbers but keeps record codes and evidence.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('inventory_receipts')) {
            return;
        }
        if (! Schema::hasColumn('inventory_receipts', 'mr_number')) {
            Schema::table('inventory_receipts', fn (Blueprint $table) => $table->string('mr_number', 80)->nullable());
        }
        DB::table('inventory_receipts')->whereNull('mr_number')->update(['mr_number' => DB::raw('code')]);
        if (! Schema::hasIndex('inventory_receipts', 'invr_mr_number_unq')) {
            Schema::table('inventory_receipts', fn (Blueprint $table) => $table->unique('mr_number', 'invr_mr_number_unq'));
        }
        $column = collect(Schema::getColumns('inventory_receipts'))->firstWhere('name', 'mr_number');
        if ($column !== null && $column['nullable']) {
            Schema::table('inventory_receipts', fn (Blueprint $table) => $table->string('mr_number', 80)->nullable(false)->change());
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('inventory_receipts')) {
            return;
        }
        if (Schema::hasIndex('inventory_receipts', 'invr_mr_number_unq')) {
            Schema::table('inventory_receipts', fn (Blueprint $table) => $table->dropUnique('invr_mr_number_unq'));
        }
        if (Schema::hasColumn('inventory_receipts', 'mr_number')) {
            Schema::table('inventory_receipts', fn (Blueprint $table) => $table->dropColumn('mr_number'));
        }
    }
};
