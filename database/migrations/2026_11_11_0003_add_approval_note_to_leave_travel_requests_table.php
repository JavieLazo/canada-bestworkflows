<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * THE APPLICATION'S "APPROVED FOR: OTHERS (SPECIFY)" LINE. A leave manager
 * approving a leave may add an optional note; `ReviewAbsence` writes it with
 * the decision and the filed form shows it on section 7.C. It is recorded
 * only and changes no pay or credits. NULL on every other row, and on every
 * request approved before this column.
 *
 * Appended without ->after(), so MariaDB 11.4 takes ALGORITHM=INSTANT.
 *
 * RETRY-SAFE: the column stands behind its own guard.
 *
 * ROLLBACK LOSES every note given: the requests stay approved as they were.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('leave_travel_requests') && ! Schema::hasColumn('leave_travel_requests', 'approval_note')) {
            Schema::table('leave_travel_requests', static fn (Blueprint $t) => $t->string('approval_note', 255)->nullable());
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('leave_travel_requests') && Schema::hasColumn('leave_travel_requests', 'approval_note')) {
            Schema::table('leave_travel_requests', static fn (Blueprint $t) => $t->dropColumn('approval_note'));
        }
    }
};
