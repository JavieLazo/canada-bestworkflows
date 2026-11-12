<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A LARGE CASH CONVERSION SAYS SO: `leave_cash_outs.large_request` BOOLEAN,
 * default false. True on a request that, when it was made, reached the
 * share of the available balance its leave type asks a reason for
 * (`leave_types.cash_out_reason_share`, LeaveCashOutLimits) — its note is
 * that reason, it may go past the yearly maximum, and the reviewer sees it
 * marked. False on every request made before the column.
 *
 * Appended without ->after(), so MariaDB 11.4 takes ALGORITHM=INSTANT.
 *
 * RETRY-SAFE: the one column stands behind its own guard.
 *
 * ROLLBACK LOSES which requests were large; their notes, days, review and
 * payment stay.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('leave_cash_outs') && ! Schema::hasColumn('leave_cash_outs', 'large_request')) {
            Schema::table('leave_cash_outs', static fn (Blueprint $t) => $t->boolean('large_request')->default(false));
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('leave_cash_outs') && Schema::hasColumn('leave_cash_outs', 'large_request')) {
            Schema::table('leave_cash_outs', static fn (Blueprint $t) => $t->dropColumn('large_request'));
        }
    }
};
