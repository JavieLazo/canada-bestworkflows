<?php

declare(strict_types=1);

use App\Domain\Enums\ModuleCode;
use App\Livewire\Inventory\Support\ItemsExport;
use App\Models\AuditLog;
use App\Models\EmployeeProfile;
use App\Models\InventoryCountLine;
use App\Models\InventoryItem;
use App\Models\InventoryMovement;
use App\Models\InventoryReceiptLine;
use App\Models\Role;
use App\Models\RoleModuleGrant;
use App\Models\User;
use App\Modules\Inventory\Application\Commands\ImportItems;
use App\Modules\Inventory\Application\Commands\IssueItems;
use App\Modules\Inventory\Application\Commands\RecordCount;
use App\Modules\Inventory\Application\Commands\SaveCatalog;
use App\Modules\Inventory\Application\Commands\SaveItem;
use App\Modules\Inventory\Application\Queries\InventoryLifecycle;
use App\Modules\Inventory\Application\Queries\InventoryRecords;
use App\Modules\Inventory\Application\Queries\InventoryReports;
use App\Platform\Transfer\ImportRunner;
use App\Support\Access;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Manual item codes identify property while hidden row keys preserve custody and frozen evidence. */
function invitemcodeActor(): Access
{
    $role = Role::query()->create(['name' => 'Manual codes '.Str::random(8), 'all_data' => true, 'is_system' => false]);
    $grant = new RoleModuleGrant;
    $grant->forceFill(['role_id' => $role->id, 'module' => ModuleCode::INVENTORY])->save();
    $user = User::factory()->create(['role_id' => $role->id]);

    return Access::forActor($user);
}

/** @return array<string,mixed> */
function invitemcodeDraft(mixed $code = 'OFFICE-0007'): array
{
    return ['code' => $code, 'name' => 'Office laptop', 'serial_no' => 'SN-7', 'acquired_on' => '2026-01-01', 'cost' => 100, 'remarks' => 'Office use'];
}

function invitemcodeEmployee(): string
{
    $row = new EmployeeProfile;
    $row->forceFill(['employee_code' => 'CODE-HOLDER', 'first_name' => 'Synthetic', 'last_name' => 'Holder', 'employment_status' => 'Regular', 'date_hired' => '2024-01-01', 'is_active' => true])->save();

    return $row->id;
}

/** @param list<list<string>> $rows */
function invitemcodeCsv(array $rows): string
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
    $bytes = stream_get_contents($stream);
    fclose($stream);

    return $bytes === false ? '' : $bytes;
}

it('saves the entered item code as text separately from its generated row key', function (string $entered, string $stored) {
    $id = app(SaveItem::class)->handle(invitemcodeActor(), null, invitemcodeDraft($entered) + ['id' => 'CLIENT-SUPPLIED-ID']);
    $item = InventoryItem::query()->findOrFail($id);
    expect($item->code)->toBe($stored)->and($id)->not->toBe($stored)->not->toBe('CLIENT-SUPPLIED-ID')
        ->and(Str::isUlid($id))->toBeTrue()->and(InventoryItem::query()->count())->toBe(1);
})->with([
    ['  PROP / 0007-A  ', 'PROP / 0007-A'],
    ['0007', '0007'],
    ['0', '0'],
    [str_repeat('A', 40), str_repeat('A', 40)],
]);

it('refuses missing or invalid manual item codes without creating a row or audit event', function (mixed $code) {
    $actor = invitemcodeActor();
    $audit = AuditLog::query()->count();
    expect(fn () => app(SaveItem::class)->handle($actor, null, invitemcodeDraft($code)))->toThrow(ValidationException::class)
        ->and(InventoryItem::query()->count())->toBe(0)->and(AuditLog::query()->count())->toBe($audit);
})->with([null, '', '   ', 123, true, [str_repeat('A', 41)], [['not text']]]);

it('refuses a duplicate code on create and edit without changing metadata or audit history', function () {
    $actor = invitemcodeActor();
    $first = app(SaveItem::class)->handle($actor, null, invitemcodeDraft('PROPERTY-001'));
    $second = app(SaveItem::class)->handle($actor, null, invitemcodeDraft('PROPERTY-002'));
    $before = InventoryItem::query()->orderBy('id')->get()->toArray();
    $audit = AuditLog::query()->count();
    foreach ([null, $second] as $target) {
        expect(fn () => app(SaveItem::class)->handle($actor, $target, array_replace(invitemcodeDraft(' PROPERTY-001 '), ['name' => 'Should not be saved'])))
            ->toThrow(RuntimeException::class, 'That item code is already in use. Enter a different code.');
    }
    expect(InventoryItem::query()->orderBy('id')->get()->toArray())->toBe($before)
        ->and(AuditLog::query()->count())->toBe($audit);
    app(SaveItem::class)->handle($actor, $first, array_replace(invitemcodeDraft(' PROPERTY-001 '), ['name' => 'Updated laptop']));
    expect(InventoryItem::query()->findOrFail($first)->name)->toBe('Updated laptop');
});

it('edits a current item code without rewriting its row key custody links or frozen MR and count evidence', function () {
    $actor = invitemcodeActor();
    $employee = invitemcodeEmployee();
    $location = app(SaveCatalog::class)->handle($actor, 'location', null, 'Supply room');
    $input = invitemcodeDraft('OLD-0007') + ['location_id' => $location];
    $id = app(SaveItem::class)->handle($actor, null, $input);
    $receipt = app(IssueItems::class)->handle($actor, [$id], $employee, $employee, '2026-02-01', 'MANUAL-CODE-MR');
    $sheet = app(InventoryLifecycle::class)->countSheet($actor, $location)->first();
    app(RecordCount::class)->save($actor, ['location_id' => $location, 'counted_on' => '2026-02-02', 'reference' => 'COUNT-CODE', 'lines' => [
        ['item_id' => $id, 'result' => 'FOUND', 'notes' => '', 'version' => $sheet->version],
    ]]);
    $paper = InventoryReceiptLine::query()->where('receipt_id', $receipt)->firstOrFail()->toArray();
    $count = InventoryCountLine::query()->where('item_id', $id)->firstOrFail()->toArray();
    $movement = InventoryMovement::query()->where('item_id', $id)->firstOrFail()->toArray();

    $saved = app(SaveItem::class)->handle($actor, $id, array_replace($input, ['code' => 'NEW-0007', 'id' => 'FORGED-ROW-ID']));
    expect($saved)->toBe($id)->and(InventoryItem::query()->findOrFail($id)->code)->toBe('NEW-0007')
        ->and(InventoryItem::query()->findOrFail($id)->current_receipt_id)->toBe($receipt)
        ->and(InventoryItem::query()->findOrFail($id)->employee_id)->toBe($employee)
        ->and(InventoryReceiptLine::query()->where('receipt_id', $receipt)->firstOrFail()->toArray())->toBe($paper)
        ->and(InventoryCountLine::query()->where('item_id', $id)->firstOrFail()->toArray())->toBe($count)
        ->and(InventoryMovement::query()->where('item_id', $id)->firstOrFail()->toArray())->toBe($movement)
        ->and($paper['item_snapshot']['code'])->toBe('OLD-0007')->and($count['item_snapshot']['code'])->toBe('OLD-0007');
});

it('orders manual item codes as text in the register stock holdings reports and CSV', function () {
    $actor = invitemcodeActor();
    $codes = ['2', 'B-002', '10', '0007', 'A-001'];
    $expected = ['0007', '10', '2', 'A-001', 'B-002'];
    $ids = array_map(fn (string $code): string => app(SaveItem::class)->handle($actor, null, invitemcodeDraft($code)), $codes);
    $records = app(InventoryRecords::class);
    expect($records->items($actor)->pluck('code')->all())->toBe($expected)
        ->and($records->items($actor, [], 'code', 'desc')->pluck('code')->all())->toBe(array_reverse($expected))
        ->and($records->items($actor, ['status' => 'IN_STOCK', 'condition' => 'SERVICEABLE'])->pluck('code')->all())->toBe($expected)
        ->and(app(InventoryReports::class)->rows($actor, 'stock')->map(fn ($row) => $row->cells[0])->all())->toBe($expected);
    $export = app(ItemsExport::class)->export($actor, []);
    $rows = iterator_to_array(($export->rows)());
    expect($export->headers[0])->toBe('Item code')->and($export->headers)->not->toContain('ID')
        ->and(array_column($rows, 0))->toBe($expected)
        ->and(array_intersect($ids, array_merge(...$rows)))->toBe([]);
    $employee = invitemcodeEmployee();
    app(IssueItems::class)->handle($actor, $ids, $employee, $employee, '2026-02-01', 'SORT-CODE-MR');
    expect($records->employeeHoldingsPage($actor, $employee)->pluck('code')->all())->toBe($expected)
        ->and(app(InventoryReports::class)->rows($actor)->map(fn ($row) => $row->cells[0])->all())->toBe($expected);
});

it('imports explicit item codes with preview rollback and matches eligible stock without exposing row keys', function () {
    $actor = invitemcodeActor();
    $id = app(SaveItem::class)->handle($actor, null, invitemcodeDraft('0007'));
    $runner = app(ImportRunner::class);
    $import = app(ImportItems::class);
    $bytes = invitemcodeCsv([
        ['0007', 'Updated laptop', 'SN-7', '2026-01-01', '120', '', '', 'Updated'],
        ['0', 'New chair', '', '2026-01-01', '50', '', '', ''],
    ]);
    $audit = AuditLog::query()->count();
    $preview = $runner->preview($actor, $import, '', $bytes);
    expect($preview->failed)->toBe(0)->and($preview->created)->toBe(1)->and($preview->updated)->toBe(1)
        ->and($preview->saved)->toBeFalse()->and(InventoryItem::query()->count())->toBe(1)
        ->and(InventoryItem::query()->findOrFail($id)->name)->toBe('Office laptop')
        ->and(AuditLog::query()->count())->toBe($audit);
    $saved = $runner->commit($actor, $import, '', $bytes);
    expect($saved->saved)->toBeTrue()->and($saved->created)->toBe(1)->and($saved->updated)->toBe(1)
        ->and(InventoryItem::query()->where('code', '0007')->value('id'))->toBe($id)
        ->and(InventoryItem::query()->where('code', '0')->count())->toBe(1)
        ->and(InventoryItem::query()->findOrFail($id)->name)->toBe('Updated laptop');
    $unchanged = $runner->commit($actor, $import, '', $bytes);
    expect($unchanged->unchanged)->toBe(2)->and($unchanged->created)->toBe(0)->and($unchanged->updated)->toBe(0);
});

it('rolls every CSV row back for blank item codes or attempts to update issued property', function () {
    $actor = invitemcodeActor();
    $id = app(SaveItem::class)->handle($actor, null, invitemcodeDraft('ISSUED-1'));
    $employee = invitemcodeEmployee();
    app(IssueItems::class)->handle($actor, [$id], $employee, $employee, '2026-02-01', 'CSV-ISSUED-MR');
    $audit = AuditLog::query()->count();
    $result = app(ImportRunner::class)->commit($actor, app(ImportItems::class), '', invitemcodeCsv([
        ['NEW-CODE', 'Valid new item', '', '2026-01-01', '50', '', '', ''],
        ['', 'Blank item code', '', '2026-01-01', '50', '', '', ''],
        ['ISSUED-1', 'Cannot update', '', '2026-01-01', '50', '', '', ''],
    ]));
    expect($result->failed)->toBe(2)->and($result->saved)->toBeFalse()
        ->and(InventoryItem::query()->count())->toBe(1)->and(InventoryItem::query()->findOrFail($id)->name)->toBe('Office laptop')
        ->and(AuditLog::query()->count())->toBe($audit);
});
