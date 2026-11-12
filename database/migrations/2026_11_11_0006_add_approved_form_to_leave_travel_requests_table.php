<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AN APPROVED APPLICATION FOR LEAVE IS A RECORD. `approved_form` (JSON,
 * nullable) holds what the form stated the moment the leave was approved —
 * the applicant's lines, the head of office, the signatories, the leave types
 * listed and the certification of credits — so a later change to any of them
 * never reaches it. `ReviewAbsence` writes it in the approving transaction.
 * NULL on a request not approved, and on every request until
 * `2026_11_11_0007` freezes the ones already approved.
 *
 * Appended without ->after(), so MariaDB 11.4 takes ALGORITHM=INSTANT.
 *
 * RETRY-SAFE: the column stands behind its own guard.
 *
 * ROLLBACK LOSES every frozen form: approved leaves then show the form as
 * the records stand, and what they said when approved cannot be recovered.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('leave_travel_requests') && ! Schema::hasColumn('leave_travel_requests', 'approved_form')) {
            Schema::table('leave_travel_requests', static fn (Blueprint $t) => $t->json('approved_form')->nullable());
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('leave_travel_requests') && Schema::hasColumn('leave_travel_requests', 'approved_form')) {
            Schema::table('leave_travel_requests', static fn (Blueprint $t) => $t->dropColumn('approved_form'));
        }
    }
};
