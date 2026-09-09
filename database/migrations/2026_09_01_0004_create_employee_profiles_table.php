<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Port of `model EmployeeProfile` (prisma/schema.prisma:140-239) — the widest
 * table in the system and the one that will not CREATE if you take Laravel's
 * defaults.
 *
 * The binding row-size limit is 8,126 bytes (half a 16 KB page), NOT 65,535.
 * 32 VARCHARs at Laravel's default 255 is 32 x 255 x 4 + 32 x 2 = 32,704 bytes,
 * which fails under ROW_FORMAT=COMPACT and passes under DYNAMIC only because
 * wide columns go off-page. Every length below comes from src/lib/api/schemas.ts
 * (EmployeeInput), which brings the declared width to ~7.0 KB under DYNAMIC and
 * 7,703 bytes under COMPACT — but only while `photo` stays out of the table as
 * a path. Under COMPACT InnoDB counts a LONGTEXT as 768 bytes of inline prefix
 * plus a 20-byte pointer (788), which would push 7,703 to 8,491 and fail.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_profiles', function (Blueprint $t): void {
            $t->engine = 'InnoDB';

            $t->char('id', 26)->primary();
            $t->string('employee_code', 50)->unique();
            $t->string('first_name', 100);
            $t->string('middle_name', 100)->nullable();
            $t->string('last_name', 100);

            // Free text rather than a foreign key, on purpose: the option lists
            // are user-editable and renaming an option must not orphan a record.
            $t->string('position', 120)->nullable();
            $t->string('position_level', 60)->nullable();

            // A NAME from `employment_statuses` ("Regular"), not a key. "Regular"
            // and "Probationary" carry behaviour beyond their label (isRegular /
            // isProbationary in src/lib/hris.ts), so renaming either of those two
            // silently turns that behaviour off.
            $t->string('employment_status', 60)->default('Probationary');

            $t->boolean('is_active')->default(true);

            // Nullable with SET NULL below, so deleting a work site does not
            // delete its employees. The API still REQUIRES it on write
            // (schemas.ts:21-26). Both are correct; do not "fix" either to match
            // the other.
            $t->char('work_site_id', 26)->nullable();

            // personal — a PATH on the filesystem, never the bytes. The source
            // holds a 3,000,000-char data: URL in a @db.Text column; see the
            // row-size arithmetic in the docblock for why that cannot come here.
            $t->string('photo_path', 255)->nullable();
            $t->date('birth_date')->nullable();
            $t->string('sex', 20)->nullable();
            $t->string('civil_status', 30)->nullable();

            // contact — note utf8mb4_unicode_ci is case- AND accent-insensitive.
            // That is a win on `email` (it replaces the source's three
            // mode:"insensitive" modifiers for free) and irrelevant here because
            // none of these columns is unique.
            $t->string('address', 300)->nullable();
            $t->string('phone', 40)->nullable();
            $t->string('email', 200)->nullable();
            $t->string('residence_cert', 60)->nullable();

            // emergency contact
            $t->string('emergency_name', 120)->nullable();
            $t->string('emergency_relation', 60)->nullable();
            $t->string('emergency_phone', 40)->nullable();

            // employment dates — NO default on date_hired. The source emitted
            // DATE NOT NULL DEFAULT CURRENT_TIMESTAMP, which MySQL rejects
            // outright (CURRENT_TIMESTAMP is legal only on DATETIME/TIMESTAMP).
            // It was never reachable through the API anyway: date_hired is
            // required input.
            $t->date('date_hired');
            $t->date('date_regular')->nullable();
            $t->date('date_resigned')->nullable();

            // compensation — precision is always explicit. A bare decimal($col)
            // is (8,2), max 999,999.99, against an API cap of 99,999,999: under
            // strict mode that is error 1264, and without it a silently clamped
            // payslip that still balances.
            $t->decimal('monthly_rate', 12, 2)->default(0);
            $t->decimal('hourly_rate', 12, 2)->default(0);
            $t->decimal('allowances', 12, 2)->default(0);

            // statutory identifiers
            $t->string('sss_no', 40)->nullable();
            $t->string('philhealth_no', 40)->nullable();
            $t->string('pagibig_no', 40)->nullable();
            $t->string('tin', 40)->nullable();

            // bank
            $t->string('bank_account_no', 60)->nullable();
            $t->string('bank_code', 40)->nullable();
            $t->string('bank_type', 40)->nullable();

            // leave credits — fractional, so not an integer
            $t->decimal('vacation_credits', 6, 2)->default(0);
            $t->decimal('sick_credits', 6, 2)->default(0);

            // payroll grouping codes
            $t->string('pay_code', 40)->nullable();
            $t->string('agency_code', 40)->nullable();
            $t->string('section_code', 40)->nullable();
            $t->string('tax_code', 40)->nullable();

            // contribution switches and overrides — an employee with no override
            // falls back to the statutory table in App\Domain\Payroll\Statutory.
            $t->boolean('allow_sss')->default(true);
            $t->boolean('allow_philhealth')->default(true);
            $t->boolean('allow_pagibig')->default(true);
            $t->boolean('allow_withholding_tax')->default(true);
            $t->boolean('annualize_tax')->default(false);
            $t->decimal('fixed_tax', 12, 2)->default(0);
            $t->decimal('percent_tax', 5, 2)->default(0);
            $t->decimal('fixed_sss_ee', 12, 2)->default(0);
            $t->decimal('fixed_sss_er', 12, 2)->default(0);
            $t->decimal('fixed_philhealth_ee', 12, 2)->default(0);
            $t->decimal('fixed_philhealth_er', 12, 2)->default(0);
            $t->decimal('fixed_pagibig_ee', 12, 2)->default(0);
            $t->decimal('fixed_pagibig_er', 12, 2)->default(0);

            // Free text, NOT enums, matching the source's `String?`:
            // SEMI_MONTHLY | MONTHLY, and null behaves as SEMI_MONTHLY.
            $t->string('sss_mode', 20)->nullable()->default('SEMI_MONTHLY');
            $t->string('philhealth_mode', 20)->nullable()->default('SEMI_MONTHLY');
            $t->string('pagibig_mode', 20)->nullable()->default('SEMI_MONTHLY');

            $t->dateTime('created_at', 3);
            $t->dateTime('updated_at', 3)->nullable();

            $t->index(['is_active', 'last_name'], 'emp_active_lastname_idx');
            $t->index('work_site_id', 'emp_worksite_idx');

            // Declared last: MySQL reuses emp_worksite_idx for the constraint
            // rather than creating a second index of its own.
            $t->foreign('work_site_id')->references('id')->on('work_sites')->nullOnDelete();
        });

        $this->dynamicRowFormat('employee_profiles');
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_profiles');
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
