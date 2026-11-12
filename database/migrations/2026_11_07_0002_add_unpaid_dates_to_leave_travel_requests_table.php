<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A LEAVE APPROVED PARTLY WITH PAY. `ReviewAbsence` pays a request as far
 * as the employee's credits (or the leave type's set days) reach, in date
 * order, and approves the rest of it without pay: `credit_dates` names the
 * paid dates as before, and the new `unpaid_dates` JSON names the dates the
 * approval left without pay. The Daily Time Record and payroll read every
 * date from the first of them on as leave without pay. NULL on every other
 * row: a request paid whole, one without pay whole (`is_paid` false),
 * official business, and every row approved before this column.
 *
 * Appended without ->after(), so MariaDB 11.4 takes ALGORITHM=INSTANT.
 *
 * RETRY-SAFE: the column stands behind its own guard.
 *
 * ROLLBACK LOSES which dates of a partly paid request are without pay:
 * such a request reads as paid whole again, on the DTR and in a payroll not
 * yet finalized. Finalized payslips keep what they captured.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('leave_travel_requests') && ! Schema::hasColumn('leave_travel_requests', 'unpaid_dates')) {
            Schema::table('leave_travel_requests', static fn (Blueprint $t) => $t->json('unpaid_dates')->nullable());
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('leave_travel_requests') && Schema::hasColumn('leave_travel_requests', 'unpaid_dates')) {
            Schema::table('leave_travel_requests', static fn (Blueprint $t) => $t->dropColumn('unpaid_dates'));
        }
    }
};
