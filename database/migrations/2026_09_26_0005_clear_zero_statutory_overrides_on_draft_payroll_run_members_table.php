<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * DATA ONLY — clears the statutory "overrides" of 0.00 that open DRAFT runs
 * carry from the build that typed every contribution by hand.
 *
 * Until 2026-09-26 the engine left SSS, PhilHealth, Pag-IBIG and withholding
 * at zero and the officer typed them, so NULL and 0.00 in
 * `payroll_run_members.deduction_sss / _philhealth / _pagibig /
 * _withholding_tax` priced the same — and the workspace stored an emptied
 * cell as 0.00. From this release the lines are computed again and NULL
 * means "use the computed figure", while a stored 0.00 is an override that
 * EXEMPTS the employee from that line. A 0.00 written under the old build
 * carries no such intent (it is almost always an emptied cell), and keeping
 * it would silently skip the contribution on every open draft. So on DRAFT
 * runs only, each of those four columns loses its 0.00, and the computed
 * figure takes over. A genuinely exempt employee belongs on the 201 record's
 * switch (Payroll Settings), which the engine reads.
 *
 * Untouched on purpose: FINALIZED runs (their posted payslips and evidence
 * are frozen, and their member rows are history), every non-zero figure (a
 * real typed override stays one), `deduction_other` (nothing computes it, so
 * 0 and NULL still mean the same), and `deduction_absence` (ignored since
 * absence moved into basic pay).
 *
 * RETRY-SAFE, STEP BY STEP. Production migrates from a temporary cPanel cron
 * that can be killed between any two statements. Each column is one
 * idempotent UPDATE behind its own table and column guards; a run killed
 * after the first resumes at the next, and a second run finds no 0.00 left
 * on a DRAFT run and changes nothing. The UPDATE's subquery reads
 * `payroll_runs`, never the table being updated, so MySQL/MariaDB accept it.
 *
 * OPERATOR STEP (release note): it reaches only the rows in the database
 * when the cron runs it. A payroll workspace left open across the release
 * still holds the old 0.00s and would write them back on Save, so officers
 * close open payroll workspaces before the files are extracted, and nobody
 * saves or finalizes a payroll until this migration shows as run.
 *
 * ROLLBACK LOSES NOTHING IT COULD RESTORE: down() is a no-op, because the
 * previous build priced NULL and 0.00 identically — there is no distinction
 * to put back. Every affected draft's statutory lines read the computed
 * figures from then on; an officer who wants a zero types 0 again.
 */
return new class extends Migration
{
    private const COLUMNS = [
        'deduction_sss',
        'deduction_philhealth',
        'deduction_pagibig',
        'deduction_withholding_tax',
    ];

    public function up(): void
    {
        if (! Schema::hasTable('payroll_run_members') || ! Schema::hasTable('payroll_runs')) {
            return;
        }

        foreach (self::COLUMNS as $column) {
            if (! Schema::hasColumn('payroll_run_members', $column)) {
                continue;
            }

            DB::table('payroll_run_members')
                ->where($column, 0)
                ->whereIn('run_id', DB::table('payroll_runs')->select('id')->where('status', 'DRAFT'))
                ->update([$column => null]);
        }
    }

    public function down(): void
    {
        // Nothing to restore: see the docblock.
    }
};
