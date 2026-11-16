<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * NEW TABLES — they have no counterpart in prisma/schema.prisma.
 *
 * THE ADJUSTMENT LEDGER: every "difference on a later payroll" as its own
 * approved, linked line.
 *
 *  - `payroll_adjustment_categories`: what a line is — `kind` EARNING or
 *    DEDUCTION, and for an earning `tax` TAXABLE or NOT_TAXED (NULL on a
 *    deduction, which is taken after the tax) — with a unique `name`.
 *    DATA: the starting list is INSERTED while the table is empty
 *    (Correction of salary; Refund – Pag-IBIG loan; Refund – cash advance;
 *    Tax refund; Tax shortfall; Overpayment recovery), to rename as the
 *    company likes.
 *  - `payroll_adjustments`: one line — the employee (RESTRICT: pay
 *    history), the category (RESTRICT), the `amount`, `reason` and
 *    `reference`, its `source` (MANUAL; later phases add their own), its
 *    `status` (PENDING → APPROVED or REJECTED; APPROVED → POSTED), who
 *    prepared and who approved it, an optional `target_run_id` (the open
 *    payroll the approver picked; SET NULL when that draft goes) and the
 *    `payslip_id` that posted it (RESTRICT: a posted payslip never goes).
 *  - `payslip_incentives.adjustment_id` and `payslip_deductions.adjustment_id`:
 *    the posted line naming the adjustment it paid (RESTRICT).
 *
 * RETRY-SAFE: CREATE, each index, each FK, each column and each CHECK have
 * their own guard; indexes precede their FKs. down() drops the two tables
 * and the columns: posted payslips keep their lines, the ledger is lost.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('payroll_adjustment_categories')) {
            Schema::create('payroll_adjustment_categories', static function (Blueprint $t): void {
                $t->engine = 'InnoDB';
                $t->char('id', 26)->primary();
                $t->string('name', 120);
                $t->string('kind', 10);
                $t->string('tax', 10)->nullable();
                $t->unsignedBigInteger('created_by')->nullable();
                $t->string('created_by_name', 200)->nullable();
                $t->dateTime('created_at', 3);
                $t->dateTime('updated_at', 3)->nullable();
            });
        }
        if (! Schema::hasIndex('payroll_adjustment_categories', 'pac_name_unq')) {
            Schema::table('payroll_adjustment_categories', static fn (Blueprint $t) => $t->unique('name', 'pac_name_unq'));
        }
        if (! Schema::hasForeignKey('payroll_adjustment_categories', ['created_by'])) {
            Schema::table('payroll_adjustment_categories', static fn (Blueprint $t) => $t->foreign('created_by', 'pac_created_by_fk')->references('id')->on('users')->nullOnDelete());
        }

        if (! Schema::hasTable('payroll_adjustments')) {
            Schema::create('payroll_adjustments', static function (Blueprint $t): void {
                $t->engine = 'InnoDB';
                $t->char('id', 26)->primary();
                $t->char('employee_id', 26);
                $t->char('category_id', 26);
                $t->decimal('amount', 12, 2);
                $t->string('reason', 160);
                $t->string('reference', 120)->nullable();
                $t->string('source', 20)->default('MANUAL');
                $t->string('status', 10);
                $t->char('target_run_id', 26)->nullable();
                $t->char('payslip_id', 26)->nullable();
                $t->unsignedBigInteger('prepared_by')->nullable();
                $t->string('prepared_by_name', 200)->nullable();
                $t->unsignedBigInteger('approved_by')->nullable();
                $t->string('approved_by_name', 200)->nullable();
                $t->dateTime('approved_at', 3)->nullable();
                $t->string('review_note', 160)->nullable();
                $t->dateTime('created_at', 3);
                $t->dateTime('updated_at', 3)->nullable();
            });
        }
        foreach (['padj_employee_status_idx' => ['employee_id', 'status'], 'padj_status_idx' => ['status', 'created_at'], 'padj_target_idx' => ['target_run_id'], 'padj_payslip_idx' => ['payslip_id'], 'padj_category_idx' => ['category_id']] as $name => $columns) {
            if (! Schema::hasIndex('payroll_adjustments', $name)) {
                Schema::table('payroll_adjustments', static fn (Blueprint $t) => $t->index($columns, $name));
            }
        }
        foreach ([
            'employee_id' => ['padj_employee_fk', 'employee_profiles', 'restrict'],
            'category_id' => ['padj_category_fk', 'payroll_adjustment_categories', 'restrict'],
            'target_run_id' => ['padj_target_fk', 'payroll_runs', 'set null'],
            'payslip_id' => ['padj_payslip_fk', 'payslips', 'restrict'],
            'prepared_by' => ['padj_prepared_by_fk', 'users', 'set null'],
            'approved_by' => ['padj_approved_by_fk', 'users', 'set null'],
        ] as $column => [$name, $table, $onDelete]) {
            if (! Schema::hasForeignKey('payroll_adjustments', [$column])) {
                Schema::table('payroll_adjustments', static fn (Blueprint $t) => $t->foreign($column, $name)->references('id')->on($table)->onDelete($onDelete));
            }
        }

        foreach (['payslip_incentives' => 'psi', 'payslip_deductions' => 'psd'] as $table => $prefix) {
            if (! Schema::hasColumn($table, 'adjustment_id')) {
                Schema::table($table, static fn (Blueprint $t) => $t->char('adjustment_id', 26)->nullable());
            }
            if (! Schema::hasIndex($table, "{$prefix}_adjustment_idx")) {
                Schema::table($table, static fn (Blueprint $t) => $t->index('adjustment_id', "{$prefix}_adjustment_idx"));
            }
            if (! Schema::hasForeignKey($table, ['adjustment_id'])) {
                Schema::table($table, static fn (Blueprint $t) => $t->foreign('adjustment_id', "{$prefix}_adjustment_fk")->references('id')->on('payroll_adjustments')->restrictOnDelete());
            }
        }

        if ($this->isMySql()) {
            foreach (['payroll_adjustment_categories', 'payroll_adjustments'] as $table) {
                DB::statement("ALTER TABLE `{$table}` ROW_FORMAT=DYNAMIC");
            }
            foreach ([
                'payroll_adjustment_categories' => [
                    'pac_kind_chk' => "`kind` IN ('EARNING','DEDUCTION')",
                    'pac_tax_chk' => "(`kind` = 'EARNING' AND `tax` IN ('TAXABLE','NOT_TAXED')) OR (`kind` = 'DEDUCTION' AND `tax` IS NULL)",
                ],
                'payroll_adjustments' => [
                    'padj_amount_chk' => '`amount` > 0',
                    'padj_status_chk' => "`status` IN ('PENDING','APPROVED','REJECTED','POSTED')",
                    'padj_approval_chk' => "(`status` = 'PENDING' AND `approved_at` IS NULL) OR (`status` <> 'PENDING' AND `approved_at` IS NOT NULL)",
                    'padj_posted_chk' => "(`status` = 'POSTED') = (`payslip_id` IS NOT NULL)",
                ],
            ] as $table => $checks) {
                foreach ($checks as $name => $expression) {
                    if (! $this->hasCheck($table, $name)) {
                        DB::statement("ALTER TABLE `{$table}` ADD CONSTRAINT `{$name}` CHECK ({$expression})");
                    }
                }
            }
        }

        $this->seedCategories();
    }

    public function down(): void
    {
        // MySQL/MariaDB drop the key by its name; sqlite only by its column.
        $mysql = $this->isMySql();
        foreach (['payslip_incentives' => 'psi', 'payslip_deductions' => 'psd'] as $table => $prefix) {
            if (Schema::hasColumn($table, 'adjustment_id')) {
                if (Schema::hasForeignKey($table, ['adjustment_id'])) {
                    Schema::table($table, static fn (Blueprint $t) => $t->dropForeign($mysql ? "{$prefix}_adjustment_fk" : ['adjustment_id']));
                }
                if (Schema::hasIndex($table, "{$prefix}_adjustment_idx")) {
                    Schema::table($table, static fn (Blueprint $t) => $t->dropIndex("{$prefix}_adjustment_idx"));
                }
                Schema::table($table, static fn (Blueprint $t) => $t->dropColumn('adjustment_id'));
            }
        }
        foreach (['payroll_adjustments', 'payroll_adjustment_categories'] as $table) {
            if (Schema::hasTable($table)) {
                Schema::drop($table);
            }
        }
    }

    /** The starting categories, named as the company's practice names them; inserted only while the table is empty. */
    private function seedCategories(): void
    {
        if (DB::table('payroll_adjustment_categories')->exists()) {
            return;
        }
        $now = now('UTC')->format('Y-m-d H:i:s.v');
        $rows = [];
        foreach ([
            ['Correction of salary', 'EARNING', 'TAXABLE'],
            ['Refund – Pag-IBIG loan', 'EARNING', 'NOT_TAXED'],
            ['Refund – cash advance', 'EARNING', 'NOT_TAXED'],
            ['Tax refund', 'EARNING', 'NOT_TAXED'],
            ['Tax shortfall', 'DEDUCTION', null],
            ['Overpayment recovery', 'DEDUCTION', null],
        ] as [$name, $kind, $tax]) {
            $rows[] = ['id' => (string) Str::ulid(), 'name' => $name, 'kind' => $kind, 'tax' => $tax, 'created_by' => null, 'created_by_name' => null, 'created_at' => $now, 'updated_at' => null];
        }
        DB::table('payroll_adjustment_categories')->insert($rows);
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
