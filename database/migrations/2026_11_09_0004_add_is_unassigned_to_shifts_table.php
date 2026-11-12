<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A DEFAULT SHIFT CAN BE CLEARED: `shifts.is_unassigned` BOOLEAN, default
 * false. A row with it set says "no default shift from `effective_from`" —
 * the Shift Roster's Clear shift (`ClearShift`), recorded as dated history
 * like every other schedule change, so the dates before it keep the shift
 * they were judged by. Such a row has no pattern and carries the default
 * filler hours only because the hour columns are NOT NULL; `RosterVersions`
 * reads it as no shift, never as those hours. False on every row before the
 * column, so no date moves when this runs.
 *
 * No CHECK ties it to `shift_type_id`: MariaDB 11.4 refuses a CHECK naming a
 * column whose foreign key is ON DELETE SET NULL (error 1901). `ClearShift`
 * writes the link empty, and the pattern-edit copy only follows a link.
 *
 * Appended without ->after(), so MariaDB 11.4 takes ALGORITHM=INSTANT.
 *
 * RETRY-SAFE: the one column stands behind its own guard.
 *
 * ROLLBACK IS REFUSED once any row is unassigned, a cancelled clearing
 * included: without the column such a row would read as an assigned
 * 8:00 AM–5:00 PM shift — silently re-rostering the employee on the dates it
 * governs, and naming those hours in the history of a cancelled one. Roll
 * back only before a shift is first cleared.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('shifts') && ! Schema::hasColumn('shifts', 'is_unassigned')) {
            Schema::table('shifts', static fn (Blueprint $t) => $t->boolean('is_unassigned')->default(false));
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('shifts') || ! Schema::hasColumn('shifts', 'is_unassigned')) {
            return;
        }
        if (DB::table('shifts')->where('is_unassigned', true)->exists()) {
            throw new RuntimeException('shifts holds cleared default shifts (is_unassigned); dropping the column would turn each into an assigned 8:00 AM–5:00 PM shift.');
        }
        Schema::table('shifts', static fn (Blueprint $t) => $t->dropColumn('is_unassigned'));
    }
};
