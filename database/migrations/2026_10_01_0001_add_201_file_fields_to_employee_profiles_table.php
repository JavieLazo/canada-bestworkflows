<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\ColumnDefinition;
use Illuminate\Support\Facades\Schema;

/**
 * NEW COLUMNS — the rest of the paper 201 file on `employee_profiles`, so
 * the record can be printed as that sheet (2026-10-01). No TypeScript
 * counterpart (DELIBERATE DIVERGENCE): the reference record carried none of
 * these. In the order the sheet asks for them:
 *
 *  - Personal Details: `telephone` (the landline beside the mobile `phone`),
 *    `religion`, `height_cm` and `weight_kg` (DECIMAL(5,2), so 62.5 kg keeps
 *    its half), `blood_type` (one of the eight ABO/Rh groups, "AB-" at most).
 *  - Family: `spouse_name` and `spouse_employment`. The children are rows of
 *    their own — `employee_children`, 2026_10_01_0002 — because this table
 *    takes no TEXT or JSON column (the database guide's row-format rule).
 *  - Educational Background: `college`, `college_course`,
 *    `college_year_graduated`, `graduate_school`, `graduate_course` and
 *    `graduate_year_graduated`. The years are strings, not INT, because
 *    the sheet takes "N/A" and "2025 (ongoing)" as readily as "2025".
 *  - Statutory & Bank: `bank_name` (the "Bank and Branch" line beside the
 *    SWIFT code and account number). The sheet's license and passport lines
 *    are NOT columns and are not printed: a license or passport is a
 *    document, filed on the Documents card with its number and expiry (the
 *    owner's call).
 *
 * All fourteen are optional, so NULL with no default: every existing
 * employee reads NULL ("—" on the page, a blank line on the sheet) and there
 * is no backfill. Nothing indexes, sorts or joins on any of them.
 *
 * RETRY-SAFE, STEP BY STEP. Production migrates from a temporary cPanel cron
 * that can die between statements, and MariaDB DDL does not roll back, so
 * each column is added by its own ALTER behind its own `hasColumn` guard: a
 * run killed after any ALTER resumes with the columns still missing. `down()`
 * drops them the same way, one guarded ALTER each. The columns are appended
 * without `->after()`, which keeps every ADD instant on MariaDB 11.4 instead
 * of rebuilding the table.
 *
 * ROW SIZE — WHY NOTHING HERE IS NARROWER THAN VARCHAR(64). The table
 * depends on ROW_FORMAT=DYNAMIC (2026_09_01_0004, and MariaDB 11.4's
 * default; see 2026_09_30_0001). InnoDB's row-size check (8,126 bytes, error
 * 1118) counts a VARCHAR under 256 bytes — under VARCHAR(64) in utf8mb4 — at
 * its FULL width, and one of 256 bytes or more as the 20-byte pointer of a
 * value it may store off-page. So a VARCHAR(40) costs this table 161 bytes of
 * its budget and a VARCHAR(64) costs 22, and the telephone, religion and the
 * two years are 64 wide for that reason alone, not because anyone types
 * sixty characters into them. Measured on MariaDB 11.4.13 on 2026-10-01:
 * before this migration the table took thirteen more instant VARCHAR(63)
 * columns before 1118; after it, twelve. With the short ones at
 * their natural widths (40, 60, 20) it took nine, and an instant DROP of the
 * columns in reverse order failed with 1118 partway — instant DROP keeps the
 * dropped columns in the row until a rebuild, and its check is stricter than
 * a rebuild's. tests/Integration/Employee201FieldsSchemaTest replays every
 * ADD with ALGORITHM=INSTANT and the whole down() sequence.
 *
 * ROLLBACK LOSES every value entered in these columns since: nothing else in
 * the system holds them.
 */
return new class extends Migration
{
    /**
     * Column => how to add it. One ALTER each, in this order.
     *
     * @return array<string, Closure(Blueprint): ColumnDefinition>
     */
    private static function columns(): array
    {
        return [
            'telephone' => static fn (Blueprint $t) => $t->string('telephone', 64)->nullable(),
            'religion' => static fn (Blueprint $t) => $t->string('religion', 64)->nullable(),
            'height_cm' => static fn (Blueprint $t) => $t->decimal('height_cm', 5, 2)->nullable(),
            'weight_kg' => static fn (Blueprint $t) => $t->decimal('weight_kg', 5, 2)->nullable(),
            'blood_type' => static fn (Blueprint $t) => $t->string('blood_type', 5)->nullable(),
            'spouse_name' => static fn (Blueprint $t) => $t->string('spouse_name', 120)->nullable(),
            'spouse_employment' => static fn (Blueprint $t) => $t->string('spouse_employment', 120)->nullable(),
            'college' => static fn (Blueprint $t) => $t->string('college', 150)->nullable(),
            'college_course' => static fn (Blueprint $t) => $t->string('college_course', 120)->nullable(),
            'college_year_graduated' => static fn (Blueprint $t) => $t->string('college_year_graduated', 64)->nullable(),
            'graduate_school' => static fn (Blueprint $t) => $t->string('graduate_school', 150)->nullable(),
            'graduate_course' => static fn (Blueprint $t) => $t->string('graduate_course', 120)->nullable(),
            'graduate_year_graduated' => static fn (Blueprint $t) => $t->string('graduate_year_graduated', 64)->nullable(),
            'bank_name' => static fn (Blueprint $t) => $t->string('bank_name', 150)->nullable(),
        ];
    }

    public function up(): void
    {
        foreach (self::columns() as $column => $add) {
            if (Schema::hasColumn('employee_profiles', $column)) {
                continue;
            }

            Schema::table('employee_profiles', function (Blueprint $t) use ($add): void {
                $add($t);
            });
        }
    }

    public function down(): void
    {
        foreach (array_reverse(array_keys(self::columns())) as $column) {
            if (! Schema::hasColumn('employee_profiles', $column)) {
                continue;
            }

            Schema::table('employee_profiles', function (Blueprint $t) use ($column): void {
                $t->dropColumn($column);
            });
        }
    }
};
