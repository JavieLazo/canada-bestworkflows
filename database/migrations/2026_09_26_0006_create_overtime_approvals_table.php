<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * NEW TABLE — `overtime_approvals`: the Daily Time Record's decision on one
 * employee's overtime on one day.
 *
 * DELIBERATE DIVERGENCE: the reference paid computed overtime only once a
 * payroll officer ticked "acknowledge" for the whole run
 * (payroll_run_members.overtime_acknowledged, payroll.ts:558). Since
 * 2026-09-26 overtime is approved PER DAY on the DTR, by an all-data EDTR
 * holder who is not the employee, and payroll pays only the approved minutes
 * (PayslipCalculator divergence 7). The run-level switch is no longer read.
 *
 * One row per (employee_id, date), where `date` is the shift START date —
 * the day DtrCalculator files the overtime under, so an overnight shift's
 * overtime is decided on the evening it began. No row means PENDING; a row
 * is APPROVED (1..computed minutes, an approver may approve fewer than the
 * day computed) or REJECTED (0 minutes). `computed_minutes` is what the day
 * computed when it was decided, so a later punch correction that changes the
 * day shows as outdated instead of silently paying minutes nobody saw. The
 * engine still caps every day at the Formulas OT maximum.
 *
 * `reviewed_by` is the deciding account, set from Access, never from input,
 * with NO foreign key (the attendance_remarks.author_id precedent,
 * 2026_09_01_0019): an account that authorised pay is kept by
 * Timekeeping's AccountReferences instead.
 *
 * `ota_decision_chk`, `ota_minutes_chk` and `ota_computed_chk` pin the
 * literal decisions and the minute bounds on MySQL/MariaDB; sqlite (the
 * test suite) creates none, and the command validates the same bounds
 * everywhere. No ENUM.
 *
 * RETRY-SAFE, STEP BY STEP. Production migrates from a temporary cPanel cron
 * that can be killed between any two statements, and MySQL DDL does not roll
 * back. The create holds only the columns and the primary key; the unique,
 * the foreign key and each CHECK are separate ALTERs behind their own guards
 * (hasIndex, hasForeignKey, information_schema), so a retry resumes wherever
 * the last run stopped. The unique comes BEFORE the foreign key so MySQL
 * reuses it for the constraint. ROW_FORMAT=DYNAMIC restates the same format
 * on a retry. down() drops the table, with everything on it, only if it
 * exists.
 *
 * ROLLBACK LOSES every overtime decision; draft payrolls would then find
 * every overtime day undecided. Posted payslips keep the minutes they paid
 * inside their finalization evidence.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('overtime_approvals')) {
            Schema::create('overtime_approvals', function (Blueprint $t): void {
                $t->engine = 'InnoDB';

                $t->char('id', 26)->primary();
                $t->char('employee_id', 26);

                // The shift START date the overtime is filed under.
                $t->date('date');

                // APPROVED | REJECTED — varchar plus ota_decision_chk.
                $t->string('decision', 10);

                // Whole minutes: what the day computed when it was decided,
                // and what was approved (0 when rejected).
                $t->unsignedSmallInteger('computed_minutes');
                $t->unsignedSmallInteger('approved_minutes');

                $t->string('note', 500)->nullable();

                // The deciding account, from Access. No foreign key.
                $t->unsignedBigInteger('reviewed_by')->nullable();

                $t->dateTime('created_at', 3);
                $t->dateTime('updated_at', 3)->nullable();
            });
        }

        if (! Schema::hasIndex('overtime_approvals', 'ota_employee_date_unq')) {
            Schema::table('overtime_approvals', function (Blueprint $t): void {
                $t->unique(['employee_id', 'date'], 'ota_employee_date_unq');
            });
        }

        if (! Schema::hasForeignKey('overtime_approvals', ['employee_id'])) {
            Schema::table('overtime_approvals', function (Blueprint $t): void {
                $t->foreign('employee_id')->references('id')->on('employee_profiles')->cascadeOnDelete();
            });
        }

        $this->dynamicRowFormat('overtime_approvals');

        if ($this->isMySql() && ! $this->hasCheck('overtime_approvals', 'ota_decision_chk')) {
            DB::statement("ALTER TABLE `overtime_approvals` ADD CONSTRAINT `ota_decision_chk` CHECK (`decision` IN ('APPROVED','REJECTED'))");
        }

        if ($this->isMySql() && ! $this->hasCheck('overtime_approvals', 'ota_computed_chk')) {
            DB::statement('ALTER TABLE `overtime_approvals` ADD CONSTRAINT `ota_computed_chk` CHECK (`computed_minutes` BETWEEN 1 AND 1440)');
        }

        if ($this->isMySql() && ! $this->hasCheck('overtime_approvals', 'ota_minutes_chk')) {
            DB::statement("ALTER TABLE `overtime_approvals` ADD CONSTRAINT `ota_minutes_chk` CHECK ((`decision` = 'REJECTED' AND `approved_minutes` = 0) OR (`decision` = 'APPROVED' AND `approved_minutes` BETWEEN 1 AND `computed_minutes`))");
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('overtime_approvals')) {
            Schema::drop('overtime_approvals');
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

    private function dynamicRowFormat(string ...$tables): void
    {
        if (! $this->isMySql()) {
            return;
        }

        foreach ($tables as $table) {
            DB::statement("ALTER TABLE `{$table}` ROW_FORMAT=DYNAMIC");
        }
    }

    private function isMySql(): bool
    {
        return in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true);
    }
};
