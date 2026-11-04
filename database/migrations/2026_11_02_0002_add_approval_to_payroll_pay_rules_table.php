<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * NEW COLUMNS on `payroll_pay_rules`: a second person approves an
 * employee's own Pay Basis & Rates version before it prices them.
 *
 * `status` is DRAFT or APPROVED. `created_by` stays the preparer;
 * `approved_by` (SET NULL on account deletion, `approved_by_name` kept like
 * `audit_logs.actor_name`) and `approved_at` record the approval. Only an
 * EMPLOYEE version is ever a draft: the company's and a status's versions
 * are saved approved, as every version was before this column, so every
 * EXISTING row is backfilled APPROVED with `approved_at` = `created_at`
 * and no money moves. `ppr_approval_chk` keeps the status and the stamp
 * agreeing; `ppr_draft_chk` keeps drafts to employee rows.
 *
 * RETRY-SAFE, STEP BY STEP: each column, the backfill, the index, the
 * foreign key and each CHECK stand behind their own guard. The CHECKs are
 * MariaDB/MySQL only, as every CHECK here is.
 *
 * ROLLBACK LOSES every draft (deleted) and the approval stamps; the
 * approved versions stay and price as they did.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('payroll_pay_rules')) {
            return;
        }

        if (! Schema::hasColumn('payroll_pay_rules', 'status')) {
            Schema::table('payroll_pay_rules', static fn (Blueprint $t) => $t->string('status', 10)->default('APPROVED')->after('params'));
        }
        if (! Schema::hasColumn('payroll_pay_rules', 'approved_by')) {
            Schema::table('payroll_pay_rules', static fn (Blueprint $t) => $t->unsignedBigInteger('approved_by')->nullable()->after('created_by_name'));
        }
        if (! Schema::hasColumn('payroll_pay_rules', 'approved_by_name')) {
            Schema::table('payroll_pay_rules', static fn (Blueprint $t) => $t->string('approved_by_name', 200)->nullable()->after('approved_by'));
        }
        if (! Schema::hasColumn('payroll_pay_rules', 'approved_at')) {
            Schema::table('payroll_pay_rules', static fn (Blueprint $t) => $t->dateTime('approved_at', 3)->nullable()->after('approved_by_name'));
        }

        // Every row written before approval existed is in force: stamp it.
        DB::table('payroll_pay_rules')->where('status', 'APPROVED')->whereNull('approved_at')->update(['approved_at' => DB::raw('`created_at`')]);

        if (! Schema::hasIndex('payroll_pay_rules', 'ppr_status_idx')) {
            Schema::table('payroll_pay_rules', static fn (Blueprint $t) => $t->index(['status', 'scope_type'], 'ppr_status_idx'));
        }
        if (! Schema::hasForeignKey('payroll_pay_rules', ['approved_by'])) {
            Schema::table('payroll_pay_rules', static fn (Blueprint $t) => $t->foreign('approved_by', 'ppr_approved_by_fk')->references('id')->on('users')->nullOnDelete());
        }

        if ($this->isMySql()) {
            if (! $this->hasCheck('ppr_status_chk')) {
                DB::statement("ALTER TABLE `payroll_pay_rules` ADD CONSTRAINT `ppr_status_chk` CHECK (`status` IN ('DRAFT', 'APPROVED'))");
            }
            if (! $this->hasCheck('ppr_approval_chk')) {
                DB::statement("ALTER TABLE `payroll_pay_rules` ADD CONSTRAINT `ppr_approval_chk` CHECK ((`status` = 'DRAFT' AND `approved_at` IS NULL) OR (`status` = 'APPROVED' AND `approved_at` IS NOT NULL))");
            }
            if (! $this->hasCheck('ppr_draft_chk')) {
                DB::statement("ALTER TABLE `payroll_pay_rules` ADD CONSTRAINT `ppr_draft_chk` CHECK (`status` = 'APPROVED' OR `scope_type` = 'EMPLOYEE')");
            }
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('payroll_pay_rules') || ! Schema::hasColumn('payroll_pay_rules', 'status')) {
            return;
        }

        DB::table('payroll_pay_rules')->where('status', 'DRAFT')->delete();

        if ($this->isMySql()) {
            foreach (['ppr_draft_chk', 'ppr_approval_chk', 'ppr_status_chk'] as $check) {
                if ($this->hasCheck($check)) {
                    DB::statement("ALTER TABLE `payroll_pay_rules` DROP CONSTRAINT `{$check}`");
                }
            }
        }
        // By column, not by name: SQLite drops a foreign key only through a table rebuild keyed on its columns.
        if (Schema::hasForeignKey('payroll_pay_rules', ['approved_by'])) {
            Schema::table('payroll_pay_rules', static fn (Blueprint $t) => $t->dropForeign(['approved_by']));
        }
        if (Schema::hasIndex('payroll_pay_rules', 'ppr_status_idx')) {
            Schema::table('payroll_pay_rules', static fn (Blueprint $t) => $t->dropIndex('ppr_status_idx'));
        }
        foreach (['approved_at', 'approved_by_name', 'approved_by', 'status'] as $column) {
            if (Schema::hasColumn('payroll_pay_rules', $column)) {
                Schema::table('payroll_pay_rules', static fn (Blueprint $t) => $t->dropColumn($column));
            }
        }
    }

    private function hasCheck(string $name): bool
    {
        $row = DB::selectOne("SELECT COUNT(*) AS n FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'payroll_pay_rules' AND CONSTRAINT_NAME = ? AND CONSTRAINT_TYPE = 'CHECK'", [$name]);

        return (int) ($row->n ?? 0) > 0;
    }

    private function isMySql(): bool
    {
        return in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true);
    }
};
