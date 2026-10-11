<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * NEW COLUMN — `payroll_runs.kind`: which payroll a run is (2026-09-26, the
 * owner's decisions D1, D5 and D6). REGULAR is the cut-off payroll as it has
 * always been ("Basic Payroll - Monthly" in the sidebar — Monthly is only the
 * label); THIRTEENTH_MONTH is the yearly 13th month pay run ("13th Month
 * Payroll - Yearly"), which covers 1 January to 31 December and pays 1/12 of
 * the basic pay earned that year.
 *
 * DELIBERATE DIVERGENCE: the reference has one kind of run and posts
 * `thirteenth_month = 0` on every payslip (payroll.ts:531).
 *
 * `varchar(20) NOT NULL DEFAULT 'REGULAR'`, so every existing run reads
 * REGULAR — exactly what it was; no backfill. Appended without `->after()`,
 * which keeps the ADD instant on MariaDB 11.4. `runs_kind_chk` pins the two
 * literal values on MySQL/MariaDB (sqlite, the test suite, creates none; the
 * writers validate against `App\Domain\Enums\PayrollRunKind`). A run's kind
 * is set when it is created and never changes (SavePayroll).
 *
 * RETRY-SAFE, STEP BY STEP: the column and the CHECK are two ALTERs with two
 * guards, so a run killed between them resumes at the CHECK. down() drops the
 * CHECK first, then the column, each only while it exists.
 *
 * ROLLBACK LOSES which runs were 13th month runs: they re-read as REGULAR,
 * and their payslips (period 1 January to 31 December) would then count as
 * regular pay. Do not roll back after a 13th month run exists.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('payroll_runs', 'kind')) {
            Schema::table('payroll_runs', function (Blueprint $t): void {
                // REGULAR | THIRTEENTH_MONTH — varchar plus the CHECK below.
                $t->string('kind', 20)->default('REGULAR');
            });
        }

        if ($this->isMySql() && ! $this->hasCheck('payroll_runs', 'runs_kind_chk')) {
            DB::statement("ALTER TABLE `payroll_runs` ADD CONSTRAINT `runs_kind_chk` CHECK (`kind` IN ('REGULAR','THIRTEENTH_MONTH'))");
        }
    }

    public function down(): void
    {
        if ($this->isMySql() && $this->hasCheck('payroll_runs', 'runs_kind_chk')) {
            DB::statement('ALTER TABLE `payroll_runs` DROP CONSTRAINT `runs_kind_chk`');
        }

        if (Schema::hasColumn('payroll_runs', 'kind')) {
            Schema::table('payroll_runs', function (Blueprint $t): void {
                $t->dropColumn('kind');
            });
        }
    }

    /** Whether the named CHECK exists — information_schema, so MySQL/MariaDB only. */
    private function hasCheck(string $table, string $name): bool
    {
        $row = DB::selectOne(
            "SELECT COUNT(*) AS n FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ? AND CONSTRAINT_TYPE = 'CHECK'",
            [$table, $name],
        );

        return (int) ($row->n ?? 0) > 0;
    }

    private function isMySql(): bool
    {
        return in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true);
    }
};
