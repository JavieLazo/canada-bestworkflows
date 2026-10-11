<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * NEW TABLE — internal Workforce employment timeline, not a payroll input.
 *
 * `employee_profiles` remains the current employee snapshot used by the rest
 * of EMS. This table records append-only copies of the Employee Details values
 * after a hire or a later HR edit, so HR can see when position, status, site,
 * dates or rates changed without making Payroll calculate from historical
 * rates. `recorded_by` and `recorded_by_name` are copied from Access, with no
 * foreign key, like audit actor columns: the row must stay readable after an
 * account is removed.
 *
 * RETRY-SAFE, STEP BY STEP. The create statement contains only columns and the
 * primary key. The employee index and foreign key are separate guarded steps,
 * so a cron run killed after the table create resumes cleanly. ROW_FORMAT is
 * restated on every retry for MariaDB/MySQL. down() drops the table if it
 * exists, taking the index and key with it.
 *
 * ROLLBACK LOSES the employment timeline rows. The live employee snapshot in
 * `employee_profiles` is not changed by rollback.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('employee_employment_histories')) {
            Schema::create('employee_employment_histories', function (Blueprint $t): void {
                $t->engine = 'InnoDB';

                $t->char('id', 26)->primary();
                $t->char('employee_id', 26);
                $t->string('event_type', 40);
                $t->date('effective_date');
                $t->string('changed_fields', 500)->nullable();

                $t->string('position', 120)->nullable();
                $t->string('position_level', 60)->nullable();
                $t->string('employment_status', 60)->nullable();
                $t->char('work_site_id', 26)->nullable();
                $t->date('date_hired')->nullable();
                $t->date('date_regular')->nullable();
                $t->date('date_resigned')->nullable();
                $t->boolean('is_active')->default(true);
                $t->decimal('monthly_rate', 12, 2)->default(0);
                $t->decimal('daily_rate', 12, 2)->nullable();

                $t->unsignedBigInteger('recorded_by')->nullable();
                $t->string('recorded_by_name', 200)->nullable();
                $t->dateTime('created_at', 3);
            });
        }

        if (! Schema::hasIndex('employee_employment_histories', 'eehist_employee_created_idx')) {
            Schema::table('employee_employment_histories', function (Blueprint $t): void {
                $t->index(['employee_id', 'created_at'], 'eehist_employee_created_idx');
            });
        }

        if (! Schema::hasForeignKey('employee_employment_histories', ['employee_id'])) {
            Schema::table('employee_employment_histories', function (Blueprint $t): void {
                $t->foreign('employee_id')->references('id')->on('employee_profiles')->cascadeOnDelete();
            });
        }

        $this->dynamicRowFormat('employee_employment_histories');
    }

    public function down(): void
    {
        if (Schema::hasTable('employee_employment_histories')) {
            Schema::drop('employee_employment_histories');
        }
    }

    private function dynamicRowFormat(string ...$tables): void
    {
        if (! in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }

        foreach ($tables as $table) {
            DB::statement("ALTER TABLE `{$table}` ROW_FORMAT=DYNAMIC");
        }
    }
};
