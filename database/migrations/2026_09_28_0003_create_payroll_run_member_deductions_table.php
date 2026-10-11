<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Keep each custom deduction amount with its draft payroll member. A positive
 * row means that deduction was chosen; its absence means it was not charged.
 * The catalog FK RESTRICTs deletion while a draft names that label, since a
 * cascade would silently remove money from a payroll under review.
 *
 * RETRY-SAFE: CREATE owns only columns and the primary key. The unique/index
 * are guarded individually and added before their foreign keys; each FK and
 * the MariaDB/MySQL amount CHECK also has its own existence guard. A stopped
 * migration resumes from any completed statement. down() is repeatable but
 * loses the custom deductions on draft members; posted payslips survive.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('payroll_run_member_deductions')) {
            Schema::create('payroll_run_member_deductions', function (Blueprint $t): void {
                $t->engine = 'InnoDB';

                $t->char('id', 26)->primary();
                $t->char('member_id', 26);
                $t->char('deduction_id', 26);
                $t->decimal('amount', 12, 2);
                $t->dateTime('created_at', 3);
            });
        }

        if (! Schema::hasIndex('payroll_run_member_deductions', 'prmd_member_deduction_unq')) {
            Schema::table('payroll_run_member_deductions', function (Blueprint $t): void {
                $t->unique(['member_id', 'deduction_id'], 'prmd_member_deduction_unq');
            });
        }

        if (! Schema::hasIndex('payroll_run_member_deductions', 'prmd_deduction_idx')) {
            Schema::table('payroll_run_member_deductions', function (Blueprint $t): void {
                $t->index('deduction_id', 'prmd_deduction_idx');
            });
        }

        if (! Schema::hasForeignKey('payroll_run_member_deductions', ['member_id'])) {
            Schema::table('payroll_run_member_deductions', function (Blueprint $t): void {
                $t->foreign('member_id')->references('id')->on('payroll_run_members')->cascadeOnDelete();
            });
        }

        if (! Schema::hasForeignKey('payroll_run_member_deductions', ['deduction_id'])) {
            Schema::table('payroll_run_member_deductions', function (Blueprint $t): void {
                $t->foreign('deduction_id')->references('id')->on('payroll_deductions')->restrictOnDelete();
            });
        }

        if ($this->isMySql()) {
            DB::statement('ALTER TABLE `payroll_run_member_deductions` ROW_FORMAT=DYNAMIC');

            if (! $this->hasCheck('prmd_amount_chk')) {
                DB::statement('ALTER TABLE `payroll_run_member_deductions` ADD CONSTRAINT `prmd_amount_chk` CHECK (`amount` > 0)');
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('payroll_run_member_deductions')) {
            Schema::drop('payroll_run_member_deductions');
        }
    }

    private function hasCheck(string $name): bool
    {
        $row = DB::selectOne(
            "SELECT COUNT(*) AS n FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'payroll_run_member_deductions' AND CONSTRAINT_NAME = ? AND CONSTRAINT_TYPE = 'CHECK'",
            [$name],
        );

        return (int) ($row->n ?? 0) > 0;
    }

    private function isMySql(): bool
    {
        return in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true);
    }
};
