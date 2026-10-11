<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * DATA ONLY — SIL does not calculate payroll and has no active formula setting.
 * Delete only its current configuration row; finalized evidence and audit history
 * keep their original bytes, including any captured SIL parameters.
 *
 * RETRY-SAFE — the table guard tolerates an absent schema, and one targeted DELETE
 * is safe to repeat after interruption. No active formula is renumbered or rewritten.
 * ROLLBACK IS IRREVERSIBLE — down() cannot recover customized SIL parameters and
 * deliberately does not recreate unused configuration with guessed defaults.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('payroll_formulas')) {
            DB::table('payroll_formulas')->where('key', 'SIL')->delete();
        }
    }

    public function down(): void
    {
        // The removed configuration cannot be reconstructed; retain all historical evidence.
    }
};
