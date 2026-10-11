<?php

declare(strict_types=1);

use App\Domain\Enums\ModuleCode;
use App\Models\AuditLog;
use App\Models\EmployeeProfile;
use App\Models\InventoryItem;
use App\Models\InventoryMovement;
use App\Models\InventoryReceipt;
use App\Models\Role;
use App\Models\RoleModuleGrant;
use App\Models\User;
use App\Modules\Inventory\Application\Commands\CorrectReceipt;
use App\Modules\Inventory\Application\Commands\IssueItems;
use App\Modules\Inventory\Application\Commands\SaveItem;
use App\Modules\Inventory\Application\Commands\TransferItems;
use App\Modules\Inventory\Application\Queries\InventoryRecords;
use App\Support\Access;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/** Entered MR numbers are unique evidence references, independent of generated record codes. */
function invnumberActor(bool $all = true, ?string $employeeId = null): Access
{
    $role = Role::query()->create(['name' => 'MR numbers '.Str::random(8), 'all_data' => $all, 'is_system' => false]);
    $grant = new RoleModuleGrant;
    $grant->forceFill(['role_id' => $role->id, 'module' => ModuleCode::INVENTORY])->save();
    $user = User::factory()->create(['role_id' => $role->id, 'employee_id' => $employeeId]);
    test()->actingAs($user);
    app()->forgetInstance(Access::class);

    return Access::forActor($user);
}

function invnumberEmployee(string $name): string
{
    $employee = new EmployeeProfile;
    $employee->forceFill(['employee_code' => (string) (EmployeeProfile::query()->count() + 1), 'first_name' => $name, 'last_name' => 'Holder', 'date_hired' => '2024-01-01', 'employment_status' => 'Regular', 'is_active' => true])->save();

    return $employee->id;
}

function invnumberItem(Access $actor, string $name = 'Laptop'): string
{
    return app(SaveItem::class)->handle($actor, null, ['code' => 'MR-ITEM-'.Str::random(8), 'name' => $name, 'acquired_on' => '2026-01-01', 'cost' => 100, 'serial_no' => null, 'category_id' => null, 'location_id' => null, 'remarks' => '']);
}

it('requires a bounded MR number for every issuance path before changing evidence', function () {
    $actor = invnumberActor();
    $employee = invnumberEmployee('First');
    $other = invnumberEmployee('Second');
    $held = invnumberItem($actor);
    $stock = invnumberItem($actor, 'Stock');
    $receipt = app(IssueItems::class)->handle($actor, [$held], $employee, $employee, '2026-02-01', 'MR-ORIGINAL');
    $before = [InventoryReceipt::query()->count(), InventoryMovement::query()->count(), AuditLog::query()->count()];

    foreach (['', '   ', str_repeat('X', 81)] as $number) {
        foreach ([
            fn () => app(IssueItems::class)->handle($actor, [$stock], $employee, $employee, '2026-03-01', $number),
            fn () => app(TransferItems::class)->handle($actor, [$held], $other, $employee, '2026-03-01', $number),
            fn () => app(CorrectReceipt::class)->handle($actor, $receipt, $other, $employee, '2026-03-01', $number, 'Clerical correction'),
        ] as $write) {
            expect($write)->toThrow(RuntimeException::class, 'Enter an MR No. of at most 80 characters.');
        }
    }
    expect([InventoryReceipt::query()->count(), InventoryMovement::query()->count(), AuditLog::query()->count()])->toBe($before)
        ->and(InventoryItem::query()->findOrFail($held)->current_receipt_id)->toBe($receipt)
        ->and(InventoryItem::query()->findOrFail($stock)->status)->toBe('IN_STOCK');
});

it('retains entered formatting while the record code continues its independent sequence', function () {
    $actor = invnumberActor();
    $employee = invnumberEmployee('Holder');
    $a = invnumberItem($actor, 'A');
    $b = invnumberItem($actor, 'B');
    $first = app(IssueItems::class)->handle($actor, [$a], $employee, $employee, '2026-02-01', '  009000/Office-A  ');
    $second = app(IssueItems::class)->handle($actor, [$b], $employee, $employee, '2026-02-01', str_repeat('N', 80));
    $records = app(InventoryRecords::class);

    expect($records->receipt($actor, $first)->mr_number)->toBe('009000/Office-A')
        ->and($records->receipt($actor, $first)->code)->toBe('1')
        ->and($records->receipt($actor, $second)->code)->toBe('2')
        ->and($records->receipt($actor, $second)->mr_number)->toBe(str_repeat('N', 80))
        ->and($records->item($actor, $a)->current_receipt_code)->toBe('1')
        ->and($records->item($actor, $a)->current_receipt_mr_number)->toBe('009000/Office-A')
        ->and($records->movements($actor, $a)->first()->receipt_code)->toBe('1')
        ->and($records->movements($actor, $a)->first()->receipt_mr_number)->toBe('009000/Office-A');
});

it('rejects duplicate MR numbers atomically across issue transfer and replacement', function () {
    $actor = invnumberActor();
    $employee = invnumberEmployee('First');
    $other = invnumberEmployee('Second');
    $held = invnumberItem($actor);
    $stock = invnumberItem($actor, 'Stock');
    $receipt = app(IssueItems::class)->handle($actor, [$held], $employee, $employee, '2026-02-01', 'Office/0007');
    $paper = InventoryReceipt::query()->with('lines')->findOrFail($receipt)->toArray();
    $before = [InventoryReceipt::query()->count(), InventoryMovement::query()->count(), AuditLog::query()->count()];

    foreach ([
        fn () => app(IssueItems::class)->handle($actor, [$stock], $employee, $employee, '2026-03-01', ' Office/0007 '),
        fn () => app(TransferItems::class)->handle($actor, [$held], $other, $employee, '2026-03-01', 'Office/0007'),
        fn () => app(CorrectReceipt::class)->handle($actor, $receipt, $other, $employee, '2026-03-01', 'Office/0007', 'Clerical correction'),
    ] as $write) {
        expect($write)->toThrow(RuntimeException::class, 'That MR No. is already in use.');
    }
    expect([InventoryReceipt::query()->count(), InventoryMovement::query()->count(), AuditLog::query()->count()])->toBe($before)
        ->and(InventoryReceipt::query()->with('lines')->findOrFail($receipt)->toArray())->toBe($paper)
        ->and(InventoryItem::query()->findOrFail($stock)->status)->toBe('IN_STOCK');
});

it('preserves original MR numbers and exposes the distinct replacement number', function () {
    $actor = invnumberActor();
    $from = invnumberEmployee('First');
    $to = invnumberEmployee('Second');
    $item = invnumberItem($actor);
    $original = app(IssueItems::class)->handle($actor, [$item], $from, $from, '2026-02-01', 'MR-OLD');
    $records = app(InventoryRecords::class);
    $snapshot = $records->receipt($actor, $original)->employee_snapshot;
    $replacement = app(CorrectReceipt::class)->handle($actor, $original, $to, $from, '2026-02-01', 'MR-REPLACEMENT', 'Wrong recipient');

    expect($records->receipt($actor, $original)->mr_number)->toBe('MR-OLD')
        ->and($records->receipt($actor, $original)->employee_snapshot)->toBe($snapshot)
        ->and($records->receipt($actor, $original)->corrected_to_code)->toBe('2')
        ->and($records->receipt($actor, $original)->corrected_to_mr_number)->toBe('MR-REPLACEMENT')
        ->and($records->receipt($actor, $replacement)->mr_number)->toBe('MR-REPLACEMENT');
    $transfer = app(TransferItems::class)->handle($actor, [$item], $from, $from, '2026-03-01', 'MR-TRANSFER');
    expect($records->receipt($actor, $replacement)->mr_number)->toBe('MR-REPLACEMENT')
        ->and($records->receipt($actor, $transfer)->code)->toBe('3')
        ->and($records->item($actor, $item)->current_receipt_mr_number)->toBe('MR-TRANSFER');
});

it('searches and sorts MR numbers only within the authorized receipt scope', function () {
    $manager = invnumberActor();
    $own = invnumberEmployee('Own');
    $other = invnumberEmployee('Other');
    $mine = app(IssueItems::class)->handle($manager, [invnumberItem($manager, 'A')], $own, $other, '2026-02-01', 'Z-OFFICE-001');
    $theirs = app(IssueItems::class)->handle($manager, [invnumberItem($manager, 'B')], $other, $other, '2026-02-01', 'A-OFFICE-001');
    $records = app(InventoryRecords::class);
    expect($records->receipts($manager, sort: 'mr_number')->pluck('id')->all())->toBe([$theirs, $mine])
        ->and($records->receipts($manager, sort: 'code')->pluck('id')->all())->toBe([$mine, $theirs]);
    $reader = invnumberActor(false, $own);
    expect($records->receipts($reader, ['search' => 'OFFICE-001'])->pluck('id')->all())->toBe([$mine])
        ->and($records->receipts($reader, ['search' => 'A-OFFICE'])->total())->toBe(0)
        ->and($records->receipt($reader, $theirs))->toBeNull();
    $unlinked = invnumberActor(false);
    expect($records->receipts($unlinked, ['search' => 'OFFICE'])->total())->toBe(0);
});

it('backfills legacy receipt codes once and retains entered numbers on migration retries', function () {
    $migration = require base_path('database/migrations/2026_10_08_0004_add_mr_number_to_inventory_receipts_table.php');
    $mysql = in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true);
    $employee = null;
    $receipt = null;
    try {
        $migration->down();
        if ($mysql) {
            DB::connection()->getPdo()->beginTransaction();
        }
        $employee = invnumberEmployee('Legacy');
        $receipt = new InventoryReceipt;
        $receipt->forceFill(['code' => '42', 'employee_id' => $employee, 'issuer_employee_id' => $employee, 'issued_on' => '2026-02-01', 'office_name' => 'Legacy office', 'employee_snapshot' => ['name' => 'Original holder'], 'issuer_snapshot' => ['name' => 'Original issuer'], 'status' => 'OPEN', 'recorder_name' => 'Synthetic actor'])->save();
        $migration->up();
        $migration->up();
        expect($receipt->fresh()->mr_number)->toBe('42')
            ->and(Schema::hasIndex('inventory_receipts', 'invr_mr_number_unq'))->toBeTrue();
        $receipt->refresh()->forceFill(['mr_number' => 'OFFICE/0042'])->save();
        $migration->up();
        expect($receipt->fresh()->mr_number)->toBe('OFFICE/0042')
            ->and($receipt->fresh()->code)->toBe('42')
            ->and($receipt->fresh()->employee_snapshot)->toBe(['name' => 'Original holder']);
    } finally {
        // MariaDB DDL commits fixtures, so remove only these rows and restore the test transaction.
        if ($mysql && DB::connection()->getPdo()->inTransaction()) {
            DB::connection()->getPdo()->rollBack();
        }
        $migration->up();
        if ($mysql) {
            if ($receipt !== null) {
                InventoryReceipt::query()->whereKey($receipt->id)->delete();
            }
            if ($employee !== null) {
                EmployeeProfile::query()->whereKey($employee)->delete();
            }
            DB::connection()->getPdo()->beginTransaction();
        }
    }
});
