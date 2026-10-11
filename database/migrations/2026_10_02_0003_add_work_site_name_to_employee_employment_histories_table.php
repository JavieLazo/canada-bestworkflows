<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Preserve the worksite name after a former site is renamed or deleted.
 *
 * The nullable snapshot matches work_sites.name's 160-character width. Old
 * history can only copy the site's CURRENT available name: a deleted site's
 * name or a name changed before this migration cannot be reconstructed. A
 * missing source stays null for the history screen's explicit unknown-site
 * fallback. New history copies the name when the employee change is recorded.
 *
 * RETRY-SAFE. Adding the column is guarded independently of the backfill. Read
 * the complete still-missing set before updating it, then fill only null names
 * so interrupted runs resume and later retries never rewrite a saved snapshot.
 *
 * ROLLBACK LOSES the copied names only. History rows and their worksite ids stay.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('employee_employment_histories')) {
            return;
        }

        if (! Schema::hasColumn('employee_employment_histories', 'work_site_name')) {
            Schema::table('employee_employment_histories', function (Blueprint $t): void {
                $t->string('work_site_name', 160)->nullable();
            });
        }

        if (! Schema::hasTable('work_sites')) {
            return;
        }

        $rows = DB::table('employee_employment_histories as history')
            ->join('work_sites', 'work_sites.id', '=', 'history.work_site_id')
            ->whereNull('history.work_site_name')
            ->orderBy('history.id')
            ->get(['history.id', 'work_sites.name']);

        foreach ($rows->chunk(100) as $chunk) {
            foreach ($chunk as $row) {
                DB::table('employee_employment_histories')
                    ->where('id', $row->id)
                    ->whereNull('work_site_name')
                    ->update(['work_site_name' => $row->name]);
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('employee_employment_histories')
            && Schema::hasColumn('employee_employment_histories', 'work_site_name')) {
            Schema::table('employee_employment_histories', function (Blueprint $t): void {
                $t->dropColumn('work_site_name');
            });
        }
    }
};
