<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * NEW TABLE — it has no counterpart in prisma/schema.prisma.
 *
 * EACH EMPLOYEE'S SALARY AS DATED VERSIONS: the Rate Per Month and the
 * Rate Per Day in force from a date (`App\Models\EmployeeSalaryRate`,
 * Workforce), with the reason, an optional reference (the memo or
 * appointment paper) and who recorded it. A change dated today or later
 * is APPROVED as recorded; a change dated in the past waits PENDING for a
 * different person's approval (or is REJECTED). The profile columns
 * (`employee_profiles.monthly_rate` / `daily_rate`) stay as THE RATE IN
 * FORCE TODAY, copied from the version that has taken effect — every
 * reader of the record keeps reading them — while payroll prices each day
 * at the version in force on it (PayslipCalculator divergence 18).
 *
 * SEED (the go-live copy): each employee with a rate and no version gets
 * one APPROVED version from their hire date, holding the record's rates,
 * so nothing moves. Idempotent: an employee who already has a version is
 * skipped.
 *
 * RETRY-SAFE: CREATE, each index, each FK, each CHECK and the seed have
 * their own guard; indexes precede their FKs. The employee key RESTRICTS
 * deletion: a salary version is pay history. down() drops the table; the
 * profile columns keep today's rates.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('employee_salary_rates')) {
            Schema::create('employee_salary_rates', static function (Blueprint $t): void {
                $t->engine = 'InnoDB';
                $t->char('id', 26)->primary();
                $t->char('employee_id', 26);
                $t->date('effective_from');
                $t->decimal('monthly_rate', 12, 2)->nullable();
                $t->decimal('daily_rate', 12, 2)->nullable();
                $t->string('reason', 160);
                $t->string('reference', 120)->nullable();
                $t->string('status', 10);
                $t->unsignedBigInteger('prepared_by')->nullable();
                $t->string('prepared_by_name', 200)->nullable();
                $t->unsignedBigInteger('approved_by')->nullable();
                $t->string('approved_by_name', 200)->nullable();
                $t->dateTime('approved_at', 3)->nullable();
                $t->string('review_note', 500)->nullable();
                $t->dateTime('created_at', 3);
                $t->dateTime('updated_at', 3)->nullable();
            });
        }
        if (! Schema::hasIndex('employee_salary_rates', 'esr_employee_from_idx')) {
            Schema::table('employee_salary_rates', static fn (Blueprint $t) => $t->index(['employee_id', 'effective_from'], 'esr_employee_from_idx'));
        }
        if (! Schema::hasIndex('employee_salary_rates', 'esr_status_idx')) {
            Schema::table('employee_salary_rates', static fn (Blueprint $t) => $t->index(['status', 'effective_from'], 'esr_status_idx'));
        }
        if (! Schema::hasForeignKey('employee_salary_rates', ['employee_id'])) {
            Schema::table('employee_salary_rates', static fn (Blueprint $t) => $t->foreign('employee_id', 'esr_employee_fk')->references('id')->on('employee_profiles')->restrictOnDelete());
        }
        if (! Schema::hasForeignKey('employee_salary_rates', ['prepared_by'])) {
            Schema::table('employee_salary_rates', static fn (Blueprint $t) => $t->foreign('prepared_by', 'esr_prepared_by_fk')->references('id')->on('users')->nullOnDelete());
        }
        if (! Schema::hasForeignKey('employee_salary_rates', ['approved_by'])) {
            Schema::table('employee_salary_rates', static fn (Blueprint $t) => $t->foreign('approved_by', 'esr_approved_by_fk')->references('id')->on('users')->nullOnDelete());
        }

        if ($this->isMySql()) {
            DB::statement('ALTER TABLE `employee_salary_rates` ROW_FORMAT=DYNAMIC');
            foreach ([
                'esr_status_chk' => "`status` IN ('PENDING','APPROVED','REJECTED')",
                'esr_approval_chk' => "(`status` = 'PENDING' AND `approved_at` IS NULL) OR (`status` <> 'PENDING' AND `approved_at` IS NOT NULL)",
                'esr_rates_chk' => '(`monthly_rate` IS NULL OR `monthly_rate` >= 0) AND (`daily_rate` IS NULL OR `daily_rate` >= 0) AND (`monthly_rate` IS NOT NULL OR `daily_rate` IS NOT NULL)',
            ] as $name => $expression) {
                if (! $this->hasCheck($name)) {
                    DB::statement("ALTER TABLE `employee_salary_rates` ADD CONSTRAINT `{$name}` CHECK ({$expression})");
                }
            }
        }

        $this->seed();
    }

    public function down(): void
    {
        if (Schema::hasTable('employee_salary_rates')) {
            Schema::drop('employee_salary_rates');
        }
    }

    /** One approved version per employee with a rate and none yet, from their hire date. */
    private function seed(): void
    {
        $now = now('UTC')->format('Y-m-d H:i:s.v');
        $employees = DB::table('employee_profiles')
            ->whereNotExists(static fn ($query) => $query->selectRaw('1')->from('employee_salary_rates')->whereColumn('employee_salary_rates.employee_id', 'employee_profiles.id'))
            ->where(static fn ($query) => $query->where('monthly_rate', '>', 0)->orWhere('daily_rate', '>', 0))
            ->get(['id', 'date_hired', 'monthly_rate', 'daily_rate']);
        $rows = [];
        foreach ($employees as $employee) {
            $rows[] = [
                'id' => (string) Str::ulid(),
                'employee_id' => $employee->id,
                'effective_from' => substr((string) $employee->date_hired, 0, 10),
                'monthly_rate' => $employee->monthly_rate,
                'daily_rate' => $employee->daily_rate,
                'reason' => 'Rates as recorded',
                'reference' => null,
                'status' => 'APPROVED',
                'prepared_by' => null,
                'prepared_by_name' => null,
                'approved_by' => null,
                'approved_by_name' => null,
                'approved_at' => $now,
                'review_note' => null,
                'created_at' => $now,
                'updated_at' => null,
            ];
        }
        foreach (array_chunk($rows, 200) as $chunk) {
            DB::table('employee_salary_rates')->insert($chunk);
        }
    }

    private function hasCheck(string $name): bool
    {
        $row = DB::selectOne(
            "SELECT COUNT(*) AS n FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'employee_salary_rates' AND CONSTRAINT_NAME = ? AND CONSTRAINT_TYPE = 'CHECK'",
            [$name],
        );

        return (int) ($row->n ?? 0) > 0;
    }

    private function isMySql(): bool
    {
        return in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true);
    }
};
