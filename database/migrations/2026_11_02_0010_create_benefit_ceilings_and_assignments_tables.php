<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * NEW TABLES — neither has a counterpart in prisma/schema.prisma.
 *
 * `payroll_benefit_ceilings`: the yearly TAX-FREE CEILING on exempt
 * benefits and the 13th month pay as dated versions (the engine carried
 * PHP 90,000 as a constant, `ExemptBenefitCeiling::CEILING`). The version
 * in force on a payroll's first day — 31 December for the 13th month run
 * — is captured in the evidence and splits the exempt lines. SEEDED with
 * one version from 2025-01-01 holding 90,000 while the table is empty, so
 * nothing moves.
 *
 * `payroll_employee_benefits`: a scheduled Incentives & Bonuses type
 * ASSIGNED to one employee for a date span, with an amount of their own
 * (NULL = the type's rule), the reason and the paper behind it, prepared
 * by one person (DRAFT) and approved by another. An approved assignment
 * proposes the benefit on every payroll inside its span whether or not the
 * type's status rule qualifies the employee.
 *
 * RETRY-SAFE: CREATE, each index, each FK, each CHECK and the seed have
 * their own guard; indexes precede their FKs. The employee and type keys
 * RESTRICT deletion: an assignment is pay history, and a type an
 * assignment names must not vanish. down() drops both tables; the ceiling
 * reads as the constant again.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('payroll_benefit_ceilings')) {
            Schema::create('payroll_benefit_ceilings', static function (Blueprint $t): void {
                $t->engine = 'InnoDB';
                $t->char('id', 26)->primary();
                $t->date('effective_from');
                $t->decimal('ceiling', 12, 2);
                $t->string('reason', 160);
                $t->unsignedBigInteger('created_by')->nullable();
                $t->string('created_by_name', 200)->nullable();
                $t->dateTime('created_at', 3);
                $t->dateTime('updated_at', 3)->nullable();
            });
        }
        if (! Schema::hasIndex('payroll_benefit_ceilings', 'pbc_from_unq')) {
            Schema::table('payroll_benefit_ceilings', static fn (Blueprint $t) => $t->unique('effective_from', 'pbc_from_unq'));
        }
        if (! Schema::hasForeignKey('payroll_benefit_ceilings', ['created_by'])) {
            Schema::table('payroll_benefit_ceilings', static fn (Blueprint $t) => $t->foreign('created_by', 'pbc_created_by_fk')->references('id')->on('users')->nullOnDelete());
        }

        if (! Schema::hasTable('payroll_employee_benefits')) {
            Schema::create('payroll_employee_benefits', static function (Blueprint $t): void {
                $t->engine = 'InnoDB';
                $t->char('id', 26)->primary();
                $t->char('employee_id', 26);
                $t->char('type_id', 26);
                $t->date('starts_on');
                $t->date('ends_on')->nullable();
                $t->decimal('amount', 12, 2)->nullable();
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
        if (! Schema::hasIndex('payroll_employee_benefits', 'peb_employee_idx')) {
            Schema::table('payroll_employee_benefits', static fn (Blueprint $t) => $t->index(['employee_id', 'starts_on'], 'peb_employee_idx'));
        }
        if (! Schema::hasIndex('payroll_employee_benefits', 'peb_status_idx')) {
            Schema::table('payroll_employee_benefits', static fn (Blueprint $t) => $t->index(['status', 'starts_on'], 'peb_status_idx'));
        }
        if (! Schema::hasForeignKey('payroll_employee_benefits', ['employee_id'])) {
            Schema::table('payroll_employee_benefits', static fn (Blueprint $t) => $t->foreign('employee_id', 'peb_employee_fk')->references('id')->on('employee_profiles')->restrictOnDelete());
        }
        if (! Schema::hasForeignKey('payroll_employee_benefits', ['type_id'])) {
            Schema::table('payroll_employee_benefits', static fn (Blueprint $t) => $t->foreign('type_id', 'peb_type_fk')->references('id')->on('payroll_incentive_types')->restrictOnDelete());
        }
        if (! Schema::hasForeignKey('payroll_employee_benefits', ['prepared_by'])) {
            Schema::table('payroll_employee_benefits', static fn (Blueprint $t) => $t->foreign('prepared_by', 'peb_prepared_by_fk')->references('id')->on('users')->nullOnDelete());
        }
        if (! Schema::hasForeignKey('payroll_employee_benefits', ['approved_by'])) {
            Schema::table('payroll_employee_benefits', static fn (Blueprint $t) => $t->foreign('approved_by', 'peb_approved_by_fk')->references('id')->on('users')->nullOnDelete());
        }

        if ($this->isMySql()) {
            DB::statement('ALTER TABLE `payroll_benefit_ceilings` ROW_FORMAT=DYNAMIC');
            DB::statement('ALTER TABLE `payroll_employee_benefits` ROW_FORMAT=DYNAMIC');
            foreach ([
                'payroll_benefit_ceilings' => ['pbc_ceiling_chk' => '`ceiling` >= 0'],
                'payroll_employee_benefits' => [
                    'peb_status_chk' => "`status` IN ('DRAFT','APPROVED')",
                    'peb_approval_chk' => "(`status` = 'DRAFT' AND `approved_at` IS NULL) OR (`status` = 'APPROVED' AND `approved_at` IS NOT NULL)",
                    'peb_span_chk' => '`ends_on` IS NULL OR `ends_on` >= `starts_on`',
                    'peb_amount_chk' => '`amount` IS NULL OR `amount` > 0',
                ],
            ] as $table => $checks) {
                foreach ($checks as $name => $expression) {
                    if (! $this->hasCheck($table, $name)) {
                        DB::statement("ALTER TABLE `{$table}` ADD CONSTRAINT `{$name}` CHECK ({$expression})");
                    }
                }
            }
        }

        if (! DB::table('payroll_benefit_ceilings')->exists()) {
            DB::table('payroll_benefit_ceilings')->insert([
                'id' => (string) Str::ulid(),
                'effective_from' => '2025-01-01',
                'ceiling' => 90000,
                'reason' => 'The yearly tax-free ceiling as the engine carried it',
                'created_by' => null,
                'created_by_name' => null,
                'created_at' => now('UTC')->format('Y-m-d H:i:s.v'),
                'updated_at' => null,
            ]);
        }
    }

    public function down(): void
    {
        foreach (['payroll_employee_benefits', 'payroll_benefit_ceilings'] as $table) {
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
