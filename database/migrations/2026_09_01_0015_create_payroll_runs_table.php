<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Port of `model PayrollRun` (prisma/schema.prisma:482-513).
 *
 * FOUR source columns do not live here:
 *
 *  - `employee_ids String[]` — MySQL has no array type, and there is no JSON
 *    fallback: requireUniquePayrollMembership (src/lib/payroll-validation.ts:108)
 *    filters it with Prisma's `{ hasSome: [...] }`, which compiles to a
 *    server-side array overlap. The MySQL equivalent is JSON_OVERLAPS, which is
 *    8.0.17+ only and absent from MariaDB at every version. It becomes the
 *    payroll_run_members pivot, which also closes the referential-integrity hole
 *    the source's own schema comment admits to ("deleting an employee leaves a
 *    dangling id here").
 *  - `draft_deductions`, `draft_allowances` and `draft_earnings` Json — all
 *    three are keyed by employee id, so they normalise onto the same pivot as
 *    real DECIMAL columns. That makes requirePayrollOverrideMembers (which
 *    checks that every override key is a member) structurally impossible to
 *    violate.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payroll_runs', function (Blueprint $t): void {
            $t->engine = 'InnoDB';

            $t->char('id', 26)->primary();
            $t->string('code', 40)->unique();

            // 200 x 4 = 800 bytes: fine for a unique index under DYNAMIC.
            $t->string('name', 200)->unique();

            $t->date('period_start');
            $t->date('period_end');

            // PostgreSQL native enum -> varchar + App\Domain\Enums\PayrollRunStatus
            // + the CHECK below. A MySQL ENUM sorts by declaration ordinal, so
            // ORDER BY status would silently differ from the source, and
            // extending it would be an ALTER TABLE.
            $t->string('status', 20)->default('DRAFT');

            $t->dateTime('finalized_at', 3)->nullable();
            $t->unsignedBigInteger('finalized_by')->nullable();

            $t->dateTime('created_at', 3);
            $t->dateTime('updated_at', 3)->nullable();

            $t->index('period_start', 'runs_period_start_idx');

            $t->foreign('finalized_by')->references('id')->on('users')->nullOnDelete();
        });

        $this->dynamicRowFormat('payroll_runs');

        // Enforced on MySQL 8.0.16+ / MariaDB 10.2.1+, parsed-and-ignored below
        // that. PayrollRunStatus and Rule::in() are the real gate.
        $this->check('payroll_runs', 'runs_status_chk', "`status` IN ('DRAFT','FINALIZED')");
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_runs');
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

    private function check(string $table, string $name, string $expression): void
    {
        if (! $this->isMySql()) {
            return;
        }

        DB::statement("ALTER TABLE `{$table}` ADD CONSTRAINT `{$name}` CHECK ({$expression})");
    }

    private function isMySql(): bool
    {
        return in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true);
    }
};
