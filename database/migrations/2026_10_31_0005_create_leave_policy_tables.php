<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * EACH EMPLOYEE'S LEAVE POLICY, and what it posts. Three tables:
 *
 *  - `leave_policy_presets`: a named set of rules (LeavePolicy `params`)
 *    an employee's policy is copied from on Leave Credits settings.
 *  - `employee_leave_policies`: an employee's dated versions. Each holds
 *    the params, the reason, and who PREPARED it (status DRAFT) and who
 *    APPROVED it — a different person — after which it is in force from
 *    its effective date and never changes. One version per employee and
 *    date.
 *  - `leave_earning_postings`: what the month-end job posted: one row per
 *    employee, month (`period`, YYYY-MM) and leave type, with the policy
 *    version, the month's facts as evidence (`inputs`), the credits and
 *    the ledger entry it wrote. The unique key is what makes the job
 *    safe to run twice. The year-end step uses the period `YYYY-YE`.
 *
 * RETRY-SAFE: CREATE, each index, each FK and each CHECK have their own
 * guard; indexes precede their FKs. The employee FKs cascade like the
 * credits'. down() drops the three tables: the ledger entries they wrote
 * stay, as the balances they moved do.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('leave_policy_presets')) {
            Schema::create('leave_policy_presets', static function (Blueprint $t): void {
                $t->engine = 'InnoDB';
                $t->char('id', 26)->primary();
                $t->string('name', 120);
                $t->json('params');
                $t->unsignedBigInteger('created_by')->nullable();
                $t->string('created_by_name', 350)->nullable();
                $t->dateTime('created_at', 3);
                $t->dateTime('updated_at', 3)->nullable();
            });
        }
        if (! Schema::hasIndex('leave_policy_presets', 'lpp_name_unq')) {
            Schema::table('leave_policy_presets', static fn (Blueprint $t) => $t->unique('name', 'lpp_name_unq'));
        }

        if (! Schema::hasTable('employee_leave_policies')) {
            Schema::create('employee_leave_policies', static function (Blueprint $t): void {
                $t->engine = 'InnoDB';
                $t->char('id', 26)->primary();
                $t->char('employee_id', 26);
                $t->date('effective_from');
                $t->json('params');
                $t->string('reason', 500);
                $t->string('status', 10);
                $t->unsignedBigInteger('prepared_by')->nullable();
                $t->string('prepared_by_name', 350)->nullable();
                $t->unsignedBigInteger('approved_by')->nullable();
                $t->string('approved_by_name', 350)->nullable();
                $t->dateTime('approved_at', 3)->nullable();
                $t->dateTime('created_at', 3);
                $t->dateTime('updated_at', 3)->nullable();
            });
        }
        if (! Schema::hasIndex('employee_leave_policies', 'elp_employee_from_unq')) {
            Schema::table('employee_leave_policies', static fn (Blueprint $t) => $t->unique(['employee_id', 'effective_from'], 'elp_employee_from_unq'));
        }
        if (! Schema::hasIndex('employee_leave_policies', 'elp_status_idx')) {
            Schema::table('employee_leave_policies', static fn (Blueprint $t) => $t->index(['status', 'effective_from'], 'elp_status_idx'));
        }
        if (! Schema::hasForeignKey('employee_leave_policies', ['employee_id'])) {
            Schema::table('employee_leave_policies', static fn (Blueprint $t) => $t->foreign('employee_id', 'elp_employee_fk')->references('id')->on('employee_profiles')->cascadeOnDelete());
        }

        if (! Schema::hasTable('leave_earning_postings')) {
            Schema::create('leave_earning_postings', static function (Blueprint $t): void {
                $t->engine = 'InnoDB';
                $t->char('id', 26)->primary();
                $t->char('employee_id', 26);
                $t->char('period', 7);
                $t->string('request_type', 60);
                $t->char('policy_id', 26)->nullable();
                $t->decimal('days', 10, 4);
                $t->json('inputs');
                $t->char('entry_id', 26)->nullable();
                $t->dateTime('created_at', 3);
            });
        }
        if (! Schema::hasIndex('leave_earning_postings', 'lep_employee_period_type_unq')) {
            Schema::table('leave_earning_postings', static fn (Blueprint $t) => $t->unique(['employee_id', 'period', 'request_type'], 'lep_employee_period_type_unq'));
        }
        if (! Schema::hasIndex('leave_earning_postings', 'lep_period_idx')) {
            Schema::table('leave_earning_postings', static fn (Blueprint $t) => $t->index(['period', 'created_at'], 'lep_period_idx'));
        }
        if (! Schema::hasForeignKey('leave_earning_postings', ['employee_id'])) {
            Schema::table('leave_earning_postings', static fn (Blueprint $t) => $t->foreign('employee_id', 'lep_employee_fk')->references('id')->on('employee_profiles')->cascadeOnDelete());
        }

        if ($this->isMySql()) {
            foreach (['leave_policy_presets', 'employee_leave_policies', 'leave_earning_postings'] as $table) {
                DB::statement("ALTER TABLE `{$table}` ROW_FORMAT=DYNAMIC");
            }
            foreach ([
                'employee_leave_policies' => [
                    'elp_status_chk' => "`status` IN ('DRAFT','APPROVED')",
                    'elp_approval_chk' => "(`status` = 'DRAFT' AND `approved_at` IS NULL) OR (`status` = 'APPROVED' AND `approved_at` IS NOT NULL)",
                ],
                'leave_earning_postings' => [
                    'lep_days_chk' => '`days` BETWEEN -999999.9999 AND 999999.9999',
                ],
            ] as $table => $checks) {
                foreach ($checks as $name => $expression) {
                    if (! $this->hasCheck($table, $name)) {
                        DB::statement("ALTER TABLE `{$table}` ADD CONSTRAINT `{$name}` CHECK ({$expression})");
                    }
                }
            }
        }
    }

    public function down(): void
    {
        foreach (['leave_earning_postings', 'employee_leave_policies', 'leave_policy_presets'] as $table) {
            if (Schema::hasTable($table)) {
                Schema::drop($table);
            }
        }
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
