<?php

declare(strict_types=1);

use App\Domain\Enums\ModuleCode;
use App\Modules\Timekeeping\Application\LeaveFormFreeze;
use App\Support\Access;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Once;

/**
 * FREEZES THE LEAVES ALREADY APPROVED. What their forms said when they were
 * approved was never stored, so each one is frozen as it reads now and stops
 * changing from here on. Approved and cancelled requests are covered, a
 * cancelled one having been approved first; pending, disapproved and
 * withdrawn requests are left alone.
 *
 * The app's own `LeaveFormFreeze` is what writes the form, so a backfilled
 * record has the same shape as a new one. It runs as a system actor holding
 * all-data LEAVES, because no one is signed in here, and it is reached only
 * when a row needs it, so a fresh database never calls it.
 *
 * RETRY-SAFE: only rows still without a form are read, and the write repeats
 * that condition, so a killed or overlapping run freezes each row once. One
 * row that cannot be frozen is logged and skipped, and keeps reading as the
 * records stand; the next run tries it again.
 *
 * ROLLBACK does nothing here. `2026_11_11_0006` drops the column.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('leave_travel_requests', 'approved_form')) {
            return;
        }
        $ids = DB::table('leave_travel_requests')->whereIn('status', ['APPROVED', 'CANCELLED'])->whereNull('approved_form')->orderBy('id')->pluck('id')->all();
        if ($ids === []) {
            return;
        }
        // Earlier migrations in this run may have asked about columns that exist now.
        Once::flush();
        $system = new Access('0', null, 'System', 'System', true, [ModuleCode::LEAVES]);
        foreach ($ids as $id) {
            try {
                app(LeaveFormFreeze::class)->capture($system, (string) $id);
            } catch (Throwable $e) {
                Log::error("Leave request {$id}: its approved form could not be frozen: {$e->getMessage()}");
            }
        }
    }

    public function down(): void {}
};
