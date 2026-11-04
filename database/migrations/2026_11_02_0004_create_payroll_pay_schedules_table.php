<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * NEW TABLE — it has no counterpart in prisma/schema.prisma.
 *
 * PAY SCHEDULES: how a month is cut into salary periods and when each is
 * paid (`params`, the figures `App\Modules\Payroll\Domain\Schedules\
 * PaySchedule` reads: the periods and their pay days, the weekend and
 * holiday rule, the deadlines before the pay date, the contribution-month
 * rule), one row per named schedule, one of them the company's default
 * (`is_default`). And the dates a regular payroll takes from its schedule,
 * as NEW NULLABLE COLUMNS on `payroll_runs`: `schedule_id` (SET NULL when
 * the schedule goes, the dates staying), `pay_date`, `contribution_month`
 * (`YYYY-MM`), and the "released" mark — `released_at`, `released_by`
 * (users, SET NULL) and `released_by_name` — which records that the pay
 * went out and locks nothing. NULL on every run before this migration and
 * on a run made without a schedule: read exactly as before.
 *
 * DATA: one default schedule is INSERTED, "Semi-monthly, paid on the last
 * day" — the 1st–15th and the 16th–end paid on each period's last day, no
 * move, no deadlines, the period's own contribution month — which is what
 * every payroll did before this table; it is inserted only while the table
 * is empty, and moves no money.
 *
 * RETRY-SAFE, STEP BY STEP: the create, each index, each column, each
 * foreign key, each CHECK and the seeded row each stand behind their own
 * guard. The CHECKs are MariaDB/MySQL only. Appended without ->after(), so
 * MariaDB 11.4 takes ALGORITHM=INSTANT.
 *
 * ROLLBACK LOSES every schedule and every run's pay date, contribution
 * month and released mark; the runs and their payslips stay.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('payroll_pay_schedules')) {
            Schema::create('payroll_pay_schedules', static function (Blueprint $t): void {
                $t->engine = 'InnoDB';
                $t->char('id', 26)->primary();
                $t->string('name', 120);
                $t->json('params');
                $t->boolean('is_default')->default(false);
                $t->unsignedBigInteger('created_by')->nullable();
                $t->string('created_by_name', 200)->nullable();
                $t->dateTime('created_at', 3);
                $t->dateTime('updated_at', 3)->nullable();
            });
        }
        if (! Schema::hasIndex('payroll_pay_schedules', 'pps_name_unq')) {
            Schema::table('payroll_pay_schedules', static fn (Blueprint $t) => $t->unique('name', 'pps_name_unq'));
        }
        if (! Schema::hasForeignKey('payroll_pay_schedules', ['created_by'])) {
            Schema::table('payroll_pay_schedules', static fn (Blueprint $t) => $t->foreign('created_by', 'pps_created_by_fk')->references('id')->on('users')->nullOnDelete());
        }

        if (Schema::hasTable('payroll_runs')) {
            if (! Schema::hasColumn('payroll_runs', 'schedule_id')) {
                Schema::table('payroll_runs', static fn (Blueprint $t) => $t->char('schedule_id', 26)->nullable());
            }
            if (! Schema::hasColumn('payroll_runs', 'pay_date')) {
                Schema::table('payroll_runs', static fn (Blueprint $t) => $t->date('pay_date')->nullable());
            }
            if (! Schema::hasColumn('payroll_runs', 'contribution_month')) {
                Schema::table('payroll_runs', static fn (Blueprint $t) => $t->char('contribution_month', 7)->nullable());
            }
            if (! Schema::hasColumn('payroll_runs', 'released_at')) {
                Schema::table('payroll_runs', static fn (Blueprint $t) => $t->dateTime('released_at', 3)->nullable());
            }
            if (! Schema::hasColumn('payroll_runs', 'released_by')) {
                Schema::table('payroll_runs', static fn (Blueprint $t) => $t->unsignedBigInteger('released_by')->nullable());
            }
            if (! Schema::hasColumn('payroll_runs', 'released_by_name')) {
                Schema::table('payroll_runs', static fn (Blueprint $t) => $t->string('released_by_name', 200)->nullable());
            }
            if (! Schema::hasIndex('payroll_runs', 'runs_schedule_idx')) {
                Schema::table('payroll_runs', static fn (Blueprint $t) => $t->index('schedule_id', 'runs_schedule_idx'));
            }
            if (! Schema::hasForeignKey('payroll_runs', ['schedule_id'])) {
                Schema::table('payroll_runs', static fn (Blueprint $t) => $t->foreign('schedule_id', 'runs_schedule_fk')->references('id')->on('payroll_pay_schedules')->nullOnDelete());
            }
            if (! Schema::hasForeignKey('payroll_runs', ['released_by'])) {
                Schema::table('payroll_runs', static fn (Blueprint $t) => $t->foreign('released_by', 'runs_released_by_fk')->references('id')->on('users')->nullOnDelete());
            }
        }

        if ($this->isMySql()) {
            DB::statement('ALTER TABLE `payroll_pay_schedules` ROW_FORMAT=DYNAMIC');
            if (! $this->hasCheck('payroll_runs', 'runs_released_chk')) {
                DB::statement("ALTER TABLE `payroll_runs` ADD CONSTRAINT `runs_released_chk` CHECK (`released_at` IS NULL OR `status` = 'FINALIZED')");
            }
            if (! $this->hasCheck('payroll_runs', 'runs_contribution_month_chk')) {
                DB::statement("ALTER TABLE `payroll_runs` ADD CONSTRAINT `runs_contribution_month_chk` CHECK (`contribution_month` IS NULL OR `contribution_month` REGEXP '^[0-9]{4}-(0[1-9]|1[0-2])$')");
            }
        }

        $this->seedDefault();
    }

    public function down(): void
    {
        if (Schema::hasTable('payroll_runs')) {
            if ($this->isMySql()) {
                foreach (['runs_contribution_month_chk', 'runs_released_chk'] as $check) {
                    if ($this->hasCheck('payroll_runs', $check)) {
                        DB::statement("ALTER TABLE `payroll_runs` DROP CONSTRAINT `{$check}`");
                    }
                }
            }
            if (Schema::hasForeignKey('payroll_runs', ['released_by'])) {
                Schema::table('payroll_runs', static fn (Blueprint $t) => $t->dropForeign(['released_by']));
            }
            if (Schema::hasForeignKey('payroll_runs', ['schedule_id'])) {
                Schema::table('payroll_runs', static fn (Blueprint $t) => $t->dropForeign(['schedule_id']));
            }
            if (Schema::hasIndex('payroll_runs', 'runs_schedule_idx')) {
                Schema::table('payroll_runs', static fn (Blueprint $t) => $t->dropIndex('runs_schedule_idx'));
            }
            foreach (['released_by_name', 'released_by', 'released_at', 'contribution_month', 'pay_date', 'schedule_id'] as $column) {
                if (Schema::hasColumn('payroll_runs', $column)) {
                    Schema::table('payroll_runs', static fn (Blueprint $t) => $t->dropColumn($column));
                }
            }
        }
        if (Schema::hasTable('payroll_pay_schedules')) {
            Schema::drop('payroll_pay_schedules');
        }
    }

    /** The company's first schedule: what every payroll did before schedules existed. */
    private function seedDefault(): void
    {
        if (DB::table('payroll_pay_schedules')->exists()) {
            return;
        }
        DB::table('payroll_pay_schedules')->insert([
            'id' => (string) Str::ulid(),
            'name' => 'Semi-monthly, paid on the last day',
            'params' => json_encode([
                'periods' => [
                    ['start' => 1, 'end' => 15, 'pay_day' => ['kind' => 'PERIOD_END', 'day' => null, 'next_month' => false]],
                    ['start' => 16, 'end' => null, 'pay_day' => ['kind' => 'PERIOD_END', 'day' => null, 'next_month' => false]],
                ],
                'holiday_rule' => 'KEEP',
                'deadlines' => [],
                'contribution_month' => 'PERIOD',
                'attendance_lag' => 0,
            ], JSON_THROW_ON_ERROR),
            'is_default' => true,
            'created_by' => null,
            'created_by_name' => null,
            'created_at' => now('UTC')->format('Y-m-d H:i:s.v'),
            'updated_at' => null,
        ]);
    }

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
