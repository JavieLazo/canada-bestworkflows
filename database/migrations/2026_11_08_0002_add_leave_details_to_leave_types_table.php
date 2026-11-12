<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * WHICH "DETAILS OF LEAVE" A LEAVE TYPE ASKS FOR, set by HR on Leave
 * Settings (the leave application's section 6.B):
 *
 *  - `leave_details` VARCHAR(8): NONE (asks nothing — the default),
 *    VACATION (Vacation / Special Privilege Leave: within the Philippines
 *    or abroad), SICK (in hospital or out patient, with the illness), WOMEN
 *    (Special Leave Benefits for Women: the illness) or STUDY (completion
 *    of a master's degree or a BAR/Board examination review).
 *    `lt_leave_details_chk` pins the five values.
 *
 * The types already set up start from their names, the first match
 * winning: "special leave benefit" → WOMEN, "study" → STUDY, "sick" →
 * SICK, "vacation" or "special privilege" → VACATION; every other type
 * stays NONE — a bare "women" is not matched, so a leave for victims of
 * violence against women is never made to name an illness. Only a row still at NONE is matched, so HR's later
 * choice is never overwritten. A type created after this runs starts at
 * NONE until HR picks. Appended without ->after(), so MariaDB 11.4 takes
 * ALGORITHM=INSTANT.
 *
 * RETRY-SAFE: the column, each backfill UPDATE (it matches nothing the
 * second time) and the CHECK stand behind their own guards.
 *
 * ROLLBACK LOSES which details each type asks for; requests already filed
 * keep the details they were filed with.
 */
return new class extends Migration
{
    /** Name patterns in the order they are tried, each => the section it starts a type on. */
    private const array BY_NAME = [
        'WOMEN' => ['%special leave benefit%'],
        'STUDY' => ['%study%'],
        'SICK' => ['%sick%'],
        'VACATION' => ['%vacation%', '%special privilege%'],
    ];

    public function up(): void
    {
        if (! Schema::hasTable('leave_types')) {
            return;
        }
        if (! Schema::hasColumn('leave_types', 'leave_details')) {
            Schema::table('leave_types', static fn (Blueprint $t) => $t->string('leave_details', 8)->default('NONE'));
        }
        foreach (self::BY_NAME as $section => $patterns) {
            foreach ($patterns as $pattern) {
                DB::table('leave_types')->where('leave_details', 'NONE')->whereRaw('LOWER(name) LIKE ?', [$pattern])->update(['leave_details' => $section]);
            }
        }
        if ($this->isMySql() && ! $this->hasCheck('lt_leave_details_chk')) {
            DB::statement("ALTER TABLE `leave_types` ADD CONSTRAINT `lt_leave_details_chk` CHECK (`leave_details` IN ('NONE','VACATION','SICK','WOMEN','STUDY'))");
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('leave_types')) {
            return;
        }
        if ($this->isMySql() && $this->hasCheck('lt_leave_details_chk')) {
            DB::statement('ALTER TABLE `leave_types` DROP CONSTRAINT `lt_leave_details_chk`');
        }
        if (Schema::hasColumn('leave_types', 'leave_details')) {
            Schema::table('leave_types', static fn (Blueprint $t) => $t->dropColumn('leave_details'));
        }
    }

    private function hasCheck(string $name): bool
    {
        $row = DB::selectOne(
            "SELECT COUNT(*) AS n FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'leave_types' AND CONSTRAINT_NAME = ? AND CONSTRAINT_TYPE = 'CHECK'",
            [$name],
        );

        return (int) ($row->n ?? 0) > 0;
    }

    private function isMySql(): bool
    {
        return in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true);
    }
};
