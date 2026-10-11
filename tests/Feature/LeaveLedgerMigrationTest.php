<?php

declare(strict_types=1);

use App\Models\EmployeeProfile;
use App\Models\LeaveCredit;
use App\Models\LeaveCreditEntry;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/** Ledger migrations preserve allocations exactly, resume missing steps and do not invent past accruals. */
function ledgerMigrationEmployee(): EmployeeProfile
{
    $employee = new EmployeeProfile;
    $employee->forceFill(['employee_code' => 'LGM-'.Str::random(10), 'first_name' => 'Ledger', 'last_name' => 'Migration', 'date_hired' => '2024-01-01', 'is_active' => true])->save();

    return $employee;
}

it('creates the ledger and lock repeatedly and recovers an interrupted index step', function () {
    $create = require base_path('database/migrations/2026_10_02_0005_create_leave_credit_entries_table.php');
    try {
        $create->up();
        $create->up();
        expect(Schema::hasIndex('leave_credit_entries', 'lce_employee_year_type_idx'))->toBeTrue()
            ->and(Schema::hasForeignKey('leave_credit_entries', ['employee_id']))->toBeTrue()
            ->and(DB::table('locks')->where('name', 'copems:leave-credits')->count())->toBe(1);
        Schema::table('leave_credit_entries', static function (Blueprint $table): void {
            $table->dropIndex('lce_recorded_by_idx');
        });
        DB::table('locks')->where('name', 'copems:leave-credits')->delete();
        $create->up();
        $create->up();
        expect(Schema::hasIndex('leave_credit_entries', 'lce_recorded_by_idx'))->toBeTrue()
            ->and(DB::table('locks')->where('name', 'copems:leave-credits')->count())->toBe(1);
    } finally {
        $create->up();
    }
});

it('backfills exact original allocations once and retains their baselines on backfill rollback', function () {
    test()->travelTo(new DateTimeImmutable('2026-10-02T00:00:00+00:00'));
    $backfill = require base_path('database/migrations/2026_10_02_0006_backfill_leave_credit_entries_table.php');
    $employee = ledgerMigrationEmployee();
    try {
        $credit = new LeaveCredit;
        $credit->forceFill(['employee_id' => $employee->id, 'year' => 2025, 'request_type' => 'RETIRED_TYPE', 'credits' => 7.25, 'created_at' => '2025-01-03 02:04:05.123'])->save();
        $backfill->up();
        $backfill->up();
        $entry = LeaveCreditEntry::query()->where('employee_id', $employee->id)->sole();
        expect($entry->id)->toBe($credit->id)->and($entry->year)->toBe(2025)
            ->and($entry->days)->toBe(7.25)->and($entry->kind)->toBe('BASELINE')
            ->and($entry->note)->toBe('Existing annual allocation; earlier movements were not recorded.')
            ->and($entry->recorded_by)->toBeNull()->and($entry->source_year)->toBeNull()
            ->and($entry->created_at->format('Y-m-d H:i:s.v'))->toBe('2026-10-02 00:00:00.000')
            ->and($credit->fresh()->credits)->toBe(7.25);
        $backfill->down();
        expect(LeaveCreditEntry::query()->whereKey($entry->id)->exists())->toBeTrue();
        $credit->delete();
        expect(LeaveCreditEntry::query()->whereKey($entry->id)->exists())->toBeTrue();
    } finally {
        EmployeeProfile::query()->whereKey($employee->id)->delete();
    }
});

it('rolls back only the ledger table then recreates and re-baselines the unchanged allocation', function () {
    $create = require base_path('database/migrations/2026_10_02_0005_create_leave_credit_entries_table.php');
    $backfill = require base_path('database/migrations/2026_10_02_0006_backfill_leave_credit_entries_table.php');
    $employee = ledgerMigrationEmployee();
    try {
        $credit = new LeaveCredit;
        $credit->forceFill(['employee_id' => $employee->id, 'year' => 2026, 'request_type' => 'SICK_LEAVE', 'credits' => 0])->save();
        $backfill->up();
        $create->down();
        $create->down();
        expect(Schema::hasTable('leave_credit_entries'))->toBeFalse()
            ->and($credit->fresh()->credits)->toBe(0.0);
        $create->up();
        $create->up();
        $backfill->up();
        $backfill->up();
        expect(LeaveCreditEntry::query()->where('employee_id', $employee->id)->sole()->days)->toBe(0.0);
    } finally {
        $create->up();
        EmployeeProfile::query()->whereKey($employee->id)->delete();
    }
});
