<?php

declare(strict_types=1);

use App\Livewire\Inventory\CatalogsPage;
use App\Livewire\Inventory\EmployeeItems;
use App\Livewire\Inventory\IssueReceipt;
use App\Livewire\Inventory\ItemPage;
use App\Livewire\Inventory\ItemsPage;
use App\Livewire\Inventory\ReceiptPage;
use App\Models\AuditLog;
use App\Models\EmployeeProfile;
use App\Models\InventoryCategory;
use App\Models\InventoryItem;
use App\Models\InventoryReceipt;
use App\Models\Role;
use App\Models\RoleModuleGrant;
use App\Models\User;
use App\Modules\Inventory\Application\Commands\IssueItems;
use App\Modules\Inventory\Application\Commands\SaveItem;
use App\Support\Access;
use Illuminate\Support\Str;
use Livewire\Livewire;

/** Inventory screens preserve employee-based custody, frozen paper and fresh action authorization. */
function inventoryUiActor(bool $allData = true, ?string $employeeId = null, string $module = 'INVENTORY'): User
{
    $role = new Role;
    $role->forceFill(['name' => 'Inventory UI '.Str::random(8), 'all_data' => $allData, 'is_system' => false])->save();
    $grant = new RoleModuleGrant;
    $grant->forceFill(['role_id' => $role->id, 'module' => $module])->save();
    $actor = User::factory()->create(['role_id' => $role->id, 'employee_id' => $employeeId]);
    test()->actingAs($actor);
    app()->forgetInstance(Access::class);

    return $actor;
}

function inventoryUiEmployee(string $code, string $name): EmployeeProfile
{
    $employee = new EmployeeProfile;
    $employee->forceFill(['employee_code' => $code, 'first_name' => $name, 'last_name' => 'Inventory', 'employment_status' => 'Regular', 'date_hired' => '2024-01-01', 'is_active' => true])->save();

    return $employee;
}

function inventoryUiItem(string $name = 'Office laptop', ?string $code = null): string
{
    return app(SaveItem::class)->handle(app(Access::class), null, ['code' => $code ?? 'UI-'.Str::random(8), 'name' => $name, 'serial_no' => 'SERIAL-'.Str::random(8), 'acquired_on' => '2026-01-02', 'cost' => '15000', 'category_id' => '', 'location_id' => '', 'remarks' => 'For official use']);
}

it('requires a manually entered item code and shows field validation before saving', function () {
    inventoryUiActor();
    $component = Livewire::test(ItemsPage::class)->call('openAdd')
        ->assertSet('form.code', '')->assertSet('editingId', null)
        ->set('form.name', 'Office tablet')->set('form.cost', '18000')
        ->set('form.acquired_on', '2026-01-02');
    $auditCount = AuditLog::query()->count();

    $component->call('saveItem')->assertHasErrors('form.code')->assertSet('itemDialog', true);
    $component->set('form.code', str_repeat('A', 41))->call('saveItem')->assertHasErrors('form.code');
    expect(InventoryItem::query()->count())->toBe(0)
        ->and(AuditLog::query()->count())->toBe($auditCount);

    $component->set('form.code', '  PROP-2026-041  ')->call('saveItem')->assertHasNoErrors()->assertSet('itemDialog', false);
    $item = InventoryItem::query()->sole();
    expect($item->code)->toBe('PROP-2026-041')->not->toBe($item->id);
    $this->get(route('inventory.items'))->assertOk()->assertSee('Item Code')->assertSee('PROP-2026-041')->assertDontSee('System ID');

    $component->call('openAdd')->assertSet('form.code', '')->assertSet('editingId', null)
        ->set('form.code', 'PROP-2026-041')->set('form.name', 'Duplicate tablet')
        ->set('form.cost', '19000')->set('form.acquired_on', '2026-01-02');
    $auditCount = AuditLog::query()->count();
    $component->call('saveItem')->assertHasErrors('inventory')
        ->assertSee('That item code is already in use. Enter a different code.')->assertSet('itemDialog', true);
    expect(InventoryItem::query()->count())->toBe(1)
        ->and(AuditLog::query()->count())->toBe($auditCount);
});

it('edits an item code without changing its hidden identity or accepting another item code', function () {
    inventoryUiActor();
    $itemId = inventoryUiItem('Office laptop');
    $otherId = inventoryUiItem('Office monitor');
    $originalCode = InventoryItem::query()->findOrFail($itemId)->code;
    $otherCode = InventoryItem::query()->findOrFail($otherId)->code;
    $component = Livewire::test(ItemPage::class, ['id' => $itemId])->call('startEdit', $itemId)
        ->assertSet('form.code', $originalCode)->assertSet('editingId', $itemId);
    $auditCount = AuditLog::query()->count();

    $component->set('form.code', $otherCode)->call('saveItem')->assertHasErrors('inventory')->assertSet('itemDialog', true);
    expect(InventoryItem::query()->findOrFail($itemId)->code)->toBe($originalCode)
        ->and(AuditLog::query()->count())->toBe($auditCount);

    $component->set('form.code', 'ASSET-LAPTOP-009')->call('saveItem')->assertHasNoErrors()->assertSet('itemDialog', false);
    expect(InventoryItem::query()->findOrFail($itemId)->code)->toBe('ASSET-LAPTOP-009')
        ->and(InventoryItem::query()->count())->toBe(2);
    $this->get(route('inventory.items.show', ['id' => $itemId]))->assertOk()->assertSee('ASSET-LAPTOP-009')->assertDontSee('System ID');
});

it('renders the inventory core surfaces and a frozen printable receipt', function () {
    inventoryUiActor();
    $employee = inventoryUiEmployee('IUI-001', 'Recipient');
    $issuer = inventoryUiEmployee('IUI-002', 'Issuer');
    $item = inventoryUiItem();
    $receipt = app(IssueItems::class)->handle(app(Access::class), [$item], $employee->id, $issuer->id, '2026-02-03', 'UI-MR-'.Str::random(8));

    $this->get(route('inventory.items'))->assertOk()->assertSee('Office laptop');
    $this->get(route('inventory.items.show', ['id' => $item]))->assertOk()->assertSee('Movement History');
    $this->get(route('inventory.receipts'))->assertOk()->assertSee('Recipient Inventory');
    $this->get(route('inventory.receipts.show', ['id' => $receipt]))->assertOk()->assertSee('Original items');
    $this->get(route('inventory.catalogs'))->assertOk()->assertSee('Categories &amp; locations', false);

    $employee->forceFill(['first_name' => 'Renamed'])->save();
    InventoryItem::query()->findOrFail($item)->forceFill(['name' => 'Renamed item', 'cost' => 999])->save();
    $this->get(route('inventory.receipts.print', ['id' => $receipt]))
        ->assertOk()->assertSee('Recipient Inventory')->assertSee('Office laptop')
        ->assertSee('15,000.00')->assertDontSee('Renamed item')->assertDontSee('Renamed Inventory');
});

it('issues a selected batch to an employee record without requiring a recipient account', function () {
    inventoryUiActor();
    $employee = inventoryUiEmployee('IUI-003', 'No account recipient');
    $issuer = inventoryUiEmployee('IUI-004', 'No account issuer');
    $first = inventoryUiItem('Laptop');
    $second = inventoryUiItem('Monitor');
    expect(User::query()->where('employee_id', $employee->id)->exists())->toBeFalse();

    Livewire::test(ItemsPage::class)
        ->set('selectedIds', [$first, $second])
        ->call('startMovement', 'issue')
        ->call('employeePicked', 'inventory-recipient', $employee->id)
        ->call('employeePicked', 'inventory-issuer', $issuer->id)
        ->set('movementDate', '2026-02-03')
        ->set('mrNumber', 'OFFICE-2026-017')
        ->call('saveMovement')->assertHasNoErrors();

    $receipt = InventoryReceipt::query()->sole();
    expect($receipt->employee_id)->toBe($employee->id)
        ->and($receipt->issuer_employee_id)->toBe($issuer->id)
        ->and($receipt->mr_number)->toBe('OFFICE-2026-017')
        ->and($receipt->lines()->count())->toBe(2)
        ->and(InventoryItem::query()->where('current_receipt_id', $receipt->id)->count())->toBe(2);
});

it('refuses a forged inventory write after the role loses all-data access', function () {
    $actor = inventoryUiActor();
    $component = Livewire::test(ItemsPage::class)->call('openAdd')->set('form.name', 'Refused item')->set('form.cost', '100')->set('form.acquired_on', '2026-01-02');
    $beforeAudit = AuditLog::query()->count();
    Role::query()->findOrFail($actor->role_id)->forceFill(['all_data' => false])->save();
    $this->actingAs($actor->fresh());
    app()->forgetInstance(Access::class);

    $component->call('saveItem')->assertForbidden();

    expect(InventoryItem::query()->count())->toBe(0)
        ->and(AuditLog::query()->count())->toBe($beforeAudit);
});

it('keeps an invalid selected item from partially issuing the valid selection', function () {
    inventoryUiActor();
    $employee = inventoryUiEmployee('IUI-005', 'Recipient');
    $issuer = inventoryUiEmployee('IUI-006', 'Issuer');
    $item = inventoryUiItem();

    Livewire::test(ItemsPage::class)
        ->set('selectedIds', [$item, (string) Str::ulid()])
        ->call('startMovement', 'issue')
        ->call('employeePicked', 'inventory-recipient', $employee->id)
        ->call('employeePicked', 'inventory-issuer', $issuer->id)
        ->set('movementDate', '2026-02-03')
        ->set('mrNumber', 'OFFICE-2026-017')
        ->call('saveMovement')->assertHasErrors('inventory');

    expect(InventoryReceipt::query()->count())->toBe(0)
        ->and(InventoryItem::query()->findOrFail($item)->status)->toBe('IN_STOCK');
});

it('records a linked receipt correction through the employee pickers', function () {
    inventoryUiActor();
    $original = inventoryUiEmployee('IUI-007', 'Original recipient');
    $correct = inventoryUiEmployee('IUI-008', 'Correct recipient');
    $issuer = inventoryUiEmployee('IUI-009', 'Issuer');
    $item = inventoryUiItem();
    $receiptId = app(IssueItems::class)->handle(app(Access::class), [$item], $original->id, $issuer->id, '2026-02-03', 'UI-MR-'.Str::random(8));

    $component = Livewire::test(ReceiptPage::class, ['id' => $receiptId])
        ->call('openCorrection')
        ->call('employeePicked', 'correction-recipient', $correct->id)
        ->assertSet('mrNumber', '')
        ->set('reason', 'Recipient selected in error; no physical return occurred.');
    $auditCount = AuditLog::query()->count();
    $originalNumber = InventoryReceipt::query()->findOrFail($receiptId)->mr_number;

    $component->call('correctReceipt')->assertHasErrors('inventory');
    $component->set('mrNumber', $originalNumber)->call('correctReceipt')->assertHasErrors('inventory');
    expect(InventoryReceipt::query()->count())->toBe(1)
        ->and(InventoryReceipt::query()->findOrFail($receiptId)->status)->toBe('OPEN')
        ->and(InventoryItem::query()->findOrFail($item)->current_receipt_id)->toBe($receiptId)
        ->and(AuditLog::query()->count())->toBe($auditCount);

    $component->set('mrNumber', 'CORRECTED-2026-004')->call('correctReceipt')->assertHasNoErrors();

    expect(InventoryReceipt::query()->findOrFail($receiptId)->status)->toBe('SUPERSEDED');
    $replacement = InventoryReceipt::query()->where('corrected_from_id', $receiptId)->sole();
    expect($replacement->employee_id)->toBe($correct->id)
        ->and($replacement->mr_number)->toBe('CORRECTED-2026-004');
    $this->get(route('inventory.receipts.show', ['id' => $receiptId]))->assertOk()->assertSee('Open the replacement MR');
});

it('edits catalog names through the owning command and refuses deleting a used category', function () {
    inventoryUiActor();
    Livewire::test(CatalogsPage::class)->call('openCatalog', 'category')->set('name', 'Computers')->call('saveCatalog')->assertHasNoErrors();
    $category = InventoryCategory::query()->sole();
    $item = inventoryUiItem();
    InventoryItem::query()->findOrFail($item)->forceFill(['category_id' => $category->id])->save();

    Livewire::test(CatalogsPage::class)->call('confirmDelete', 'category', $category->id)->call('deleteCatalog')->assertHasErrors('inventory');

    expect(InventoryCategory::query()->whereKey($category->id)->exists())->toBeTrue();
});

it('pins all-data Portal holdings and receipts to the signed-in employee', function () {
    inventoryUiActor();
    $own = inventoryUiEmployee('IUI-010', 'Own recipient');
    $foreign = inventoryUiEmployee('IUI-011', 'Foreign recipient');
    $issuer = inventoryUiEmployee('IUI-012', 'Issuer');
    $mine = inventoryUiItem('Own property');
    $theirs = inventoryUiItem('Foreign property');
    $ownReceipt = app(IssueItems::class)->handle(app(Access::class), [$mine], $own->id, $issuer->id, '2026-02-03', 'UI-MR-'.Str::random(8));
    $foreignReceipt = app(IssueItems::class)->handle(app(Access::class), [$theirs], $foreign->id, $issuer->id, '2026-02-03', 'UI-MR-'.Str::random(8));
    inventoryUiActor(true, $own->id, 'PORTAL');

    Livewire::test(EmployeeItems::class, ['employeeId' => $foreign->id, 'own' => true])
        ->assertSet('employeeId', $own->id)->assertSee('Own property')->assertDontSee('Foreign property')
        ->assertDontSee('Issue Items')->assertDontSee('Export CSV')
        ->call('pollForChanges')->assertDontSee('Export CSV');
    $this->get(route('portal.inventory.receipts.show', ['id' => $ownReceipt]))->assertOk()->assertSee('Own property');
    $this->get(route('portal.inventory.receipts.show', ['id' => $foreignReceipt]))->assertNotFound();
    $this->get(route('portal.inventory.receipts.print', ['id' => $foreignReceipt]))->assertNotFound();
    $this->get('/portal/inventory/export?own=0&employee='.$foreign->id)->assertNotFound();
});

it('shows no holdings or receipt to an unlinked Portal account', function () {
    inventoryUiActor();
    $employee = inventoryUiEmployee('IUI-013', 'Recipient');
    $issuer = inventoryUiEmployee('IUI-014', 'Issuer');
    $item = inventoryUiItem('Hidden property');
    $receipt = app(IssueItems::class)->handle(app(Access::class), [$item], $employee->id, $issuer->id, '2026-02-03', 'UI-MR-'.Str::random(8));
    inventoryUiActor(true, null, 'PORTAL');

    Livewire::test(EmployeeItems::class, ['employeeId' => $employee->id, 'own' => true])
        ->assertSee('No employee record is linked')->assertDontSee('Hidden property')->assertDontSee('Export CSV');
    $this->get(route('portal.inventory.receipts.show', ['id' => $receipt]))->assertNotFound();
    $this->get('/portal/inventory/export?employee='.$employee->id)->assertNotFound();
});

it('keeps employee-record holdings within the Employee Records row scope', function () {
    inventoryUiActor();
    $own = inventoryUiEmployee('IUI-015', 'Own recipient');
    $foreign = inventoryUiEmployee('IUI-016', 'Foreign recipient');
    $issuer = inventoryUiEmployee('IUI-017', 'Issuer');
    $item = inventoryUiItem('Scoped property');
    app(IssueItems::class)->handle(app(Access::class), [$item], $foreign->id, $issuer->id, '2026-02-03', 'UI-MR-'.Str::random(8));
    inventoryUiActor(false, $own->id, 'EMPLOYEES');

    Livewire::test(EmployeeItems::class, ['employeeId' => $foreign->id])->assertDontSee('Scoped property');
    $csv = $this->get(route('employees.inventory.export', ['employee' => $foreign->id, 'own' => '1']))->assertOk()->streamedContent();
    expect($csv)->not->toContain('Scoped property');
});

it('creates a receipt directly from the MR register with paginated serviceable stock', function () {
    inventoryUiActor();
    $employee = inventoryUiEmployee('IUI-DIRECT-1', 'Recipient');
    $issuer = inventoryUiEmployee('IUI-DIRECT-2', 'Issuer');
    $stock = [];
    for ($index = 1; $index <= 21; $index++) {
        $stock[] = inventoryUiItem('Available item '.$index, sprintf('AVAILABLE-%03d', $index));
    }
    $damaged = inventoryUiItem('Damaged stock');
    InventoryItem::query()->findOrFail($damaged)->forceFill(['condition' => 'DAMAGED'])->save();
    $alreadyIssued = inventoryUiItem('Already issued');
    app(IssueItems::class)->handle(app(Access::class), [$alreadyIssued], $employee->id, $issuer->id, '2026-02-03', 'UI-MR-'.Str::random(8));

    $this->get(route('inventory.receipts'))->assertOk()->assertSee('Issue Items');
    $component = Livewire::test(IssueReceipt::class)->call('open')
        ->assertSet('dialogOpen', true);
    $component->assertViewHas('stockItems', fn ($rows) => $rows->total() === 21 && $rows->getPageName() === 'issueStockPage');
    $component->assertDontSee('Damaged stock')->assertDontSee('Already issued')
        ->set('selectedIds', [$stock[0]])
        ->call('nextPage', 'issueStockPage')->assertSee('Available item 21')
        ->set('selectedIds', [$stock[0], $stock[20]])
        ->set('search', 'Available item 1');
    $component->assertViewHas('stockItems', fn ($rows) => $rows->currentPage() === 1);
    $component->call('employeePicked', 'issue-receipt-recipient', $employee->id)
        ->call('employeePicked', 'issue-receipt-issuer', $issuer->id)
        ->set('issuedOn', '2026-02-04')
        ->set('mrNumber', 'OFFICE-2026-025')
        ->call('save')->assertHasNoErrors();

    $receipt = InventoryReceipt::query()->whereDate('issued_on', '2026-02-04')->sole();
    $component->assertRedirect(route('inventory.receipts.show', ['id' => $receipt->id]));
    expect($receipt->lines()->pluck('item_id')->all())->toEqualCanonicalizing([$stock[0], $stock[20]])
        ->and($receipt->mr_number)->toBe('OFFICE-2026-025');
});

it('rechecks manager access before saving a directly opened issue receipt', function () {
    $actor = inventoryUiActor();
    $employee = inventoryUiEmployee('IUI-DIRECT-3', 'Recipient');
    $issuer = inventoryUiEmployee('IUI-DIRECT-4', 'Issuer');
    $item = inventoryUiItem();
    $component = Livewire::test(IssueReceipt::class)->call('open')
        ->set('selectedIds', [$item])
        ->call('employeePicked', 'issue-receipt-recipient', $employee->id)
        ->call('employeePicked', 'issue-receipt-issuer', $issuer->id);
    $auditCount = AuditLog::query()->count();
    Role::query()->findOrFail($actor->role_id)->forceFill(['all_data' => false])->save();
    $this->actingAs($actor->fresh());
    app()->forgetInstance(Access::class);

    $component->call('save')->assertForbidden();

    expect(InventoryReceipt::query()->count())->toBe(0)
        ->and(InventoryItem::query()->findOrFail($item)->status)->toBe('IN_STOCK')
        ->and(AuditLog::query()->count())->toBe($auditCount);
});

it('shows the entered MR number while keeping the generated record ID internal', function () {
    inventoryUiActor();
    $employee = inventoryUiEmployee('IUI-NUM-1', 'Recipient');
    $issuer = inventoryUiEmployee('IUI-NUM-2', 'Issuer');
    $item = inventoryUiItem('Numbered property');
    $mrNumber = 'OFFICE/2026-00017';
    $receiptId = app(IssueItems::class)->handle(app(Access::class), [$item], $employee->id, $issuer->id, '2026-02-03', $mrNumber);
    $receipt = InventoryReceipt::query()->findOrFail($receiptId);

    expect($receipt->code)->toBe('1')->not->toBe($mrNumber);
    $this->get(route('inventory.receipts'))->assertOk()->assertSee('MR No.')->assertSee($mrNumber);
    $this->get(route('inventory.receipts.show', ['id' => $receiptId]))->assertOk()->assertDontSee('System ID')->assertSee($mrNumber);
    $this->get(route('inventory.receipts.print', ['id' => $receiptId]))->assertOk()->assertSee('MR No.')->assertSee($mrNumber)->assertDontSee('System ID');
    $this->get(route('inventory.items.show', ['id' => $item]))->assertOk()->assertSee($mrNumber);

    $csv = $this->get(route('inventory.receipts.export', ['q' => $mrNumber]))->assertOk()->streamedContent();
    $csvLines = explode("\n", trim(ltrim($csv, "\xEF\xBB\xBF")));
    expect(array_slice(str_getcsv($csvLines[0], ',', '"', ''), 0, 2))->toBe(['MR No.', 'Issued on'])
        ->and(array_slice(str_getcsv($csvLines[1], ',', '"', ''), 0, 2))->toBe([$mrNumber, '2026-02-03']);
    $itemsCsv = $this->get(route('inventory.items.export'))->assertOk()->streamedContent();
    expect($itemsCsv)->toContain($mrNumber);

    inventoryUiActor(true, null, 'EMPLOYEES');
    Livewire::test(EmployeeItems::class, ['employeeId' => $employee->id])
        ->assertSee('Export CSV')->assertSeeHtml('/employees/'.$employee->id.'/inventory/export');
    $holdingsCsv = $this->get(route('employees.inventory.export', ['employee' => $employee->id]))->assertOk()->streamedContent();
    expect($holdingsCsv)->toContain($mrNumber);

    inventoryUiActor(true, $employee->id, 'PORTAL');
    Livewire::test(EmployeeItems::class, ['employeeId' => $employee->id, 'own' => true])->assertSee($mrNumber)->assertDontSee('Export CSV');
    $this->get(route('portal.inventory.receipts.show', ['id' => $receiptId]))->assertOk()->assertSee($mrNumber)->assertDontSee('System ID');
    $this->get(route('portal.inventory.receipts.print', ['id' => $receiptId]))->assertOk()->assertSee($mrNumber)->assertDontSee('System ID');
});

it('refuses missing and duplicate MR numbers without issuing stock or recording an audit', function (string $screen, string $mrNumber) {
    inventoryUiActor();
    $employee = inventoryUiEmployee('IUI-INVALID-1', 'Recipient');
    $issuer = inventoryUiEmployee('IUI-INVALID-2', 'Issuer');
    $issued = inventoryUiItem('Already issued property');
    app(IssueItems::class)->handle(app(Access::class), [$issued], $employee->id, $issuer->id, '2026-02-03', 'TAKEN-2026-001');
    $stock = inventoryUiItem('Unissued property');
    if ($screen === 'register') {
        $component = Livewire::test(IssueReceipt::class)->call('open')
            ->set('selectedIds', [$stock])
            ->call('employeePicked', 'issue-receipt-recipient', $employee->id)
            ->call('employeePicked', 'issue-receipt-issuer', $issuer->id)
            ->set('issuedOn', '2026-02-04');
        $action = 'save';
    } else {
        $component = Livewire::test(ItemsPage::class)->call('startMovement', 'issue', $stock)
            ->call('employeePicked', 'inventory-recipient', $employee->id)
            ->call('employeePicked', 'inventory-issuer', $issuer->id)
            ->set('movementDate', '2026-02-04');
        $action = 'saveMovement';
    }
    $auditCount = AuditLog::query()->count();

    $component->set('mrNumber', $mrNumber)->call($action)->assertHasErrors('inventory');

    expect(InventoryReceipt::query()->count())->toBe(1)
        ->and(InventoryItem::query()->findOrFail($stock)->status)->toBe('IN_STOCK')
        ->and(AuditLog::query()->count())->toBe($auditCount);
})->with(['register', 'items'])->with(['', 'TAKEN-2026-001']);

it('requires a new MR number when transferring but returns without creating another receipt', function () {
    inventoryUiActor();
    $original = inventoryUiEmployee('IUI-TRANSFER-1', 'Original');
    $recipient = inventoryUiEmployee('IUI-TRANSFER-2', 'Recipient');
    $issuer = inventoryUiEmployee('IUI-TRANSFER-3', 'Issuer');
    $item = inventoryUiItem();
    $originalReceiptId = app(IssueItems::class)->handle(app(Access::class), [$item], $original->id, $issuer->id, '2026-02-03', 'FIRST-2026-001');
    $component = Livewire::test(ItemsPage::class)->call('startMovement', 'transfer', $item)
        ->call('employeePicked', 'inventory-recipient', $recipient->id)
        ->call('employeePicked', 'inventory-issuer', $issuer->id)
        ->set('movementDate', '2026-02-04');
    $auditCount = AuditLog::query()->count();

    $component->call('saveMovement')->assertHasErrors('inventory');
    expect(InventoryReceipt::query()->count())->toBe(1)
        ->and(InventoryItem::query()->findOrFail($item)->current_receipt_id)->toBe($originalReceiptId)
        ->and(AuditLog::query()->count())->toBe($auditCount);
    $component->set('mrNumber', 'TRANSFER-2026-002')->call('saveMovement')->assertHasNoErrors();
    expect(InventoryReceipt::query()->findOrFail($originalReceiptId)->mr_number)->toBe('FIRST-2026-001');

    Livewire::test(ItemsPage::class)->call('startMovement', 'return', $item)
        ->assertSet('mrNumber', '')
        ->set('movementDate', '2026-02-05')->call('saveMovement')->assertHasNoErrors();
    expect(InventoryReceipt::query()->count())->toBe(2)
        ->and(InventoryItem::query()->findOrFail($item)->status)->toBe('IN_STOCK');
});
