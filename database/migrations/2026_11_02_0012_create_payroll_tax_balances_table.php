<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * NEW TABLE — no counterpart in prisma/schema.prisma. `payroll_tax_balances`
 * holds what a tax year already carried for an employee before this
 * system posted it: the go-live OPENING balance (the year's pay and
 * withholding posted elsewhere) and a new hire's PREVIOUS_EMPLOYER figures
 * from the certificate they bring. Payroll enters a row as a DRAFT and a
 * different person approves it; only an APPROVED row joins the year's
 * figures the whole-year withholding estimate reads (TaxYearToDate).
 * Employees never enter them.
 *
 * RETRY-SAFE: CREATE, each index, each FK and each CHECK have their own
 * guard; indexes precede their FKs. The employee key RESTRICTS deletion:
 * a balance is tax history. down() drops the table; the year reads only
 * its posted payslips again.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('payroll_tax_balances')) {
            Schema::create('payroll_tax_balances', static function (Blueprint $t): void {
                $t->char('id', 26)->primary();
                $t->char('employee_id', 26);
                $t->unsignedSmallInteger('tax_year');
                $t->string('kind', 20);
                $t->decimal('taxable_income', 12, 2);
                $t->decimal('tax_withheld', 12, 2);
                $t->string('reason', 160);
                $t->string('reference', 120)->nullable();
                $t->string('status', 10);
                $t->unsignedBigInteger('prepared_by')->nullable();
                $t->string('prepared_by_name', 200)->nullable();
                $t->unsignedBigInteger('approved_by')->nullable();
                $t->string('approved_by_name', 200)->nullable();
                $t->dateTime('approved_at', 3)->nullable();
                $t->dateTime('created_at', 3);
                $t->dateTime('updated_at', 3)->nullable();
            });
        }
        if (! Schema::hasIndex('payroll_tax_balances', 'ptb_employee_idx')) {
            Schema::table('payroll_tax_balances', static fn (Blueprint $t) => $t->index(['employee_id', 'tax_year'], 'ptb_employee_idx'));
        }
        if (! Schema::hasIndex('payroll_tax_balances', 'ptb_status_idx')) {
            Schema::table('payroll_tax_balances', static fn (Blueprint $t) => $t->index(['status', 'tax_year'], 'ptb_status_idx'));
        }
        if (! Schema::hasForeignKey('payroll_tax_balances', ['employee_id'])) {
            Schema::table('payroll_tax_balances', static fn (Blueprint $t) => $t->foreign('employee_id', 'ptb_employee_fk')->references('id')->on('employee_profiles')->restrictOnDelete());
        }
        if (! Schema::hasForeignKey('payroll_tax_balances', ['prepared_by'])) {
            Schema::table('payroll_tax_balances', static fn (Blueprint $t) => $t->foreign('prepared_by', 'ptb_prepared_by_fk')->references('id')->on('users')->nullOnDelete());
        }
        if (! Schema::hasForeignKey('payroll_tax_balances', ['approved_by'])) {
            Schema::table('payroll_tax_balances', static fn (Blueprint $t) => $t->foreign('approved_by', 'ptb_approved_by_fk')->references('id')->on('users')->nullOnDelete());
        }

        if ($this->isMySql()) {
            DB::statement('ALTER TABLE `payroll_tax_balances` ROW_FORMAT=DYNAMIC');
            foreach ([
                'ptb_year_chk' => '`tax_year` BETWEEN 2000 AND 2100',
                'ptb_kind_chk' => "`kind` IN ('OPENING','PREVIOUS_EMPLOYER')",
                'ptb_status_chk' => "`status` IN ('DRAFT','APPROVED')",
                'ptb_approval_chk' => "(`status` = 'DRAFT' AND `approved_at` IS NULL) OR (`status` = 'APPROVED' AND `approved_at` IS NOT NULL)",
                'ptb_amounts_chk' => '`taxable_income` >= 0 AND `tax_withheld` >= 0',
            ] as $name => $expression) {
                if (! $this->hasCheck($name)) {
                    DB::statement("ALTER TABLE `payroll_tax_balances` ADD CONSTRAINT `{$name}` CHECK ({$expression})");
                }
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('payroll_tax_balances')) {
            Schema::drop('payroll_tax_balances');
        }
    }

    private function hasCheck(string $name): bool
    {
        $row = DB::selectOne(
            "SELECT COUNT(*) AS n FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'payroll_tax_balances' AND CONSTRAINT_NAME = ? AND CONSTRAINT_TYPE = 'CHECK'",
            [$name],
        );

        return (int) ($row->n ?? 0) > 0;
    }

    private function isMySql(): bool
    {
        return in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true);
    }
};
