<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * NEW COLUMN — `payroll_run_members.additional_basic_pay`: on a 13th month
 * run, the basic pay an employee earned that year OUTSIDE EMS, typed by the
 * payroll officer (2026-09-26, the owner's decision D11: EMS went live in
 * September 2026, so the months before were paid elsewhere).
 *
 * The 13th month is 1/12 of the basic pay earned in the calendar year: what
 * EMS's own finalized regular payslips show (work days pay + leave pay) PLUS
 * this figure. DECIMAL(12,2) NULL, like every other per-member override:
 * NULL is "nothing typed" (the same as 0 here), and the column is never
 * read on a regular run. It is captured in the 13th month run's evidence.
 *
 * RETRY-SAFE: one guarded ALTER, appended without `->after()` so MariaDB
 * 11.4 adds it instantly; down() drops it only while it exists.
 *
 * ROLLBACK LOSES every typed figure; an open 13th month run would then pay
 * on EMS's own payslips alone.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('payroll_run_members', 'additional_basic_pay')) {
            Schema::table('payroll_run_members', function (Blueprint $t): void {
                $t->decimal('additional_basic_pay', 12, 2)->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('payroll_run_members', 'additional_basic_pay')) {
            Schema::table('payroll_run_members', function (Blueprint $t): void {
                $t->dropColumn('additional_basic_pay');
            });
        }
    }
};
