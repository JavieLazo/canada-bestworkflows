<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Keeps HR's manual credit movements separately from the annual aggregate.
 * There is deliberately no leave_credits foreign key: clearing an allocation
 * keeps its ledger. Employee deletion cascades as it does for leave credits.
 * Attribution has no user FK; Timekeeping's account retention preserves it.
 *
 * RETRY-SAFE — table, indexes, employee FK, each CHECK and the locks sentinel
 * have independent guards. Indexes precede the FK. The sentinel serializes
 * both allocation writers, including their first insert into an empty year.
 * BASELINE preserves existing decimals exactly; half-day input validation
 * belongs to the commands, so old imported allocations can be reconciled.
 *
 * ROLLBACK LOSES movement history and its sentinel, never annual allocations
 * or leave requests. The separate backfill can run after this create.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('leave_credit_entries')) {
            Schema::create('leave_credit_entries', function (Blueprint $t): void {
                $t->engine = 'InnoDB';
                $t->char('id', 26)->primary();
                $t->char('employee_id', 26);
                $t->unsignedSmallInteger('year');
                $t->string('request_type', 60);
                $t->string('kind', 20);
                $t->decimal('days', 6, 2);
                $t->string('note', 500);
                $t->unsignedSmallInteger('source_year')->nullable();
                $t->unsignedBigInteger('recorded_by')->nullable();
                $t->dateTime('created_at', 3);
                $t->dateTime('updated_at', 3)->nullable();
            });
        }

        if (! Schema::hasIndex('leave_credit_entries', 'lce_employee_year_type_idx')) {
            Schema::table('leave_credit_entries', function (Blueprint $t): void {
                $t->index(['employee_id', 'year', 'request_type', 'created_at'], 'lce_employee_year_type_idx');
            });
        }
        if (! Schema::hasIndex('leave_credit_entries', 'lce_recorded_by_idx')) {
            Schema::table('leave_credit_entries', function (Blueprint $t): void {
                $t->index('recorded_by', 'lce_recorded_by_idx');
            });
        }
        if (! Schema::hasForeignKey('leave_credit_entries', ['employee_id'])) {
            Schema::table('leave_credit_entries', function (Blueprint $t): void {
                $t->foreign('employee_id', 'lce_employee_fk')->references('id')->on('employee_profiles')->cascadeOnDelete();
            });
        }
        if ($this->isMySql()) {
            DB::statement('ALTER TABLE `leave_credit_entries` ROW_FORMAT=DYNAMIC');
            foreach ([
                'lce_year_chk' => '`year` BETWEEN 2000 AND 2100',
                'lce_kind_chk' => "`kind` IN ('BASELINE','OPENING','ACCRUAL','CARRY_FORWARD','ADJUSTMENT')",
                'lce_days_chk' => "`days` BETWEEN -9999.99 AND 9999.99 AND ((`kind` IN ('BASELINE','OPENING') AND `days` >= 0) OR (`kind` IN ('ACCRUAL','CARRY_FORWARD') AND `days` > 0) OR (`kind` = 'ADJUSTMENT' AND `days` <> 0))",
                'lce_source_year_chk' => "(`kind` = 'CARRY_FORWARD' AND `source_year` IS NOT NULL AND `source_year` = `year` - 1) OR (`kind` <> 'CARRY_FORWARD' AND `source_year` IS NULL)",
            ] as $name => $expression) {
                if (! $this->hasCheck($name)) {
                    DB::statement("ALTER TABLE `leave_credit_entries` ADD CONSTRAINT `{$name}` CHECK ({$expression})");
                }
            }
        }
        if (! DB::table('locks')->where('name', 'copems:leave-credits')->exists()) {
            DB::table('locks')->insert(['name' => 'copems:leave-credits', 'created_at' => now()->utc()->format('Y-m-d H:i:s.v')]);
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('leave_credit_entries')) {
            Schema::drop('leave_credit_entries');
        }
        DB::table('locks')->where('name', 'copems:leave-credits')->delete();
    }

    private function hasCheck(string $name): bool
    {
        $row = DB::selectOne(
            "SELECT COUNT(*) AS n FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ? AND CONSTRAINT_TYPE = 'CHECK'",
            ['leave_credit_entries', $name],
        );

        return (int) ($row->n ?? 0) > 0;
    }

    private function isMySql(): bool
    {
        return in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true);
    }
};
