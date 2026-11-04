<?php

declare(strict_types=1);

use App\Domain\Enums\ModuleCode;
use App\Livewire\Inventory\Support\ItemsExport;
use App\Livewire\Inventory\Support\ReceiptsExport;
use App\Models\AuditLog;
use App\Models\EmployeeProfile;
use App\Models\InventoryCount;
use App\Models\InventoryCountLine;
use App\Models\InventoryItem;
use App\Models\InventoryLocation;
use App\Models\InventoryMovement;
use App\Models\InventoryReceipt;
use App\Models\InventoryReceiptLine;
use App\Models\OrgNode;
use App\Models\Position;
use App\Models\Role;
use App\Models\RoleModuleGrant;
use App\Models\User;
use App\Modules\Inventory\Application\Commands\CorrectReceipt;
use App\Modules\Inventory\Application\Commands\DeleteCatalog;
use App\Modules\Inventory\Application\Commands\DeleteItem;
use App\Modules\Inventory\Application\Commands\ImportItems;
use App\Modules\Inventory\Application\Commands\IssueItems;
use App\Modules\Inventory\Application\Commands\ReturnItems;
use App\Modules\Inventory\Application\Commands\SaveCatalog;
use App\Modules\Inventory\Application\Commands\SaveItem;
use App\Modules\Inventory\Application\Commands\TransferItems;
use App\Modules\Inventory\Application\Queries\InventoryRecords;
use App\Platform\Transfer\ImportRunner;
use App\Support\Access;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** Inventory commands own custody and snapshots; reads never widen an employee's scope. */

/** @param list<string> $modules */
function invcoreActor(array $modules = [ModuleCode::INVENTORY], bool $all = true, ?string $employeeId = null): Access
{
    $role = Role::query()->create(['name' => 'Inventory '.Str::random(8), 'all_data' => $all, 'is_system' => false]);
    foreach ($modules as $module) {
        $grant = new RoleModuleGrant;
        $grant->forceFill(['role_id' => $role->id, 'module' => $module])->save();
    }
    $user = User::factory()->create(['role_id' => $role->id, 'employee_id' => $employeeId]);
    test()->actingAs($user);
    app()->forgetInstance(Access::class);

    return Access::forActor($user);
}

function invcoreEmployee(string $name, bool $active = true): string
{
    $row = new EmployeeProfile;
    $row->forceFill(['employee_code' => (string) (EmployeeProfile::query()->count() + 1), 'first_name' => $name, 'last_name' => 'Holder', 'position' => 'Clerk', 'employment_status' => 'Regular', 'date_hired' => '2024-01-01', 'is_active' => $active])->save();

    return $row->id;
}

/** @return array<string,mixed> */
function invcoreDraft(string $name = 'Laptop'): array
{
    return ['code' => 'ITEM-'.Str::slug($name), 'name' => $name, 'serial_no' => 'SN-'.$name, 'acquired_on' => '2026-01-01', 'cost' => 1999.99, 'category_id' => null, 'location_id' => null, 'remarks' => 'Office use'];
}

/** @return array{department: OrgNode, unit: OrgNode, position: Position} */
function invcorePlacement(string $employeeId, string $code, string $departmentName = 'Operations'): array
{
    $department = new OrgNode;
    $department->forceFill(['code' => $code.'-D', 'name' => $departmentName, 'kind' => 'DEPARTMENT'])->save();
    $unit = new OrgNode;
    $unit->forceFill(['code' => $code.'-U', 'name' => 'Property office', 'kind' => 'UNIT', 'parent_id' => $department->id])->save();
    $position = new Position;
    $position->forceFill(['code' => $code.'-P', 'name' => 'Property analyst', 'org_node_id' => $unit->id])->save();
    EmployeeProfile::query()->whereKey($employeeId)->update(['position_id' => $position->id, 'position' => $position->name]);

    return compact('department', 'unit', 'position');
}

it('requires inventory management access on every direct command without partial effects', function () {
    $manager = invcoreActor();
    $employee = invcoreEmployee('One');
    $item = app(SaveItem::class)->handle($manager, null, invcoreDraft());
    $receipt = app(IssueItems::class)->handle($manager, [$item], $employee, $employee, '2026-02-01', 'CORE-001');
    $before = [InventoryItem::query()->count(), InventoryReceipt::query()->count(), InventoryMovement::query()->count(), AuditLog::query()->count()];
    foreach ([invcoreActor([ModuleCode::INVENTORY], false), invcoreActor([ModuleCode::EMPLOYEES], true), Access::forGuest()] as $denied) {
        foreach ([
            fn () => app(SaveItem::class)->handle($denied, null, invcoreDraft()),
            fn () => app(DeleteItem::class)->handle($denied, $item),
            fn () => app(SaveCatalog::class)->handle($denied, 'category', null, 'Test'),
            fn () => app(DeleteCatalog::class)->handle($denied, 'category', 'none'),
            fn () => app(IssueItems::class)->handle($denied, [$item], $employee, $employee, '2026-03-01', 'CORE-002'),
            fn () => app(TransferItems::class)->handle($denied, [$item], $employee, $employee, '2026-03-01', 'CORE-003'),
            fn () => app(ReturnItems::class)->handle($denied, [$item], '2026-03-01'),
            fn () => app(CorrectReceipt::class)->handle($denied, $receipt, $employee, $employee, '2026-03-01', 'CORE-004', 'Typo'),
        ] as $write) {
            expect($write)->toThrow(RuntimeException::class, 'cannot manage inventory');
        }
    }
    expect([InventoryItem::query()->count(), InventoryReceipt::query()->count(), InventoryMovement::query()->count(), AuditLog::query()->count()])->toBe($before);
});

it('issues a batch to employee records without recipient or issuer accounts and freezes every paper field', function () {
    $actor = invcoreActor();
    $employee = invcoreEmployee('Recipient');
    $issuer = invcoreEmployee('Issuer');
    $category = app(SaveCatalog::class)->handle($actor, 'category', null, 'Technology');
    $location = app(SaveCatalog::class)->handle($actor, 'location', null, 'Supply room');
    $first = app(SaveItem::class)->handle($actor, null, array_replace(invcoreDraft('Laptop'), ['category_id' => $category, 'location_id' => $location]));
    $second = app(SaveItem::class)->handle($actor, null, invcoreDraft('Monitor'));
    config(['copems.inventory.office_name' => 'Original office']);
    $id = app(IssueItems::class)->handle($actor, [$first, $second], $employee, $issuer, '2026-02-01', 'CORE-005', 'Issue evidence');
    $before = app(InventoryRecords::class)->receipt($actor, $id);
    expect($before->lines)->toHaveCount(2)->and($before->total_cost)->toBe(3999.98)
        ->and($before->employee_name)->toBe('Recipient Holder')->and($before->issuer_name)->toBe('Issuer Holder')
        ->and($before->office_name)->toBe('Original office')->and($before->recorded_by)->toBe((int) $actor->userId)
        ->and(InventoryItem::query()->where('employee_id', $employee)->count())->toBe(2)
        ->and(User::query()->whereIn('employee_id', [$employee, $issuer])->count())->toBe(0);
    $paper = $before->toArray();
    EmployeeProfile::query()->whereKey($employee)->update(['first_name' => 'Renamed', 'position' => 'Director']);
    config(['copems.inventory.office_name' => 'Other office']);
    app(SaveCatalog::class)->handle($actor, 'category', $category, 'Computers');
    app(SaveCatalog::class)->handle($actor, 'location', $location, 'New room');
    app(SaveItem::class)->handle($actor, $first, invcoreDraft('Replacement description'));
    expect(app(InventoryRecords::class)->receipt($actor, $id)->toArray())->toEqual($paper);
});

it('moves a batch onto one new MR while original lines and snapshots remain intact', function () {
    $actor = invcoreActor();
    $from = invcoreEmployee('From');
    $to = invcoreEmployee('To');
    $ids = array_map(fn (string $name): string => app(SaveItem::class)->handle($actor, null, invcoreDraft($name)), ['A', 'B', 'C']);
    $original = app(IssueItems::class)->handle($actor, $ids, $from, $from, '2026-02-01', 'CORE-006');
    $snapshot = InventoryReceiptLine::query()->where('receipt_id', $original)->where('item_id', $ids[0])->firstOrFail()->item_snapshot;
    $moved = app(TransferItems::class)->handle($actor, [$ids[0], $ids[1]], $to, $from, '2026-03-01', 'CORE-007');
    expect(InventoryReceipt::query()->count())->toBe(2)
        ->and(InventoryReceiptLine::query()->where('receipt_id', $original)->count())->toBe(3)
        ->and(InventoryReceiptLine::query()->where('receipt_id', $original)->where('status', 'TRANSFERRED')->count())->toBe(2)
        ->and(InventoryReceiptLine::query()->where('receipt_id', $moved)->count())->toBe(2)
        ->and(InventoryReceiptLine::query()->where('receipt_id', $original)->where('item_id', $ids[0])->firstOrFail()->item_snapshot)->toBe($snapshot)
        ->and(InventoryReceipt::query()->findOrFail($original)->status)->toBe('OPEN');
    app(ReturnItems::class)->handle($actor, [$ids[2]], '2026-03-02');
    expect(InventoryReceipt::query()->findOrFail($original)->status)->toBe('CLOSED')
        ->and(InventoryItem::query()->findOrFail($ids[2])->status)->toBe('IN_STOCK');
    $reissued = app(IssueItems::class)->handle($actor, [$ids[2]], $to, $from, '2026-03-03', 'CORE-008');
    expect($reissued)->not->toBe($original)->and(InventoryReceiptLine::query()->where('receipt_id', $original)->count())->toBe(3);
});

it('rolls the entire batch back when any item or date is invalid', function () {
    $actor = invcoreActor();
    $employee = invcoreEmployee('Holder');
    $b = app(SaveItem::class)->handle($actor, null, invcoreDraft('B'));
    $a = app(SaveItem::class)->handle($actor, null, invcoreDraft('A'));
    app(IssueItems::class)->handle($actor, [$b], $employee, $employee, '2026-02-01', 'CORE-009');
    $before = [InventoryReceipt::query()->count(), InventoryMovement::query()->count(), AuditLog::query()->count()];
    expect(fn () => app(IssueItems::class)->handle($actor, [$a, $b], $employee, $employee, '2026-02-02', 'CORE-010'))->toThrow(RuntimeException::class)
        ->and(fn () => app(ReturnItems::class)->handle($actor, [$b, $a], '2026-02-02'))->toThrow(RuntimeException::class)
        ->and(fn () => app(ReturnItems::class)->handle($actor, [$b], '2026-01-31'))->toThrow(RuntimeException::class)
        ->and(fn () => app(IssueItems::class)->handle($actor, [$a], $employee, $employee, '2026-02-30', 'CORE-011'))->toThrow(RuntimeException::class)
        ->and(fn () => app(IssueItems::class)->handle($actor, [$a], $employee, $employee, '2999-01-01', 'CORE-012'))->toThrow(RuntimeException::class)
        ->and(fn () => app(IssueItems::class)->handle($actor, [$a], $employee, $employee, '2025-12-31', 'CORE-013'))->toThrow(RuntimeException::class);
    expect(InventoryItem::query()->findOrFail($a)->status)->toBe('IN_STOCK')
        ->and(InventoryItem::query()->findOrFail($b)->status)->toBe('ISSUED')
        ->and([InventoryReceipt::query()->count(), InventoryMovement::query()->count(), AuditLog::query()->count()])->toBe($before);
});

it('supersedes a complete current receipt without fictitious return movements', function () {
    $actor = invcoreActor();
    $from = invcoreEmployee('Wrong');
    $to = invcoreEmployee('Correct');
    $item = app(SaveItem::class)->handle($actor, null, invcoreDraft());
    $original = app(IssueItems::class)->handle($actor, [$item], $from, $from, '2026-02-01', 'CORE-014');
    $snapshot = InventoryReceipt::query()->findOrFail($original)->employee_snapshot;
    $fixed = app(CorrectReceipt::class)->handle($actor, $original, $to, $from, '2026-02-01', 'CORE-015', 'Recipient selected incorrectly');
    expect(InventoryReceipt::query()->findOrFail($original)->status)->toBe('SUPERSEDED')
        ->and(InventoryReceipt::query()->findOrFail($original)->employee_snapshot)->toBe($snapshot)
        ->and(InventoryReceipt::query()->findOrFail($fixed)->corrected_from_id)->toBe($original)
        ->and(InventoryItem::query()->findOrFail($item)->employee_id)->toBe($to)
        ->and(InventoryReceiptLine::query()->where('receipt_id', $original)->firstOrFail()->status)->toBe('SUPERSEDED')
        ->and(InventoryMovement::query()->pluck('event_type')->all())->toBe(['ISSUED', 'CORRECTED'])
        ->and(fn () => app(CorrectReceipt::class)->handle($actor, $original, $from, $from, '2026-02-02', 'CORE-016', 'Again'))->toThrow(RuntimeException::class);
});

it('refuses correcting a receipt after any original item has returned', function () {
    $actor = invcoreActor();
    $employee = invcoreEmployee('Holder');
    $a = app(SaveItem::class)->handle($actor, null, invcoreDraft('A'));
    $b = app(SaveItem::class)->handle($actor, null, invcoreDraft('B'));
    $id = app(IssueItems::class)->handle($actor, [$a, $b], $employee, $employee, '2026-02-01', 'CORE-017');
    app(ReturnItems::class)->handle($actor, [$a], '2026-03-01');
    expect(fn () => app(CorrectReceipt::class)->handle($actor, $id, $employee, $employee, '2026-03-02', 'CORE-018', 'Correction'))->toThrow(RuntimeException::class)
        ->and(InventoryReceipt::query()->count())->toBe(1);
});

it('keeps item reads scoped and Portal reads pinned even for all-data accounts', function () {
    $manager = invcoreActor();
    $own = invcoreEmployee('Own');
    $other = invcoreEmployee('Other');
    $a = app(SaveItem::class)->handle($manager, null, invcoreDraft('A'));
    $b = app(SaveItem::class)->handle($manager, null, invcoreDraft('B'));
    $stock = app(SaveItem::class)->handle($manager, null, invcoreDraft('Stock'));
    $mine = app(IssueItems::class)->handle($manager, [$a], $own, $other, '2026-02-01', 'CORE-019');
    $theirs = app(IssueItems::class)->handle($manager, [$b], $other, $other, '2026-02-01', 'CORE-020');
    $records = app(InventoryRecords::class);
    $limited = invcoreActor([ModuleCode::INVENTORY], false, $own);
    expect($records->items($limited)->pluck('id')->all())->toBe([$a])
        ->and($records->item($limited, $b))->toBeNull()->and($records->item($limited, $stock))->toBeNull()
        ->and($records->receipts($limited)->pluck('id')->all())->toBe([$mine])
        ->and($records->receipt($limited, $theirs))->toBeNull();
    $unlinked = invcoreActor([ModuleCode::INVENTORY], false);
    expect($records->items($unlinked)->total())->toBe(0)->and($records->receipts($unlinked)->total())->toBe(0);
    $portal = invcoreActor([ModuleCode::PORTAL, ModuleCode::INVENTORY], true, $own);
    expect($records->employeeHoldingsPage($portal, $own, true)->pluck('id')->all())->toBe([$a])
        ->and($records->employeeHoldingsPage($portal, $other, true)->total())->toBe(0)
        ->and($records->receipt($portal, $theirs, true))->toBeNull()
        ->and($records->receipt($portal, $mine, true)->id)->toBe($mine);
    $unrelated = invcoreActor([ModuleCode::PAYROLL], true);
    expect(fn () => $records->items($unrelated))->toThrow(HttpException::class);
});

it('retains issued history and refuses deleting used catalog entries', function () {
    $actor = invcoreActor();
    $employee = invcoreEmployee('Holder');
    $category = app(SaveCatalog::class)->handle($actor, 'category', null, 'Tech');
    $item = app(SaveItem::class)->handle($actor, null, array_replace(invcoreDraft(), ['category_id' => $category]));
    expect(fn () => app(DeleteCatalog::class)->handle($actor, 'category', $category))->toThrow(RuntimeException::class);
    app(IssueItems::class)->handle($actor, [$item], $employee, $employee, '2026-02-01', 'CORE-021');
    app(ReturnItems::class)->handle($actor, [$item], '2026-03-01');
    expect(fn () => app(DeleteItem::class)->handle($actor, $item))->toThrow(RuntimeException::class)
        ->and(InventoryItem::query()->whereKey($item)->exists())->toBeTrue();
});

it('accepts entered item codes while ignoring client custody and actor fields', function () {
    $actor = invcoreActor();
    $employee = invcoreEmployee('Holder');
    $ids = [];
    for ($n = 0; $n < 11; $n++) {
        $ids[] = app(SaveItem::class)->handle($actor, null, invcoreDraft('Item '.$n) + ['id' => 'client-supplied-id', 'employee_id' => $employee, 'status' => 'ISSUED', 'condition' => 'MISSING', 'recorded_by' => 123]);
    }
    expect(InventoryItem::query()->whereNotNull('employee_id')->count())->toBe(0)
        ->and(InventoryItem::query()->where('condition', '<>', 'SERVICEABLE')->count())->toBe(0)
        ->and(app(InventoryRecords::class)->items($actor)->pluck('code')->all())->toBe(['ITEM-item-0', 'ITEM-item-1', 'ITEM-item-10', 'ITEM-item-2', 'ITEM-item-3', 'ITEM-item-4', 'ITEM-item-5', 'ITEM-item-6', 'ITEM-item-7', 'ITEM-item-8', 'ITEM-item-9'])
        ->and(InventoryItem::query()->whereKey('client-supplied-id')->exists())->toBeFalse()
        ->and(fn () => app(IssueItems::class)->handle($actor, [$ids[0]], invcoreEmployee('Inactive', false), $employee, '2026-02-01', 'CORE-022'))->toThrow(RuntimeException::class);
});

it('does not treat missing items as physically returned and refuses same-holder transfers', function () {
    $actor = invcoreActor();
    $employee = invcoreEmployee('Holder');
    $item = app(SaveItem::class)->handle($actor, null, invcoreDraft());
    app(IssueItems::class)->handle($actor, [$item], $employee, $employee, '2026-02-01', 'CORE-023');
    expect(fn () => app(TransferItems::class)->handle($actor, [$item], $employee, $employee, '2026-03-01', 'CORE-024'))->toThrow(RuntimeException::class);
    InventoryItem::query()->whereKey($item)->update(['condition' => 'MISSING']);
    expect(fn () => app(ReturnItems::class)->handle($actor, [$item], '2026-03-01'))->toThrow(RuntimeException::class)
        ->and(InventoryItem::query()->findOrFail($item)->employee_id)->toBe($employee)
        ->and(InventoryMovement::query()->count())->toBe(1);
});

/** @param list<list<string>> $rows */
function invcoreCsv(array $rows): string
{
    $stream = fopen('php://temp', 'w+');
    if ($stream === false) {
        throw new RuntimeException('Cannot open CSV fixture.');
    }
    fputcsv($stream, ImportItems::COLUMNS, ',', '"', '');
    foreach ($rows as $row) {
        fputcsv($stream, $row, ',', '"', '');
    }
    rewind($stream);
    $text = stream_get_contents($stream);
    fclose($stream);

    return $text === false ? '' : $text;
}

it('previews CSV without effects and commits only after every row passes owner validation', function () {
    $actor = invcoreActor();
    $runner = app(ImportRunner::class);
    $import = app(ImportItems::class);
    $bytes = invcoreCsv([['LAP-001', 'New laptop', 'SN-100', '2026-01-01', '1200.50', '', '', 'From CSV']]);
    $preview = $runner->preview($actor, $import, '', $bytes);
    expect($preview->errors)->toBe([])->and($preview->created)->toBe(1)->and($preview->saved)->toBeFalse()
        ->and(InventoryItem::query()->count())->toBe(0)->and(AuditLog::query()->count())->toBe(0);
    $commit = $runner->commit($actor, $import, '', $bytes);
    expect($commit->saved)->toBeTrue()->and($commit->created)->toBe(1)
        ->and(InventoryItem::query()->firstOrFail()->cost)->toBe(1200.5);
    $invalid = invcoreCsv([
        ['LAP-002', 'Would pass', '', '2026-01-01', '100', '', '', ''],
        ['LAP-003', 'Fails', '', '2026-01-01', '-10', '', '', ''],
    ]);
    $beforeAudit = AuditLog::query()->count();
    $refused = $runner->commit($actor, $import, '', $invalid);
    expect($refused->saved)->toBeFalse()->and($refused->failed)->toBe(1)
        ->and(InventoryItem::query()->count())->toBe(1)->and(AuditLog::query()->count())->toBe($beforeAudit);
});

it('updates only unused stock from CSV and refuses issued or returned items', function () {
    $actor = invcoreActor();
    $employee = invcoreEmployee('Holder');
    $id = app(SaveItem::class)->handle($actor, null, invcoreDraft());
    $runner = app(ImportRunner::class);
    $import = app(ImportItems::class);
    $csv = invcoreCsv([['ITEM-laptop', 'Renamed laptop', 'ABC', '2026-01-01', '123.45', '', '', 'Metadata only']]);
    expect($runner->commit($actor, $import, '', $csv)->updated)->toBe(1)
        ->and($runner->commit($actor, $import, '', $csv)->unchanged)->toBe(1)
        ->and(InventoryItem::query()->findOrFail($id)->name)->toBe('Renamed laptop');
    app(IssueItems::class)->handle($actor, [$id], $employee, $employee, '2026-02-01', 'CORE-025');
    expect($runner->commit($actor, $import, '', $csv)->failed)->toBe(1);
    app(ReturnItems::class)->handle($actor, [$id], '2026-03-01');
    expect($runner->commit($actor, $import, '', $csv)->failed)->toBe(1)
        ->and(InventoryItem::query()->findOrFail($id)->name)->toBe('Renamed laptop');
});

it('exports the full scoped filtered order and freezes receipt CSV names after employee edits', function () {
    $manager = invcoreActor();
    $own = invcoreEmployee('Original');
    $other = invcoreEmployee('Other');
    $ids = [];
    for ($n = 1; $n <= 12; $n++) {
        $ids[] = app(SaveItem::class)->handle($manager, null, invcoreDraft('Laptop '.$n));
    }
    $mine = app(IssueItems::class)->handle($manager, [$ids[0], $ids[9]], $own, $other, '2026-02-01', 'CORE-026');
    app(IssueItems::class)->handle($manager, [$ids[1]], $other, $other, '2026-02-01', 'CORE-027');
    $actor = invcoreActor([ModuleCode::INVENTORY], false, $own);
    $export = app(ItemsExport::class)->export($actor, ['q' => 'Laptop', 'status' => 'ISSUED', 'sort' => 'code', 'dir' => 'desc']);
    $rows = iterator_to_array(($export->rows)());
    expect(array_column($rows, 0))->toBe(['ITEM-laptop-10', 'ITEM-laptop-1'])->and(array_column($rows, 10))->toBe(['Original Holder', 'Original Holder']);
    EmployeeProfile::query()->whereKey($own)->update(['first_name' => 'Changed']);
    $receipts = app(ReceiptsExport::class)->export($actor, ['q' => 'Original']);
    $receiptRows = iterator_to_array(($receipts->rows)());
    expect($receiptRows)->toHaveCount(1)->and($receiptRows[0][2])->toBe('Original Holder')
        ->and($receiptRows[0][3])->toBe('Other Holder')
        ->and($receiptRows[0][0])->toBe('CORE-026');
    $all = app(ItemsExport::class)->export($manager, ['status' => 'IN_STOCK', 'sort' => 'code']);
    expect(array_column(iterator_to_array(($all->rows)()), 0))->toBe(['ITEM-laptop-11', 'ITEM-laptop-12', 'ITEM-laptop-3', 'ITEM-laptop-4', 'ITEM-laptop-5', 'ITEM-laptop-6', 'ITEM-laptop-7', 'ITEM-laptop-8', 'ITEM-laptop-9']);
});

it('clamps narrowed inventory pages to the last permitted page', function () {
    $actor = invcoreActor();
    $employee = invcoreEmployee('Own');
    $ids = [];
    foreach (range(1, 4) as $n) {
        $ids[] = app(SaveItem::class)->handle($actor, null, invcoreDraft('Item '.$n));
    }
    app(IssueItems::class)->handle($actor, $ids, $employee, $employee, '2026-02-01', 'CORE-028');
    $query = app(InventoryRecords::class);
    $items = $query->items($actor, ['search' => 'Item 1'], page: 999, perPage: 2);
    $receipts = $query->receipts($actor, page: 999, perPage: 2);
    $holdings = $query->employeeHoldingsPage($actor, $employee, page: 999, perPage: 2);
    expect($items->currentPage())->toBe(1)->and($items->total())->toBe(1)
        ->and($receipts->currentPage())->toBe(1)->and($holdings->currentPage())->toBe(2);
});

it('retains a physical-count location after every live item has moved elsewhere', function () {
    $actor = invcoreActor();
    $oldLocation = app(SaveCatalog::class)->handle($actor, 'location', null, 'Original storeroom');
    $newLocation = app(SaveCatalog::class)->handle($actor, 'location', null, 'Current storeroom');
    $item = app(SaveItem::class)->handle($actor, null, array_replace(invcoreDraft(), ['location_id' => $oldLocation]));
    $count = new InventoryCount;
    $count->forceFill([
        'location_id' => $oldLocation, 'location_name' => 'Original storeroom',
        'counted_on' => '2026-02-01', 'reference' => 'COUNT-RETENTION', 'notes' => '',
        'status' => 'OPEN', 'actor_id' => (int) $actor->userId, 'actor_name' => $actor->fullName,
    ])->save();
    InventoryCountLine::query()->create([
        'count_id' => $count->id, 'item_id' => $item,
        'item_snapshot' => ['name' => 'Laptop', 'location_id' => $oldLocation],
        'result' => 'FOUND', 'notes' => '',
    ]);
    app(SaveItem::class)->handle($actor, $item, array_replace(invcoreDraft(), ['location_id' => $newLocation]));
    $auditCount = AuditLog::query()->count();

    expect(InventoryItem::query()->where('location_id', $oldLocation)->count())->toBe(0)
        ->and(fn () => app(DeleteCatalog::class)->handle($actor, 'location', $oldLocation))
        ->toThrow(RuntimeException::class, 'That location has physical-count history and cannot be deleted.')
        ->and(InventoryLocation::query()->whereKey($oldLocation)->exists())->toBeTrue()
        ->and(InventoryCount::query()->whereKey($count->id)->exists())->toBeTrue()
        ->and(AuditLog::query()->count())->toBe($auditCount);
});

it('refreshes employee holdings when a referenced location is renamed', function () {
    $this->travelTo(CarbonImmutable::parse('2026-03-01T00:00:00Z'));
    $manager = invcoreActor();
    $employee = invcoreEmployee('Holder');
    $location = app(SaveCatalog::class)->handle($manager, 'location', null, 'Original room');
    $unrelated = app(SaveCatalog::class)->handle($manager, 'location', null, 'Other room');
    $item = app(SaveItem::class)->handle($manager, null, array_replace(invcoreDraft(), ['location_id' => $location]));
    app(IssueItems::class)->handle($manager, [$item], $employee, $employee, '2026-02-01', 'CORE-029');
    $portal = invcoreActor([ModuleCode::PORTAL], false, $employee);
    $records = app(InventoryRecords::class);
    $before = $records->holdingsStamp($portal, $employee, true);
    $itemUpdatedAt = InventoryItem::query()->findOrFail($item)->updated_at;
    $this->travel(1)->seconds();
    app(SaveCatalog::class)->handle($manager, 'location', $unrelated, 'Another room');
    expect($records->holdingsStamp($portal, $employee, true))->toBe($before);
    $this->travel(1)->seconds();
    app(SaveCatalog::class)->handle($manager, 'location', $location, 'Renamed room');
    expect($records->holdingsStamp($portal, $employee, true))->not->toBe($before)
        ->and($records->employeeHoldingsPage($portal, $employee, true)->items()[0]->location_name)->toBe('Renamed room')
        ->and(InventoryItem::query()->findOrFail($item)->updated_at)->toEqual($itemUpdatedAt);
    $this->travelBack();
});

it('refreshes scoped holder labels when the employee record name changes', function () {
    $this->travelTo(CarbonImmutable::parse('2026-03-01T00:00:00Z'));
    $manager = invcoreActor();
    $employee = invcoreEmployee('Original');
    $other = invcoreEmployee('Other');
    $item = app(SaveItem::class)->handle($manager, null, invcoreDraft());
    app(IssueItems::class)->handle($manager, [$item], $employee, $employee, '2026-02-01', 'CORE-030');
    $reader = invcoreActor([ModuleCode::INVENTORY], false, $employee);
    $records = app(InventoryRecords::class);
    $before = $records->changeStamp($reader);
    $this->travel(1)->seconds();
    EmployeeProfile::query()->whereKey($other)->update(['first_name' => 'Someone else']);
    expect($records->changeStamp($reader))->toBe($before);
    $this->travel(1)->seconds();
    EmployeeProfile::query()->whereKey($employee)->update(['first_name' => 'Renamed']);
    expect($records->changeStamp($reader))->not->toBe($before)
        ->and($records->item($reader, $item)->employee_name)->toBe('Renamed Holder');
    $this->travelBack();
});

it('projects current holder placement and the active issue date without exposing foreign holdings', function () {
    $manager = invcoreActor();
    $from = invcoreEmployee('Original');
    $to = invcoreEmployee('Current');
    $placement = invcorePlacement($to, 'VIEW');
    $item = app(SaveItem::class)->handle($manager, null, invcoreDraft());
    $stock = app(SaveItem::class)->handle($manager, null, invcoreDraft('Stock'));
    $original = app(IssueItems::class)->handle($manager, [$item], $from, $from, '2026-02-01', 'CORE-031');
    $current = app(TransferItems::class)->handle($manager, [$item], $to, $from, '2026-03-01', 'CORE-032');
    $placement['unit']->forceFill(['name' => 'Current property office'])->save();
    $records = app(InventoryRecords::class);
    $items = $records->items($manager)->getCollection()->keyBy('id');

    expect($items[$item]->employee_name)->toBe('Current Holder')
        ->and($items[$item]->employee_position)->toBe('Property analyst')
        ->and($items[$item]->employee_department)->toBe('Operations')
        ->and($items[$item]->employee_unit)->toBe('Current property office')
        ->and($items[$item]->current_receipt_id)->toBe($current)
        ->and($items[$item]->current_receipt_issued_on)->toBe('2026-03-01')
        ->and($items[$stock]->employee_position)->toBeNull()
        ->and($items[$stock]->employee_department)->toBeNull()
        ->and($items[$stock]->employee_unit)->toBeNull()
        ->and($items[$stock]->current_receipt_issued_on)->toBeNull()
        ->and($records->receipt($manager, $original)->issued_on)->toBe('2026-02-01')
        ->and($records->receipt($manager, $current)->employee_snapshot['unit'])->toBe('Property office');

    $reader = invcoreActor([ModuleCode::INVENTORY], false, $to);
    expect($records->items($reader)->pluck('id')->all())->toBe([$item])
        ->and($records->item($reader, $item)->employee_unit)->toBe('Current property office')
        ->and($records->item($reader, $stock))->toBeNull();
    $former = invcoreActor([ModuleCode::INVENTORY], false, $from);
    expect($records->items($former)->total())->toBe(0)->and($records->item($former, $item))->toBeNull();
    $portal = invcoreActor([ModuleCode::PORTAL], true, $to);
    expect($records->employeeHoldingsPage($portal, $to, true)->items()[0]->current_receipt_issued_on)->toBe('2026-03-01')
        ->and($records->employeeHoldingsPage($portal, $from, true)->total())->toBe(0);

    app(ReturnItems::class)->handle($manager, [$item], '2026-03-02');
    expect($records->item($manager, $item)->current_receipt_issued_on)->toBeNull()
        ->and($records->item($manager, $item)->employee_position)->toBeNull();
});

it('summarizes original receipt items from a batch limited to the visible page', function () {
    $manager = invcoreActor();
    $own = invcoreEmployee('Own');
    $other = invcoreEmployee('Other');
    $items = array_map(fn (string $name): string => app(SaveItem::class)->handle($manager, null, invcoreDraft($name)), ['Tablet', 'Monitor', 'Desk', 'Dock']);
    $mine = app(IssueItems::class)->handle($manager, $items, $own, $other, '2026-02-01', 'CORE-033');
    $foreignItem = app(SaveItem::class)->handle($manager, null, invcoreDraft('Foreign printer'));
    $theirs = app(IssueItems::class)->handle($manager, [$foreignItem], $other, $other, '2026-02-02', 'CORE-034');
    app(SaveItem::class)->handle($manager, $items[0], invcoreDraft('Renamed tablet'));
    app(ReturnItems::class)->handle($manager, [$items[2]], '2026-03-01');
    $records = app(InventoryRecords::class);
    $reader = invcoreActor([ModuleCode::INVENTORY], false, $own);
    $rows = $records->receipts($reader, page: 99, perPage: 1);
    $detail = $records->receipt($reader, $mine);

    expect($rows->total())->toBe(1)->and($rows->currentPage())->toBe(1)
        ->and($rows->items()[0]->id)->toBe($mine)
        ->and($rows->items()[0]->item_count)->toBe(4)
        ->and($rows->items()[0]->item_summary)->toBe('Tablet, Monitor, Desk +1 more')
        ->and($rows->items()[0]->lines)->toHaveCount(4)
        ->and($detail->item_count)->toBe(4)
        ->and($detail->item_summary)->toBe($rows->items()[0]->item_summary)
        ->and($records->receipt($reader, $theirs))->toBeNull();

    DB::flushQueryLog();
    DB::enableQueryLog();
    try {
        $secondPage = $records->receipts($manager, page: 2, perPage: 1);
        $lineQueries = collect(DB::getQueryLog())->filter(static fn (array $query): bool => str_contains($query['query'], 'inventory_receipt_lines'))->values();
    } finally {
        DB::disableQueryLog();
        DB::flushQueryLog();
    }
    expect($secondPage->items()[0]->item_count)->toBe(1)
        ->and($secondPage->items()[0]->item_summary)->toBe('Foreign printer')
        ->and($lineQueries->count())->toBe(1)
        ->and($lineQueries[0]['bindings'])->toBe([$theirs]);
});

it('refreshes current placement labels without reacting to a foreign department', function () {
    $manager = invcoreActor();
    $own = invcoreEmployee('Own');
    $other = invcoreEmployee('Other');
    $placement = invcorePlacement($own, 'OWN');
    $foreignPlacement = invcorePlacement($other, 'OTHER', 'Other department');
    $item = app(SaveItem::class)->handle($manager, null, invcoreDraft());
    app(IssueItems::class)->handle($manager, [$item], $own, $other, '2026-02-01', 'CORE-035');
    $reader = invcoreActor([ModuleCode::INVENTORY], false, $own);
    $records = app(InventoryRecords::class);
    $employeeUpdatedAt = EmployeeProfile::query()->findOrFail($own)->updated_at;
    $before = $records->changeStamp($reader);
    $foreignPlacement['department']->forceFill(['name' => 'Unrelated department'])->save();
    expect($records->changeStamp($reader))->toBe($before);
    $placement['unit']->forceFill(['name' => 'Renamed unit'])->save();
    $afterUnit = $records->changeStamp($reader);
    expect($afterUnit)->not->toBe($before)
        ->and($records->item($reader, $item)->employee_unit)->toBe('Renamed unit');
    $placement['department']->forceFill(['name' => 'Renamed department'])->save();
    expect($records->changeStamp($reader))->not->toBe($afterUnit)
        ->and($records->item($reader, $item)->employee_department)->toBe('Renamed department')
        ->and(EmployeeProfile::query()->findOrFail($own)->updated_at)->toEqual($employeeUpdatedAt);
});
