<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * NEW COLUMN — `payroll_run_members.skipped_recurring_lines`: the recurring
 * lines (2026_10_30_0002) an officer skipped for this employee on this
 * payroll — a JSON list of their ids, NULL when none. A skipped line
 * proposes nothing on this payroll and stays as it is for the next one
 * (an employee with no pay this period, a loan paused by agreement).
 *
 * Written with the member row by SavePayroll, which re-creates every member
 * row on each save, and captured in the payroll evidence.
 *
 * RETRY-SAFE: the column has its own guard.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('payroll_run_members', 'skipped_recurring_lines')) {
            Schema::table('payroll_run_members', static fn (Blueprint $t) => $t->json('skipped_recurring_lines')->nullable());
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('payroll_run_members', 'skipped_recurring_lines')) {
            Schema::table('payroll_run_members', static fn (Blueprint $t) => $t->dropColumn('skipped_recurring_lines'));
        }
    }
};
