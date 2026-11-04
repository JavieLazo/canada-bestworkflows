<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * NEW COLUMN — `payroll_pay_rules.scope_type`, and `params` becomes
 * nullable: Pay Basis & Rates versions for ONE EMPLOYEE, ahead of their
 * Employment status's override and the company rule (Records → Payroll
 * Settings → Employee Pay Settings).
 *
 * `scope` holds `COMPANY`, an Employment list id or an employee id. An
 * employee ULID fills all 26 characters, so the kind cannot ride in the
 * scope as a prefix: `scope_type` names it — COMPANY, STATUS or EMPLOYEE.
 * Existing rows are the company's or a status's, so the column defaults to
 * STATUS and the company rows are backfilled; `ppr_scope_type_chk` keeps
 * the two columns agreeing.
 *
 * An employee goes BACK to their status's or the company's rules from a
 * date, and a version is never deleted, so that is a row too: an EMPLOYEE
 * row with NULL `params` (a "follows" row, as roster_shifts has one,
 * 2026_09_30_0005). `ppr_params_chk` allows NULL on such a row only.
 *
 * RETRY-SAFE, STEP BY STEP: the column, the backfill, the index, the
 * nullable change and each CHECK stand behind their own guard. The CHECKs
 * are MariaDB/MySQL only, as every CHECK here is.
 *
 * ROLLBACK LOSES every employee version: their employees are priced by
 * their status's or the company's rules again, which is what payroll did
 * before this migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('payroll_pay_rules')) {
            return;
        }

        if (! Schema::hasColumn('payroll_pay_rules', 'scope_type')) {
            Schema::table('payroll_pay_rules', static fn (Blueprint $t) => $t->string('scope_type', 10)->default('STATUS')->after('scope'));
        }

        DB::table('payroll_pay_rules')->where('scope', 'COMPANY')->where('scope_type', '!=', 'COMPANY')->update(['scope_type' => 'COMPANY']);

        if (! Schema::hasIndex('payroll_pay_rules', 'ppr_scope_type_idx')) {
            Schema::table('payroll_pay_rules', static fn (Blueprint $t) => $t->index(['scope_type', 'scope'], 'ppr_scope_type_idx'));
        }

        if (! $this->isNullable('params')) {
            Schema::table('payroll_pay_rules', static fn (Blueprint $t) => $t->json('params')->nullable()->change());
        }

        if ($this->isMySql()) {
            if (! $this->hasCheck('ppr_scope_type_chk')) {
                DB::statement("ALTER TABLE `payroll_pay_rules` ADD CONSTRAINT `ppr_scope_type_chk` CHECK (`scope_type` IN ('COMPANY', 'STATUS', 'EMPLOYEE') AND ((`scope` = 'COMPANY') = (`scope_type` = 'COMPANY')))");
            }
            if (! $this->hasCheck('ppr_params_chk')) {
                DB::statement("ALTER TABLE `payroll_pay_rules` ADD CONSTRAINT `ppr_params_chk` CHECK (`params` IS NOT NULL OR `scope_type` = 'EMPLOYEE')");
            }
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('payroll_pay_rules') || ! Schema::hasColumn('payroll_pay_rules', 'scope_type')) {
            return;
        }

        DB::table('payroll_pay_rules')->where('scope_type', 'EMPLOYEE')->delete();

        if ($this->isMySql()) {
            foreach (['ppr_params_chk', 'ppr_scope_type_chk'] as $check) {
                if ($this->hasCheck($check)) {
                    DB::statement("ALTER TABLE `payroll_pay_rules` DROP CONSTRAINT `{$check}`");
                }
            }
        }

        if ($this->isNullable('params')) {
            Schema::table('payroll_pay_rules', static fn (Blueprint $t) => $t->json('params')->nullable(false)->change());
        }

        if (Schema::hasIndex('payroll_pay_rules', 'ppr_scope_type_idx')) {
            Schema::table('payroll_pay_rules', static fn (Blueprint $t) => $t->dropIndex('ppr_scope_type_idx'));
        }

        Schema::table('payroll_pay_rules', static fn (Blueprint $t) => $t->dropColumn('scope_type'));
    }

    private function isNullable(string $column): bool
    {
        foreach (Schema::getColumns('payroll_pay_rules') as $definition) {
            if ($definition['name'] === $column) {
                return (bool) $definition['nullable'];
            }
        }

        return false;
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
