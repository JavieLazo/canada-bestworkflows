<?php

declare(strict_types=1);

use App\Models\EmployeeProfile;
use App\Models\InventoryItem;
use App\Models\InventoryReceipt;
use App\Models\InventoryReceiptLine;
use App\Modules\Inventory\Application\InventoryWrite;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/** MariaDB must enforce inventory evidence constraints and resume partially applied DDL. */
function invschemaMigration(): mixed
{
    return require base_path('database/migrations/2026_10_08_0001_create_inventory_tables.php');
}

function invschemaChecks(string $table, string $name): int
{
    $row = DB::selectOne("SELECT COUNT(*) AS n FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ? AND CONSTRAINT_TYPE = 'CHECK'", [$table, $name]);

    return (int) ($row->n ?? 0);
}

function invschemaEmployee(): string
{
    $row = new EmployeeProfile;
    $row->forceFill(['employee_code' => (string) random_int(10000, 99999), 'first_name' => 'Synthetic', 'last_name' => 'Holder', 'date_hired' => '2024-01-01', 'employment_status' => 'Regular'])->save();

    return $row->id;
}

it('replays inventory DDL twice without duplicates and resumes a missing index and check', function () {
    expect(in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true))->toBeTrue();
    invschemaMigration()->up();
    invschemaMigration()->up();
    expect(DB::table('locks')->where('name', 'copems:inventory')->count())->toBe(1)
        ->and(invschemaChecks('inventory_items', 'invi_custody_chk'))->toBe(1)
        ->and(invschemaChecks('inventory_receipt_lines', 'invrl_ended_chk'))->toBe(1);
    Schema::table('inventory_items', fn ($t) => $t->dropIndex('invi_status_idx'));
    DB::statement('ALTER TABLE inventory_items DROP CONSTRAINT invi_cost_chk');
    invschemaMigration()->up();
    invschemaMigration()->up();
    expect(Schema::hasIndex('inventory_items', 'invi_status_idx'))->toBeTrue()
        ->and(invschemaChecks('inventory_items', 'invi_cost_chk'))->toBe(1)
        ->and(Schema::hasForeignKey('inventory_receipts', ['employee_id']))->toBeTrue();
});

it('rejects invalid inventory states and protects historical employee and item references', function () {
    $employee = invschemaEmployee();
    $item = InventoryItem::query()->create(['code' => '1', 'name' => 'Laptop', 'acquired_on' => '2026-01-01', 'cost' => 100, 'status' => 'IN_STOCK', 'condition' => 'SERVICEABLE']);
    $receipt = new InventoryReceipt;
    $receipt->fill(['code' => '1', 'mr_number' => 'SCHEMA-2026-001', 'employee_id' => $employee, 'issuer_employee_id' => $employee, 'issued_on' => '2026-02-01', 'office_name' => 'Synthetic office', 'employee_snapshot' => ['id' => $employee], 'issuer_snapshot' => ['id' => $employee], 'status' => 'OPEN']);
    $receipt->recorder_name = 'Synthetic actor';
    $receipt->save();
    InventoryReceiptLine::query()->create(['receipt_id' => $receipt->id, 'item_id' => $item->id, 'status' => 'ISSUED', 'item_snapshot' => ['name' => 'Laptop']]);
    expect(fn () => InventoryItem::query()->whereKey($item->id)->update(['status' => 'ISSUED']))->toThrow(QueryException::class, 'invi_custody_chk')
        ->and(fn () => InventoryItem::query()->whereKey($item->id)->update(['cost' => -1]))->toThrow(QueryException::class, 'invi_cost_chk')
        ->and(fn () => InventoryItem::query()->whereKey($item->id)->update(['condition' => 'UNKNOWN']))->toThrow(QueryException::class, 'invi_condition_chk')
        ->and(fn () => InventoryReceiptLine::query()->where('receipt_id', $receipt->id)->update(['status' => 'RETURNED']))->toThrow(QueryException::class, 'invrl_ended_chk')
        ->and(fn () => EmployeeProfile::query()->whereKey($employee)->delete())->toThrow(QueryException::class)
        ->and(fn () => InventoryItem::query()->whereKey($item->id)->delete())->toThrow(QueryException::class)
        ->and(fn () => InventoryReceipt::query()->whereKey($receipt->id)->delete())->toThrow(QueryException::class);
    InventoryItem::query()->whereKey($item->id)->update(['status' => 'ISSUED', 'employee_id' => $employee, 'current_receipt_id' => $receipt->id]);
    expect(InventoryItem::query()->findOrFail($item->id)->employee_id)->toBe($employee);
});

it('holds the inventory sentinel until rollback and refuses a missing sentinel', function () {
    $otherName = 'inventory_schema_connection_'.Str::lower(Str::random(8));
    config(['database.connections.'.$otherName => config('database.connections.'.config('database.default'))]);
    $other = DB::connection($otherName);
    $other->statement('SET SESSION innodb_lock_wait_timeout = 1');
    DB::beginTransaction();
    try {
        InventoryWrite::lock();
        $other->beginTransaction();
        try {
            expect(fn () => $other->table('locks')->where('name', 'copems:inventory')->lockForUpdate()->first())->toThrow(QueryException::class, '1205');
        } finally {
            $other->rollBack();
        }
        DB::rollBack();
        $other->beginTransaction();
        expect($other->table('locks')->where('name', 'copems:inventory')->lockForUpdate()->first())->not->toBeNull();
        $other->rollBack();
        DB::table('locks')->where('name', 'copems:inventory')->delete();
        expect(fn () => DB::transaction(fn () => InventoryWrite::lock()))->toThrow(RuntimeException::class, 'database lock is not ready');
    } finally {
        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        DB::purge($otherName);
        invschemaMigration()->up();
    }
});
