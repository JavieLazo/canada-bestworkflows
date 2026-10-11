<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The SECOND employee identifier — the one a person is given on paper.
 *
 * `employee_code` is minted by the system (COP-00001, NOT NULL, UNIQUE) and is
 * the index every list has always sorted and searched on. It cannot also be the
 * company's own employee number, because that number is assigned outside this
 * system, arrives late, and some staff never get one. So this column holds the
 * HAND-TYPED id and is NULLABLE; `employee_code` stays the identifier that is
 * always there.
 *
 * NOT NAMED `employee_id`. That name is the ULID FOREIGN KEY to this table on
 * users, shifts, attendance_logs, leave_travel_requests, payslips and a dozen
 * more, and it is what `ScopesToActor` narrows on. A column of that name here
 * would read as "this row's own foreign key" at every call site.
 *
 * UNIQUE, AND NULLABLE AT THE SAME TIME. InnoDB (MariaDB 11.4 and MySQL 8) and
 * SQLite all treat NULLs as distinct under a unique index, so any number of
 * employees may have none while no two may share one — the precedent is
 * `notifications.dedupe_key`. MariaDB has no partial index and the generated
 * column workaround is banned here, so this is the only shape available. Note
 * the collation is utf8mb4_unicode_ci, making the uniqueness case- AND
 * accent-insensitive: "ab-1" and "AB-1" are the same id on the host even though
 * SQLite's BINARY default would let both through in a test.
 *
 * RETRY-SAFE, STEP BY STEP. A cron run killed between the two statements
 * resumes cleanly: the column and the index are guarded separately because
 * ADD COLUMN is instant on MariaDB 11.4 while building the unique index is a
 * second statement that can be interrupted on its own. The suffix migration
 * (2026_09_06_0001) is NOT a template for this — it guards nothing.
 *
 * ROW SIZE. The ledger the create migration keeps (2026_09_01_0004) is 7,703
 * bytes declared under ROW_FORMAT=COMPACT, which the suffix column took to
 * 7,784, against the binding 8,126-byte half-page limit. utf8mb4 VARCHAR(50)
 * adds 50 x 4 + 1 = 201, reaching 7,985 and leaving 141. The table is actually
 * created ROW_FORMAT=DYNAMIC (2026_09_01_0004 sets it), where long columns go
 * off-page, so that figure is a conservative budget rather than a measurement —
 * but it is the budget this repo holds itself to, and the next column added
 * here has very little of it left.
 *
 * ROLLBACK LOSES every hand-typed employee number. There is nothing to
 * recompute them from: the system never generated them.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('employee_profiles', 'employee_id_no')) {
            Schema::table('employee_profiles', function (Blueprint $t): void {
                $t->string('employee_id_no', 50)->nullable()->after('employee_code');
            });
        }

        if (! Schema::hasIndex('employee_profiles', 'emp_employee_id_no_unq')) {
            Schema::table('employee_profiles', function (Blueprint $t): void {
                $t->unique('employee_id_no', 'emp_employee_id_no_unq');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasIndex('employee_profiles', 'emp_employee_id_no_unq')) {
            Schema::table('employee_profiles', function (Blueprint $t): void {
                $t->dropUnique('emp_employee_id_no_unq');
            });
        }

        if (Schema::hasColumn('employee_profiles', 'employee_id_no')) {
            Schema::table('employee_profiles', function (Blueprint $t): void {
                $t->dropColumn('employee_id_no');
            });
        }
    }
};
