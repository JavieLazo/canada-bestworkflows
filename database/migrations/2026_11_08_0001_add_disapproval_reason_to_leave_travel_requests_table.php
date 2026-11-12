<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WHY A REQUEST WAS DISAPPROVED. A leave manager who disapproves a leave or
 * official-business request says why ("Disapproved due to", as on the leave
 * application), and the employee reads it in My Portal. `ReviewAbsence`
 * writes the new `disapproval_reason` with the decision; it is NULL on every
 * other row, and on every request disapproved before this column. The
 * stored status stays `REJECTED`.
 *
 * Appended without ->after(), so MariaDB 11.4 takes ALGORITHM=INSTANT.
 *
 * RETRY-SAFE: the column stands behind its own guard.
 *
 * ROLLBACK LOSES every reason given: the requests stay disapproved, with no
 * reason shown. The notices the employees received keep the reason.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('leave_travel_requests') && ! Schema::hasColumn('leave_travel_requests', 'disapproval_reason')) {
            Schema::table('leave_travel_requests', static fn (Blueprint $t) => $t->string('disapproval_reason', 1000)->nullable());
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('leave_travel_requests') && Schema::hasColumn('leave_travel_requests', 'disapproval_reason')) {
            Schema::table('leave_travel_requests', static fn (Blueprint $t) => $t->dropColumn('disapproval_reason'));
        }
    }
};
