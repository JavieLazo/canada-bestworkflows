<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Keep the exact previous values of future employment edits for change reports.
 *
 * Existing history stays untouched: the old snapshot may support a comparison,
 * but an absent previous value must never be invented by a data backfill.
 *
 * RETRY-SAFE. The nullable JSON column and recorded-time index each have their
 * own guard, so a stopped cron resumes either step. The index supports history
 * across employees ordered by recorded time with an id tie-breaker.
 *
 * ROLLBACK LOSES the captured previous values only. Rows and after-values stay.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('employee_employment_histories')) {
            return;
        }

        if (! Schema::hasColumn('employee_employment_histories', 'previous_values')) {
            Schema::table('employee_employment_histories', function (Blueprint $t): void {
                $t->json('previous_values')->nullable();
            });
        }

        if (! Schema::hasIndex('employee_employment_histories', 'eehist_created_id_idx')) {
            Schema::table('employee_employment_histories', function (Blueprint $t): void {
                $t->index(['created_at', 'id'], 'eehist_created_id_idx');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('employee_employment_histories')) {
            return;
        }

        if (Schema::hasIndex('employee_employment_histories', 'eehist_created_id_idx')) {
            Schema::table('employee_employment_histories', function (Blueprint $t): void {
                $t->dropIndex('eehist_created_id_idx');
            });
        }

        if (Schema::hasColumn('employee_employment_histories', 'previous_values')) {
            Schema::table('employee_employment_histories', function (Blueprint $t): void {
                $t->dropColumn('previous_values');
            });
        }
    }
};
