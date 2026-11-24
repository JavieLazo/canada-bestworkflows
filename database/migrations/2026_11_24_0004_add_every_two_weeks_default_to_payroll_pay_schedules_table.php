<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * DATA ONLY: the company's default pay schedule becomes "Every two weeks,
 * paid the Friday after".
 *
 * One schedule is INSERTED — two-week periods running Sunday to Saturday
 * from 21 December 2025, each paid six days after it ends, a pay date on a
 * weekend or holiday moved to the working day before — and made the default
 * in place of the seeded "Semi-monthly, paid on the last day". The seeded
 * schedule stays as a row payrolls already made keep pointing at; no payroll
 * is touched, and a payroll made from now on takes its period and pay date
 * from the new default.
 *
 * ONLY THE UNTOUCHED SEED IS REPLACED AS THE DEFAULT: where someone has
 * already made another schedule the default, or renamed or changed the
 * seeded one, the new schedule is still inserted but the default is left as
 * it was chosen.
 *
 * RETRY-SAFE: the insert is skipped once a schedule of that name exists, and
 * the switch of the default is one transaction, so a run stopped between the
 * two is simply run again.
 *
 * ROLLBACK removes the two-week schedule (payrolls made from it keep their
 * dates and lose only the link) and gives the default back to the seeded
 * schedule when no other holds it.
 */
return new class extends Migration
{
    private const NAME = 'Every two weeks, paid the Friday after';

    private const SEEDED = 'Semi-monthly, paid on the last day';

    public function up(): void
    {
        if (! Schema::hasTable('payroll_pay_schedules')) {
            return;
        }

        if (! DB::table('payroll_pay_schedules')->where('name', self::NAME)->exists()) {
            DB::table('payroll_pay_schedules')->insert([
                'id' => (string) Str::ulid(),
                'name' => self::NAME,
                'params' => json_encode([
                    'periods' => [],
                    'holiday_rule' => 'BEFORE',
                    'deadlines' => [],
                    'contribution_month' => 'PAY_DATE',
                    'attendance_lag' => 0,
                    'cycle' => ['weeks' => 2, 'anchor' => '2025-12-21', 'pay_after_days' => 6],
                ], JSON_THROW_ON_ERROR),
                'is_default' => false,
                'created_by' => null,
                'created_by_name' => null,
                'created_at' => now('UTC')->format('Y-m-d H:i:s.v'),
                'updated_at' => null,
            ]);
        }

        DB::transaction(function (): void {
            $defaults = DB::table('payroll_pay_schedules')->where('is_default', true)->get(['id', 'name', 'params']);
            $untouchedSeed = $defaults->count() === 1 && $defaults[0]->name === self::SEEDED && $this->isSeededFigures((string) $defaults[0]->params);
            if (! $defaults->isEmpty() && ! $untouchedSeed) {
                return;
            }
            DB::table('payroll_pay_schedules')->where('is_default', true)->update(['is_default' => false]);
            DB::table('payroll_pay_schedules')->where('name', self::NAME)->update(['is_default' => true]);
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('payroll_pay_schedules')) {
            return;
        }

        DB::transaction(function (): void {
            DB::table('payroll_pay_schedules')->where('name', self::NAME)->delete();
            if (! DB::table('payroll_pay_schedules')->where('is_default', true)->exists()) {
                DB::table('payroll_pay_schedules')->where('name', self::SEEDED)->update(['is_default' => true]);
            }
        });
    }

    /** Whether a row still holds exactly the figures the seed wrote. */
    private function isSeededFigures(string $params): bool
    {
        $figures = json_decode($params, true);
        if (! is_array($figures)) {
            return false;
        }

        return ($figures['holiday_rule'] ?? null) === 'KEEP'
            && ($figures['deadlines'] ?? null) === []
            && ($figures['contribution_month'] ?? null) === 'PERIOD'
            && ($figures['attendance_lag'] ?? null) === 0
            && ! isset($figures['cycle'])
            && ($figures['periods'] ?? null) === [
                ['start' => 1, 'end' => 15, 'pay_day' => ['kind' => 'PERIOD_END', 'day' => null, 'next_month' => false]],
                ['start' => 16, 'end' => null, 'pay_day' => ['kind' => 'PERIOD_END', 'day' => null, 'next_month' => false]],
            ];
    }
};
