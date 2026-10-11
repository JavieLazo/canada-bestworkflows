<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * NEW COLUMN — `employee_profiles.daily_rate`: the record's Rate Per Day,
 * beside Rate Per Month on Employee Details. No TypeScript counterpart
 * (DELIBERATE DIVERGENCE, 2026-09-30): the reference derived every daily
 * rate from `monthly_rate`.
 *
 * WHY NULLABLE, WITH NO DEFAULT. Payroll prices a day at this rate when it
 * holds more than zero, and otherwise derives the day from Rate Per Month
 * exactly as before (PayslipCalculator, divergence 10). A blank Rate Per Day
 * is therefore a real "no value", and NULL says so: every existing employee
 * reads NULL ("—" on the record), keeps the monthly derivation, and there is
 * no backfill. `DECIMAL(12,2)` like the record's other money columns.
 *
 * RETRY-SAFE. Production migrates from a temporary cPanel cron that can die
 * between statements, and MariaDB DDL does not roll back, so the one ALTER
 * sits behind its own `hasColumn` guard and a re-run after it landed does
 * nothing. `down()` is guarded the same way. The column is appended without
 * `->after()`, which keeps the ADD instant on MariaDB 11.4 instead of
 * rebuilding the table.
 *
 * ROW SIZE. The table depends on ROW_FORMAT=DYNAMIC (2026_09_01_0004, and
 * MariaDB 11.4's default); see 2026_09_30_0001 for the measurement. One
 * DECIMAL(12,2) adds six bytes to the in-row record.
 *
 * ROLLBACK LOSES every Rate Per Day entered since; payroll then derives those
 * employees' days from Rate Per Month again, and anyone whose Rate Per Month
 * was left blank would be paid nothing until one is entered.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('employee_profiles', 'daily_rate')) {
            Schema::table('employee_profiles', function (Blueprint $t): void {
                $t->decimal('daily_rate', 12, 2)->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('employee_profiles', 'daily_rate')) {
            Schema::table('employee_profiles', function (Blueprint $t): void {
                $t->dropColumn('daily_rate');
            });
        }
    }
};
