<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * NEW COLUMN — `payroll_deductions.calculation`: how a Payroll Deductions row
 * is priced on a regular payslip (2026-09-30, the owner's decision that every
 * row of the list is a column of the Basic Payroll grid, and that SSS,
 * PhilHealth, Pag-IBIG and withholding come from the list rather than from
 * code). SSS | PHILHEALTH | PAGIBIG | WITHHOLDING_TAX are the statutory tables
 * the engine computes, LATE_UNDERTIME the two DTR-priced lines, and NULL an
 * amount typed per employee (`App\Modules\Payroll\Domain\DeductionCalculation`).
 *
 * `varchar(20) NULL`, appended without `->after()` so MariaDB 11.4 takes
 * ALGORITHM=INSTANT. `pded_calculation_chk` pins the literal list on
 * MySQL/MariaDB (sqlite, the test suite, creates none; the catalog validates
 * against the constants class). `pded_calculation_unq` keeps each calculation
 * on ONE row — NULLs stay free, so any number of typed rows coexist.
 *
 * DATA: the five rows the seed minted (`ded-1`…`ded-5`, or a row named
 * exactly like the seeded one when the id is gone) are tagged, each only
 * while that calculation is still unassigned and the row is not already a
 * typed line on some draft (a tag there would charge it twice). A
 * calculation that still has no row afterwards gets its seeded row
 * INSERTED (next code, seeded name and description): since a computed
 * deduction whose row is missing is a deduction SWITCHED OFF (the owner's
 * decision, 2026-09-30), this is what keeps SSS, PhilHealth, Pag-IBIG,
 * withholding and late/undertime deducted on every installation exactly as
 * before the release — a migration must never change payroll money on its
 * own. The one case left off is a same-named row that a draft already types
 * (the name unique forbids the insert); the release note says how to check.
 * The code used to skip those five ids by a literal list; this makes the
 * link data.
 *
 * RETRY-SAFE, STEP BY STEP: the column, the tagging, the CHECK and the unique
 * index each stand behind their own guard; the tagging and the inserts are
 * idempotent (they touch only NULL rows and only unassigned calculations),
 * so a run killed anywhere resumes cleanly. down() drops the index and the
 * CHECK before the column, each only while it exists; inserted rows stay.
 *
 * ROLLBACK LOSES which rows are automatic: the grid would then offer the
 * statutory rows as typed columns beside the computed figures.
 */
return new class extends Migration
{
    /** Seeded id => calculation, the seed's exact name (the fallback match and the insert) and description. */
    private const array SEEDED = [
        'ded-1' => ['SSS', 'SSS', 'Social Security System contribution'],
        'ded-2' => ['PHILHEALTH', 'PhilHealth', 'National health insurance premium'],
        'ded-3' => ['PAGIBIG', 'Pag-IBIG', 'Home Development Mutual Fund'],
        'ded-4' => ['WITHHOLDING_TAX', 'Withholding Tax', 'BIR income tax withheld'],
        'ded-5' => ['LATE_UNDERTIME', 'Late / Undertime', null],
    ];

    public function up(): void
    {
        if (! Schema::hasColumn('payroll_deductions', 'calculation')) {
            Schema::table('payroll_deductions', function (Blueprint $t): void {
                // SSS | PHILHEALTH | PAGIBIG | WITHHOLDING_TAX | LATE_UNDERTIME | NULL — varchar plus the CHECK below.
                $t->string('calculation', 20)->nullable();
            });
        }

        $this->tagSeededRows();

        if ($this->isMySql() && ! $this->hasCheck('payroll_deductions', 'pded_calculation_chk')) {
            DB::statement("ALTER TABLE `payroll_deductions` ADD CONSTRAINT `pded_calculation_chk` CHECK (`calculation` IS NULL OR `calculation` IN ('SSS','PHILHEALTH','PAGIBIG','WITHHOLDING_TAX','LATE_UNDERTIME'))");
        }

        if (! Schema::hasIndex('payroll_deductions', 'pded_calculation_unq')) {
            Schema::table('payroll_deductions', function (Blueprint $t): void {
                $t->unique('calculation', 'pded_calculation_unq');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasIndex('payroll_deductions', 'pded_calculation_unq')) {
            Schema::table('payroll_deductions', function (Blueprint $t): void {
                $t->dropUnique('pded_calculation_unq');
            });
        }

        if ($this->isMySql() && $this->hasCheck('payroll_deductions', 'pded_calculation_chk')) {
            DB::statement('ALTER TABLE `payroll_deductions` DROP CONSTRAINT `pded_calculation_chk`');
        }

        if (Schema::hasColumn('payroll_deductions', 'calculation')) {
            Schema::table('payroll_deductions', function (Blueprint $t): void {
                $t->dropColumn('calculation');
            });
        }
    }

    /**
     * Tag the seed's five rows, or put them back. Each calculation goes to
     * the row with the seeded id, else to the one row named exactly as the
     * seed named it — only while nothing carries that calculation yet and
     * the row is not a typed line on a draft payroll — and, failing both, to
     * a row inserted here under the seeded name, so the deduction stays on.
     */
    private function tagSeededRows(): void
    {
        foreach (self::SEEDED as $id => [$calculation, $name, $description]) {
            $taken = DB::table('payroll_deductions')->where('calculation', $calculation)->exists();
            if ($taken) {
                continue;
            }

            $row = DB::table('payroll_deductions')->where('id', $id)->whereNull('calculation')->first(['id']);
            if ($row === null) {
                $named = DB::table('payroll_deductions')->where('name', $name)->whereNull('calculation')->get(['id']);
                $row = $named->count() === 1 ? $named->first() : null;
            }

            if ($row !== null) {
                $onDraft = Schema::hasTable('payroll_run_member_deductions')
                    && DB::table('payroll_run_member_deductions')->where('deduction_id', $row->id)->exists();
                if ($onDraft) {
                    continue; // typed on a draft: a tag would charge it twice; the name is taken, so no insert either
                }

                DB::table('payroll_deductions')->where('id', $row->id)->whereNull('calculation')->update(['calculation' => $calculation]);

                continue;
            }

            if (DB::table('payroll_deductions')->where('name', $name)->exists()) {
                continue; // a same-named typed row a draft charges (see the docblock)
            }

            DB::table('payroll_deductions')->insert([
                'id' => DB::table('payroll_deductions')->where('id', $id)->exists() ? (string) Str::ulid() : $id,
                'code' => $this->nextCode(),
                'name' => $name,
                'description' => $description,
                'calculation' => $calculation,
                'created_at' => now('UTC')->format('Y-m-d H:i:s.v'),
                'updated_at' => null,
            ]);
        }
    }

    /** The next bare-number code, as the list mints them (the highest number plus one). */
    private function nextCode(): string
    {
        $highest = 0;
        foreach (DB::table('payroll_deductions')->pluck('code') as $code) {
            if (is_numeric($code) && (int) $code > $highest) {
                $highest = (int) $code;
            }
        }

        return (string) ($highest + 1);
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
