<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * NEW COLUMNS — `employee_profiles.place_of_birth`, `.citizenship` and
 * `.emergency_address`: Place of Birth and Citizenship on the record's
 * Personal Details, and the emergency contact's Address beside their name,
 * relationship and number. No TypeScript counterpart (DELIBERATE DIVERGENCE,
 * 2026-09-30): the reference record carried none of the three.
 *
 * All three are optional, so `VARCHAR NULL` with no default: every existing
 * employee reads NULL ("—" on the page) and there is no backfill. The widths
 * follow the record's own: 120 like a person's name, 60 like a relationship,
 * and 300 exactly like the employee's `address`. Nothing indexes, sorts or
 * joins on them.
 *
 * RETRY-SAFE, STEP BY STEP. Production migrates from a temporary cPanel cron
 * that can die between statements, and MariaDB DDL does not roll back, so
 * each column is added by its own ALTER behind its own `hasColumn` guard: a
 * run killed after the first ALTER resumes with the other two. `down()` drops
 * them the same way, one guarded ALTER each. The columns are appended without
 * `->after()`, which keeps every ADD instant on MariaDB 11.4 instead of
 * rebuilding the table.
 *
 * ROW SIZE. The COMPACT ledger the earlier migrations on this table keep
 * (7,979 bytes after 2026_09_25_0002) is no longer a real bound: measured on
 * MariaDB 11.4.13 on 2026-09-30, the table AS IT STOOD BEFORE THIS MIGRATION
 * is already refused `ROW_FORMAT=COMPACT` (error 1118, row size too large).
 * The table depends on the DYNAMIC format 2026_09_01_0004 gives it, which is
 * also MariaDB 11.4's default; under DYNAMIC with innodb_strict_mode on, the
 * three columns add cleanly (same measurement). A restore must therefore keep
 * ROW_FORMAT=DYNAMIC or land on a server whose default is DYNAMIC.
 *
 * ROLLBACK LOSES every place of birth, citizenship and emergency address
 * entered since: nothing else in the system holds them.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('employee_profiles', 'place_of_birth')) {
            Schema::table('employee_profiles', function (Blueprint $t): void {
                $t->string('place_of_birth', 120)->nullable();
            });
        }

        if (! Schema::hasColumn('employee_profiles', 'citizenship')) {
            Schema::table('employee_profiles', function (Blueprint $t): void {
                $t->string('citizenship', 60)->nullable();
            });
        }

        if (! Schema::hasColumn('employee_profiles', 'emergency_address')) {
            Schema::table('employee_profiles', function (Blueprint $t): void {
                $t->string('emergency_address', 300)->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('employee_profiles', 'emergency_address')) {
            Schema::table('employee_profiles', function (Blueprint $t): void {
                $t->dropColumn('emergency_address');
            });
        }

        if (Schema::hasColumn('employee_profiles', 'citizenship')) {
            Schema::table('employee_profiles', function (Blueprint $t): void {
                $t->dropColumn('citizenship');
            });
        }

        if (Schema::hasColumn('employee_profiles', 'place_of_birth')) {
            Schema::table('employee_profiles', function (Blueprint $t): void {
                $t->dropColumn('place_of_birth');
            });
        }
    }
};
