<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Drop the two leave-credit columns the record no longer carries.
 *
 * `vacation_credits` and `sick_credits` came over from the reference as
 * "Vacation Leave Credits" and "Sick Leave Credits" on the Employee Details
 * section (employee-fields.tsx:119-120). Nothing ever computed from them:
 * leave is approved per request, never drawn down from a balance, and the
 * payroll engine reads neither column. On 2026-09-25 the fields were removed
 * from the record by decision (DELIBERATE DIVERGENCE in
 * EmployeeFields::sections()): the model's $fillable and casts, the read
 * model and the demo seeder no longer name them, so since that day the
 * columns hold only what was typed before it, and the DEFAULT 0 for everyone
 * hired after. This finishes the removal at the table.
 *
 * NO COPY STEP, ON PURPOSE. The copy-then-drop rule is for values that move
 * somewhere else. These values go nowhere: the business decided the record
 * does not carry a credit balance, and no table, screen or report takes one.
 *
 * ORDER ON MARIADB. Two plain DECIMAL(6,2) NOT NULL DEFAULT 0 columns with no
 * index, foreign key or CHECK on them (2026_09_01_0004:107-108), so each drop
 * is one ALTER with nothing to detach first. They go in two statements rather
 * than one so a retry has a per-column precondition. MariaDB 11.4 drops a
 * column of a DYNAMIC-format InnoDB table instantly (no rebuild), and the
 * integration test sends the same statement with ALGORITHM=INSTANT to prove
 * it on the real engine.
 *
 * RETRY-SAFE, STEP BY STEP. Each DROP COLUMN is guarded by its own
 * hasColumn(): production migrates from a temporary cPanel cron that can be
 * killed between the two ALTERs, MySQL/MariaDB DDL does not roll back, and a
 * re-run drops only the column that is still there. down() re-adds each
 * column only while it is absent, so a repeated rollback is as safe as a
 * repeated up(). On SQLite (the test suite) a column drop may rebuild the
 * table, which is why the migration tests seed an employee with no child
 * rows.
 *
 * ROW SIZE. The declared COMPACT-format width the create migration ledgers
 * went 7,703 → 7,784 with the suffix (2026_09_06_0001) → 7,985 with
 * employee_id_no (2026_09_19_0001). A DECIMAL(6,2) occupies 3 bytes (2 for
 * the four integer digits, 1 for the two fractional), so dropping both takes
 * it to 7,979 against the binding 8,126-byte half-page limit.
 *
 * ROLLBACK LOSES every stored credit balance. down() restores both columns
 * as DECIMAL(6,2) NOT NULL DEFAULT 0, so every row reads 0 afterwards; the
 * values typed before 2026-09-25 survive only in a backup taken before this
 * ran. Nothing in the application would read them back anyway — the fields
 * were gone from the record before the columns were.
 */
return new class extends Migration
{
    /** @var list<string> */
    private const array COLUMNS = ['vacation_credits', 'sick_credits'];

    public function up(): void
    {
        foreach (self::COLUMNS as $column) {
            if (Schema::hasColumn('employee_profiles', $column)) {
                Schema::table('employee_profiles', function (Blueprint $t) use ($column): void {
                    $t->dropColumn($column);
                });
            }
        }
    }

    public function down(): void
    {
        foreach (self::COLUMNS as $column) {
            if (! Schema::hasColumn('employee_profiles', $column)) {
                Schema::table('employee_profiles', function (Blueprint $t) use ($column): void {
                    $t->decimal($column, 6, 2)->default(0);
                });
            }
        }
    }
};
