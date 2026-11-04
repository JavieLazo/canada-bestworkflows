<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * NEW TABLE — no counterpart in prisma/schema.prisma. A loan's ledger
 * beside the payslips: an OUTSIDE_PAYMENT the employee made straight to
 * the lender (it lowers what is left to collect) or an ADJUSTMENT of the
 * balance — the difference on the lender's statement, positive when more
 * is owed, negative when less. Each entry names its recurring deduction
 * line, is prepared by one person as a DRAFT and approved by another;
 * only an APPROVED entry joins what the payslips paid
 * (RecurringLineLedger::paidBefore), so every draft payroll re-reads what
 * is left and finalized payslips keep what they collected.
 *
 * RETRY-SAFE: CREATE, each index, each FK and each CHECK have their own
 * guard; indexes precede their FKs. The line and employee keys RESTRICT
 * deletion: an entry is pay history. down() drops the table; what is left
 * on a loan reads from the payslips alone again.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('payroll_recurring_line_entries')) {
            Schema::create('payroll_recurring_line_entries', static function (Blueprint $t): void {
                $t->char('id', 26)->primary();
                $t->char('line_id', 26);
                $t->char('employee_id', 26);
                $t->string('kind', 20);
                $t->decimal('amount', 12, 2);
                $t->date('entry_date');
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
        if (! Schema::hasIndex('payroll_recurring_line_entries', 'prle_line_idx')) {
            Schema::table('payroll_recurring_line_entries', static fn (Blueprint $t) => $t->index(['line_id', 'status'], 'prle_line_idx'));
        }
        if (! Schema::hasIndex('payroll_recurring_line_entries', 'prle_employee_idx')) {
            Schema::table('payroll_recurring_line_entries', static fn (Blueprint $t) => $t->index(['employee_id', 'entry_date'], 'prle_employee_idx'));
        }
        if (! Schema::hasForeignKey('payroll_recurring_line_entries', ['line_id'])) {
            Schema::table('payroll_recurring_line_entries', static fn (Blueprint $t) => $t->foreign('line_id', 'prle_line_fk')->references('id')->on('payroll_recurring_lines')->restrictOnDelete());
        }
        if (! Schema::hasForeignKey('payroll_recurring_line_entries', ['employee_id'])) {
            Schema::table('payroll_recurring_line_entries', static fn (Blueprint $t) => $t->foreign('employee_id', 'prle_employee_fk')->references('id')->on('employee_profiles')->restrictOnDelete());
        }
        if (! Schema::hasForeignKey('payroll_recurring_line_entries', ['prepared_by'])) {
            Schema::table('payroll_recurring_line_entries', static fn (Blueprint $t) => $t->foreign('prepared_by', 'prle_prepared_by_fk')->references('id')->on('users')->nullOnDelete());
        }
        if (! Schema::hasForeignKey('payroll_recurring_line_entries', ['approved_by'])) {
            Schema::table('payroll_recurring_line_entries', static fn (Blueprint $t) => $t->foreign('approved_by', 'prle_approved_by_fk')->references('id')->on('users')->nullOnDelete());
        }

        if ($this->isMySql()) {
            DB::statement('ALTER TABLE `payroll_recurring_line_entries` ROW_FORMAT=DYNAMIC');
            foreach ([
                'prle_kind_chk' => "`kind` IN ('OUTSIDE_PAYMENT','ADJUSTMENT')",
                'prle_amount_chk' => "(`kind` = 'OUTSIDE_PAYMENT' AND `amount` > 0) OR (`kind` = 'ADJUSTMENT' AND `amount` <> 0)",
                'prle_status_chk' => "`status` IN ('DRAFT','APPROVED')",
                'prle_approval_chk' => "(`status` = 'DRAFT' AND `approved_at` IS NULL) OR (`status` = 'APPROVED' AND `approved_at` IS NOT NULL)",
            ] as $name => $expression) {
                if (! $this->hasCheck($name)) {
                    DB::statement("ALTER TABLE `payroll_recurring_line_entries` ADD CONSTRAINT `{$name}` CHECK ({$expression})");
                }
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('payroll_recurring_line_entries')) {
            Schema::drop('payroll_recurring_line_entries');
        }
    }

    private function hasCheck(string $name): bool
    {
        $row = DB::selectOne(
            "SELECT COUNT(*) AS n FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'payroll_recurring_line_entries' AND CONSTRAINT_NAME = ? AND CONSTRAINT_TYPE = 'CHECK'",
            [$name],
        );

        return (int) ($row->n ?? 0) > 0;
    }

    private function isMySql(): bool
    {
        return in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true);
    }
};
