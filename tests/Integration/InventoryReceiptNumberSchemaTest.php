<?php

declare(strict_types=1);

use App\Models\EmployeeProfile;
use App\Models\InventoryItem;
use App\Models\InventoryReceipt;
use App\Models\InventoryReceiptLine;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/** MariaDB preserves issued evidence while retrying the additive MR-number migration. */
function invnumschemaMigration(): mixed
{
    return require base_path('database/migrations/2026_10_08_0004_add_mr_number_to_inventory_receipts_table.php');
}

function invnumschemaEmployee(): string
{
    $row = new EmployeeProfile;
    $row->forceFill(['employee_code' => 'MR-'.Str::random(12), 'first_name' => 'Synthetic', 'last_name' => 'Holder', 'date_hired' => '2024-01-01', 'employment_status' => 'Regular'])->save();

    return $row->id;
}

function invnumschemaReceipt(string $employee, string $code, ?string $mrNumber = null): InventoryReceipt
{
    $row = new InventoryReceipt;
    $data = ['code' => $code, 'employee_id' => $employee, 'issuer_employee_id' => $employee, 'issued_on' => '2026-02-01', 'office_name' => 'Original office', 'employee_snapshot' => ['name' => 'Original holder'], 'issuer_snapshot' => ['name' => 'Original issuer'], 'status' => 'OPEN', 'recorder_name' => 'Synthetic actor'];
    if ($mrNumber !== null) {
        $data['mr_number'] = $mrNumber;
    }
    $row->forceFill($data)->save();

    return $row;
}

it('backfills legacy MR numbers without changing receipt keys or frozen item lines on MariaDB', function () {
    expect(in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true))->toBeTrue();
    $migration = invnumschemaMigration();
    $migration->down();
    $employee = invnumschemaEmployee();
    $receipt = invnumschemaReceipt($employee, '19');
    $item = InventoryItem::query()->create(['code' => '1', 'name' => 'Laptop', 'acquired_on' => '2026-01-01', 'cost' => 100, 'status' => 'IN_STOCK', 'condition' => 'SERVICEABLE']);
    $line = InventoryReceiptLine::query()->create(['receipt_id' => $receipt->id, 'item_id' => $item->id, 'status' => 'ISSUED', 'item_snapshot' => ['name' => 'Original laptop', 'cost' => 100]]);
    $before = $receipt->fresh()->toArray();
    $lineBefore = $line->fresh()->toArray();
    $migration->up();
    $migration->up();

    expect($receipt->fresh()->mr_number)->toBe('19')
        ->and(Arr::except($receipt->fresh()->toArray(), 'mr_number'))->toBe($before)
        ->and($line->fresh()->toArray())->toBe($lineBefore)
        ->and(InventoryReceipt::query()->count())->toBe(1);
    $receipt->refresh()->forceFill(['mr_number' => 'OFFICE/0019'])->save();
    $migration->up();
    expect($receipt->fresh()->mr_number)->toBe('OFFICE/0019');
});

it('resumes an added nullable MR column and a missing unique index without overwriting copied numbers', function () {
    $migration = invnumschemaMigration();
    $migration->down();
    $employee = invnumschemaEmployee();
    $first = invnumschemaReceipt($employee, '1');
    $second = invnumschemaReceipt($employee, '2');
    Schema::table('inventory_receipts', fn (Blueprint $table) => $table->string('mr_number', 80)->nullable());
    DB::table('inventory_receipts')->where('id', $first->id)->update(['mr_number' => 'Office/001']);
    $migration->up();
    $migration->up();
    expect($first->fresh()->mr_number)->toBe('Office/001')
        ->and($second->fresh()->mr_number)->toBe('2')
        ->and(collect(Schema::getColumns('inventory_receipts'))->firstWhere('name', 'mr_number')['nullable'])->toBeFalse();

    Schema::table('inventory_receipts', fn (Blueprint $table) => $table->dropUnique('invr_mr_number_unq'));
    $migration->up();
    $migration->up();
    expect(Schema::hasIndex('inventory_receipts', 'invr_mr_number_unq'))->toBeTrue()
        ->and(fn () => invnumschemaReceipt($employee, '3', 'Office/001'))->toThrow(QueryException::class)
        ->and(fn () => invnumschemaReceipt($employee, '4', 'office/001'))->toThrow(QueryException::class)
        ->and(fn () => DB::table('inventory_receipts')->where('id', $second->id)->update(['mr_number' => null]))->toThrow(QueryException::class)
        ->and(InventoryReceipt::query()->count())->toBe(2);
});

it('retries rollback safely while retaining the generated record identity and original evidence', function () {
    $migration = invnumschemaMigration();
    $receipt = invnumschemaReceipt(invnumschemaEmployee(), '8', 'OFFICE-0008');
    $before = Arr::except($receipt->fresh()->toArray(), 'mr_number');
    $migration->down();
    $migration->down();
    expect(Schema::hasColumn('inventory_receipts', 'mr_number'))->toBeFalse()
        ->and($receipt->fresh()->toArray())->toBe($before);
    $migration->up();
    $migration->up();
    expect($receipt->fresh()->mr_number)->toBe('8')
        ->and(Arr::except($receipt->fresh()->toArray(), 'mr_number'))->toBe($before);
});
