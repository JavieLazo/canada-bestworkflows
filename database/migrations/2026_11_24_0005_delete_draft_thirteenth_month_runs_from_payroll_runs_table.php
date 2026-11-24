<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * DATA ONLY: every DRAFT payroll run of kind THIRTEENTH_MONTH is DELETED.
 *
 * 13th Month Payroll is no longer a feature: no screen opens such a draft,
 * and nothing saves or finalizes one. A draft posted nothing — no payslip,
 * no evidence, no notice to anyone — so it is only a row nobody can reach.
 * Its members and their typed figures go with it through the `run_id`
 * cascade of `payroll_run_members`.
 *
 * Untouched on purpose: every FINALIZED 13th month run, its payslips and its
 * evidence (posted pay is history, still opened read-only and printed as a
 * pay statement), every REGULAR run, and the `kind` column and its CHECK,
 * which the stored rows still need. A draft that somehow holds a payslip or
 * finalization evidence is left alone as well: posted pay and evidence are
 * never deleted by a migration, and the evidence key (`pfs_run_fk`,
 * RESTRICT) would refuse the whole statement.
 *
 * RETRY-SAFE: one DELETE behind its table and column guards; a second run
 * finds no such draft and deletes nothing. Its subqueries read `payslips`
 * and `payroll_finalization_snapshots`, never the table being deleted from,
 * so MySQL/MariaDB accept it.
 *
 * ROLLBACK does not bring the drafts back: down() is a no-op. What is lost
 * is each draft's name, its member list and the figures typed on it.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('payroll_runs') || ! Schema::hasColumn('payroll_runs', 'kind') || ! Schema::hasColumn('payroll_runs', 'status')) {
            return;
        }

        DB::table('payroll_runs')
            ->where('kind', 'THIRTEENTH_MONTH')
            ->where('status', 'DRAFT')
            ->when(Schema::hasTable('payslips'), static fn ($runs) => $runs->whereNotIn('id', DB::table('payslips')->select('run_id')->whereNotNull('run_id')))
            ->when(Schema::hasTable('payroll_finalization_snapshots'), static fn ($runs) => $runs->whereNotIn('id', DB::table('payroll_finalization_snapshots')->select('run_id')->whereNotNull('run_id')))
            ->delete();
    }

    public function down(): void
    {
        // Nothing to restore: the drafts are gone.
    }
};
