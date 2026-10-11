<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * NEW COLUMNS on `employee_pds_details` (2026_10_07_0001) — they have no
 * counterpart in prisma/schema.prisma.
 *
 * The Personal Data Sheet's family lines and its twelve declarations
 * (DELIBERATE DIVERGENCE: the reference record has none of them):
 *
 *  - the spouse in parts — surname, first name, name extension, middle name,
 *    occupation, employer or business name, business address, telephone;
 *  - the father's name in parts, and the mother's MAIDEN name in parts;
 *  - questions 34 a/b, 35 a/b, 36, 37, 38 a/b, 39 and 40 a/b/c: each answer
 *    is a NULLable boolean followed by the details its "If YES" asks for
 *    (35 b also the date filed and the status of the case, 39 the country,
 *    40 a the group, 40 b and 40 c the ID number).
 *
 * Every column is NULLable with no default. A declaration answer is NULL
 * until someone answers it: NULL is "not answered", 1 Yes, 0 No — never
 * assumed No. There is no CHECK: the answers are booleans and the rest is
 * free text.
 *
 * Columns on this table rather than on `employee_profiles` or a table of
 * their own: the details row is already 1:1 with the employee and loaded
 * with the record, so these columns come back in the query that reads it,
 * and it is written only by the Workforce commands in the transaction that
 * locked the profile row first; `employee_profiles` — its
 * columns, its values and its row-size budget — stays exactly as it is. A
 * missing details row still reads as all blank. The legacy
 * `employee_profiles.spouse_name` and `spouse_employment` keep what they hold
 * and are read only as the fallback while no spouse part is typed.
 *
 * ROW BUDGET. After this migration the table's declared VARCHARs total about
 * 3,950 characters — about 15.8 KB of the 65,535-byte declared-row limit in
 * utf8mb4 — and InnoDB's 8,126-byte row-size check counts about 2.4 KB of it:
 * a VARCHAR under 64 characters at its full width, one of 64 or more as a
 * 20-byte off-page pointer. That is why the name parts are VARCHAR(64). No
 * TEXT or JSON.
 *
 * RETRY-SAFE, STEP BY STEP. Production migrates from a temporary cPanel cron
 * that can die between statements, and MariaDB DDL does not roll back, so
 * every column is its own ALTER behind its own hasColumn() guard: a run
 * killed part way resumes at the next missing column. Each column is
 * appended without `->after()`, which keeps every ADD instant on MariaDB 11.4
 * (the table is ROW_FORMAT=DYNAMIC) instead of rebuilding the table. down()
 * drops them in reverse order, each only while it exists.
 *
 * ROLLBACK LOSES every spouse part, parent's name and declaration typed
 * since: nothing else in the system holds them. The details row itself, its
 * step-one values and the legacy `employee_profiles.spouse_name` /
 * `spouse_employment` are untouched either way.
 */
return new class extends Migration
{
    /**
     * Each column in the order it is appended: its type and, for text, its
     * width. Every declaration answer is followed by its own detail columns.
     *
     * @var array<string, array{0: 'string'|'boolean'|'date', 1: int|null}>
     */
    private const array COLUMNS = [
        'spouse_surname' => ['string', 64],
        'spouse_first_name' => ['string', 64],
        'spouse_middle_name' => ['string', 64],
        // JR., SR., III.
        'spouse_name_extension' => ['string', 10],
        'spouse_occupation' => ['string', 120],
        // "Employer / business name".
        'spouse_employer' => ['string', 150],
        'spouse_business_address' => ['string', 255],
        'spouse_telephone' => ['string', 40],

        'father_surname' => ['string', 64],
        'father_first_name' => ['string', 64],
        'father_middle_name' => ['string', 64],
        'father_name_extension' => ['string', 10],

        // The mother's MAIDEN name.
        'mother_surname' => ['string', 64],
        'mother_first_name' => ['string', 64],
        'mother_middle_name' => ['string', 64],

        // NULL not answered, 1 Yes, 0 No — each answer, then its details.
        'decl_34a' => ['boolean', null],
        'decl_34a_details' => ['string', 255],
        'decl_34b' => ['boolean', null],
        'decl_34b_details' => ['string', 255],
        'decl_35a' => ['boolean', null],
        'decl_35a_details' => ['string', 255],
        'decl_35b' => ['boolean', null],
        'decl_35b_details' => ['string', 255],
        'decl_35b_date_filed' => ['date', null],
        // "Status of case/s".
        'decl_35b_case_status' => ['string', 255],
        'decl_36' => ['boolean', null],
        'decl_36_details' => ['string', 255],
        'decl_37' => ['boolean', null],
        'decl_37_details' => ['string', 255],
        'decl_38a' => ['boolean', null],
        'decl_38a_details' => ['string', 255],
        'decl_38b' => ['boolean', null],
        'decl_38b_details' => ['string', 255],
        'decl_39' => ['boolean', null],
        'decl_39_country' => ['string', 60],
        'decl_40a' => ['boolean', null],
        // The indigenous group.
        'decl_40a_specify' => ['string', 120],
        'decl_40b' => ['boolean', null],
        // PWD ID no.
        'decl_40b_id_no' => ['string', 40],
        'decl_40c' => ['boolean', null],
        // Solo parent ID no.
        'decl_40c_id_no' => ['string', 40],
    ];

    public function up(): void
    {
        // One guarded ALTER per column, never one ALTER for all of them: a run
        // killed between two columns resumes at the first one still missing.
        foreach (self::COLUMNS as $column => [$type, $length]) {
            if (Schema::hasColumn('employee_pds_details', $column)) {
                continue;
            }

            Schema::table('employee_pds_details', function (Blueprint $t) use ($column, $type, $length): void {
                match ($type) {
                    'string' => $t->string($column, $length)->nullable(),
                    'boolean' => $t->boolean($column)->nullable(),
                    'date' => $t->date($column)->nullable(),
                };
            });
        }
    }

    public function down(): void
    {
        foreach (array_reverse(array_keys(self::COLUMNS)) as $column) {
            if (! Schema::hasColumn('employee_pds_details', $column)) {
                continue;
            }

            Schema::table('employee_pds_details', function (Blueprint $t) use ($column): void {
                $t->dropColumn($column);
            });
        }
    }
};
