<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * NEW TABLE.
 *
 * What payroll must know about one employee to deduct Canada Pension Plan
 * contributions, Employment Insurance premiums and income tax: the province
 * of employment, the total claim amounts from the employee's federal and
 * provincial personal tax credits returns, any extra tax they asked to have
 * deducted each pay, and whether the pension plan and the insurance apply to
 * them at all.
 *
 * One row per employee, changed in place. An employee with no row is read as
 * the starting position every new hire has: the province the system is set
 * up for, the basic claim amounts, no extra tax, both deductions on. A claim
 * amount left NULL means "the basic amount in force", so it follows each
 * year's published figure without anyone retyping it.
 *
 * `updated_by` is the account that last saved the row (SET NULL on account
 * deletion) and `updated_by_name` its name at the time, denormalised like
 * `audit_logs.actor_name`.
 *
 * RETRY-SAFE, STEP BY STEP: the create carries only the columns and the
 * primary key; the unique index, the foreign keys, the row format and the
 * CHECKs are their own guarded steps, so a run stopped between any two
 * statements is simply run again. On SQLite (tests) no CHECK is created.
 *
 * ROLLBACK LOSES every employee's saved tax details; payroll then reads
 * everyone at the starting position.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('payroll_employee_tax_details')) {
            Schema::create('payroll_employee_tax_details', static function (Blueprint $t): void {
                $t->engine = 'InnoDB';

                $t->char('id', 26)->primary();
                $t->char('employee_id', 26);

                // Two-letter province or territory code — free text plus petd_province_chk below.
                $t->char('province', 2);

                // NULL = the basic personal amount in force.
                $t->decimal('federal_claim', 12, 2)->nullable();
                $t->decimal('provincial_claim', 12, 2)->nullable();

                $t->decimal('additional_tax', 12, 2)->default(0);
                $t->boolean('deduct_pension')->default(true);
                $t->boolean('deduct_insurance')->default(true);

                $t->unsignedBigInteger('updated_by')->nullable();
                $t->string('updated_by_name', 200)->nullable();

                $t->dateTime('created_at', 3);
                $t->dateTime('updated_at', 3)->nullable();
            });
        }

        if (! Schema::hasIndex('payroll_employee_tax_details', 'petd_employee_unq')) {
            Schema::table('payroll_employee_tax_details', static fn (Blueprint $t) => $t->unique('employee_id', 'petd_employee_unq'));
        }
        if (! Schema::hasForeignKey('payroll_employee_tax_details', ['employee_id'])) {
            Schema::table('payroll_employee_tax_details', static fn (Blueprint $t) => $t->foreign('employee_id', 'petd_employee_fk')->references('id')->on('employee_profiles')->cascadeOnDelete());
        }
        if (! Schema::hasForeignKey('payroll_employee_tax_details', ['updated_by'])) {
            Schema::table('payroll_employee_tax_details', static fn (Blueprint $t) => $t->foreign('updated_by', 'petd_updated_by_fk')->references('id')->on('users')->nullOnDelete());
        }

        if ($this->isMySql()) {
            // Idempotent: a retry restates the same row format.
            DB::statement('ALTER TABLE `payroll_employee_tax_details` ROW_FORMAT=DYNAMIC');
            foreach ([
                'petd_province_chk' => "`province` IN ('AB','BC','MB','NB','NL','NS','NT','NU','ON','PE','QC','SK','YT')",
                'petd_amounts_chk' => '(`federal_claim` IS NULL OR `federal_claim` >= 0) AND (`provincial_claim` IS NULL OR `provincial_claim` >= 0) AND `additional_tax` >= 0',
            ] as $name => $expression) {
                if (! $this->hasCheck($name)) {
                    DB::statement("ALTER TABLE `payroll_employee_tax_details` ADD CONSTRAINT `{$name}` CHECK ({$expression})");
                }
            }
        }
    }

    public function down(): void
    {
        // The CHECKs, the index and the foreign keys all go with the table.
        if (Schema::hasTable('payroll_employee_tax_details')) {
            Schema::drop('payroll_employee_tax_details');
        }
    }

    /** Whether the named CHECK exists — information_schema, so MySQL/MariaDB only. */
    private function hasCheck(string $name): bool
    {
        $row = DB::selectOne(
            "SELECT COUNT(*) AS n FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'payroll_employee_tax_details' AND CONSTRAINT_NAME = ? AND CONSTRAINT_TYPE = 'CHECK'",
            [$name],
        );

        return (int) ($row->n ?? 0) > 0;
    }

    private function isMySql(): bool
    {
        return in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true);
    }
};
