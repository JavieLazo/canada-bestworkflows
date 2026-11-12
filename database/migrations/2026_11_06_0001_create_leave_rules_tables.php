<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * THE LEAVE RULES, SET ON THE LEAVE TYPE FOR EVERY EMPLOYEE. Two tables:
 *
 *  - `leave_rules`: dated versions of a leave type's rule, by `scope` —
 *    the type's token (its waiting period, what the employee holds on
 *    acquiring it, its monthly earning and the type it falls back on).
 *    Saving a rule
 *    writes the version for that day, APPROVED at once and in force from
 *    it (SaveLeaveRule); the DRAFT value the CHECK allows is not written.
 *    One version per scope and date. The scope is text, like the token
 *    credits and requests carry, so it has no foreign key;
 *    `prepared_by` / `approved_by` (both the saver) have none either, and
 *    Timekeeping's AccountReferences keeps the accounts they name.
 *  - `leave_acquisitions`: the day an employee acquired a leave type that
 *    has a waiting period — written once by the earning job, with how the
 *    day was reached (`basis`) and the credits granted on it. The unique
 *    key on (employee, type) is what makes the job safe to run twice.
 *
 * RETRY-SAFE: CREATE, each index, the FK and each CHECK have their own
 * guard; indexes precede the FK. The employee FK cascades like the
 * credits'. down() drops both tables: the ledger entries a grant wrote
 * stay, as the balances they moved do, and with no rule nobody has terms —
 * nothing earns and nothing is charged to credits.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('leave_rules')) {
            Schema::create('leave_rules', static function (Blueprint $t): void {
                $t->engine = 'InnoDB';
                $t->char('id', 26)->primary();
                $t->string('scope', 60);
                $t->date('effective_from');
                $t->json('params');
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
        if (! Schema::hasIndex('leave_rules', 'lrule_scope_from_unq')) {
            Schema::table('leave_rules', static fn (Blueprint $t) => $t->unique(['scope', 'effective_from'], 'lrule_scope_from_unq'));
        }
        if (! Schema::hasIndex('leave_rules', 'lrule_status_idx')) {
            Schema::table('leave_rules', static fn (Blueprint $t) => $t->index(['status', 'effective_from'], 'lrule_status_idx'));
        }

        if (! Schema::hasTable('leave_acquisitions')) {
            Schema::create('leave_acquisitions', static function (Blueprint $t): void {
                $t->engine = 'InnoDB';
                $t->char('id', 26)->primary();
                $t->char('employee_id', 26);
                $t->string('request_type', 60);
                $t->date('acquired_on');
                $t->char('rule_id', 26)->nullable();
                $t->json('basis');
                $t->decimal('granted', 10, 4)->default(0);
                $t->char('entry_id', 26)->nullable();
                $t->dateTime('created_at', 3);
            });
        }
        if (! Schema::hasIndex('leave_acquisitions', 'lacq_employee_type_unq')) {
            Schema::table('leave_acquisitions', static fn (Blueprint $t) => $t->unique(['employee_id', 'request_type'], 'lacq_employee_type_unq'));
        }
        if (! Schema::hasForeignKey('leave_acquisitions', ['employee_id'])) {
            Schema::table('leave_acquisitions', static fn (Blueprint $t) => $t->foreign('employee_id', 'lacq_employee_fk')->references('id')->on('employee_profiles')->cascadeOnDelete());
        }

        if ($this->isMySql()) {
            foreach (['leave_rules', 'leave_acquisitions'] as $table) {
                DB::statement("ALTER TABLE `{$table}` ROW_FORMAT=DYNAMIC");
            }
            foreach ([
                'leave_rules' => [
                    'lrule_status_chk' => "`status` IN ('DRAFT','APPROVED')",
                    'lrule_approval_chk' => "(`status` = 'DRAFT' AND `approved_at` IS NULL) OR (`status` = 'APPROVED' AND `approved_at` IS NOT NULL)",
                ],
                'leave_acquisitions' => [
                    'lacq_granted_chk' => '`granted` BETWEEN 0 AND 999999.9999',
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
        foreach (['leave_acquisitions', 'leave_rules'] as $table) {
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
