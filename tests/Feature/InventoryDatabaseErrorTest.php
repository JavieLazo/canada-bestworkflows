<?php

declare(strict_types=1);

use App\Livewire\Inventory\CatalogsPage;
use App\Livewire\Inventory\CountsPage;
use App\Livewire\Inventory\DisposalsPage;
use App\Livewire\Inventory\IncidentsPage;
use App\Livewire\Inventory\ItemsPage;
use App\Livewire\Inventory\SignedReceipt;
use App\Models\AuditLog;
use App\Models\EmployeeProfile;
use App\Models\InventoryAttachment;
use App\Models\InventoryCategory;
use App\Models\InventoryCount;
use App\Models\InventoryDisposal;
use App\Models\InventoryIncident;
use App\Models\InventoryItem;
use App\Models\Role;
use App\Models\RoleModuleGrant;
use App\Models\User;
use App\Modules\Inventory\Application\Commands\IssueItems;
use App\Modules\Inventory\Application\Commands\RecordCount;
use App\Modules\Inventory\Application\Commands\RecordIncident;
use App\Modules\Inventory\Application\Commands\SaveCatalog;
use App\Modules\Inventory\Application\Commands\SaveItem;
use App\Modules\Inventory\Application\Queries\InventoryLifecycle;
use App\Support\Access;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

/** Inventory feedback reports database failures without exposing SQL or losing the user's draft. */
function invdbActor(): void
{
    $role = new Role;
    $role->forceFill(['name' => 'Inventory errors '.Str::random(8), 'all_data' => true, 'is_system' => false])->save();
    $grant = new RoleModuleGrant;
    $grant->forceFill(['role_id' => $role->id, 'module' => 'INVENTORY'])->save();
    test()->actingAs(User::factory()->create(['role_id' => $role->id]));
    app()->forgetInstance(Access::class);
}

function invdbItem(?string $locationId = null): InventoryItem
{
    $id = app(SaveItem::class)->handle(app(Access::class), null, ['code' => 'DB-'.Str::random(8), 'name' => 'Office printer', 'acquired_on' => '2026-01-01', 'cost' => '5000', 'location_id' => $locationId]);

    return InventoryItem::query()->findOrFail($id);
}

function invdbEmployee(): EmployeeProfile
{
    $employee = new EmployeeProfile;
    $employee->forceFill(['employee_code' => 'DB-'.Str::random(8), 'first_name' => 'Inventory', 'last_name' => 'Employee', 'employment_status' => 'Regular', 'date_hired' => '2024-01-01', 'is_active' => true])->save();

    return $employee;
}

function invdbFail(string $table, string $verb): void
{
    Exceptions::fake([QueryException::class]);
    $armed = true;
    DB::connection()->beforeExecuting(static function (string $sql, array $bindings) use ($table, $verb, &$armed): void {
        if ($armed && str_starts_with(strtolower($sql), $verb)
            && (str_contains($sql, '"'.$table.'"') || str_contains($sql, '`'.$table.'`'))) {
            $armed = false;
            throw new QueryException('private_inventory_connection', $sql, $bindings, new PDOException('Synthetic SQLSTATE[HY000] failure'));
        }
    });
}

/**
 * @template TComponent of \Livewire\Component
 *
 * @param  Testable<TComponent>  $component
 */
function invdbAssertFailure(Testable $component, string $action, string $message, ?string $errorKey = 'inventory'): void
{
    $auditCount = AuditLog::query()->count();
    $component->call($action)->assertSee($message)
        ->assertDontSee('private_inventory_connection')->assertDontSee('SQLSTATE')->assertDontSee('Connection:');
    if ($errorKey === null) {
        $component->assertSet('formError', $message);
    } else {
        $component->assertHasErrors($errorKey);
    }
    Exceptions::assertReported(fn (QueryException $error): bool => str_contains($error->getMessage(), 'private_inventory_connection'));
    expect(AuditLog::query()->count())->toBe($auditCount);
}

it('keeps an item draft open when the database refuses its save', function () {
    invdbActor();
    $component = Livewire::test(ItemsPage::class)->call('openAdd')
        ->set('form.code', 'OFFICE-001')->set('form.name', 'Office tablet')->set('form.cost', '1000');
    invdbFail('inventory_items', 'insert');

    invdbAssertFailure($component, 'saveItem', 'The item could not be saved. Try again.');

    $component->assertSet('itemDialog', true)->assertSet('form.code', 'OFFICE-001');
    expect(InventoryItem::query()->count())->toBe(0);
});

it('keeps an item when the database refuses its deletion', function () {
    invdbActor();
    $item = invdbItem();
    $component = Livewire::test(ItemsPage::class)->call('confirmDelete', $item->id);
    invdbFail('inventory_items', 'delete');

    invdbAssertFailure($component, 'deleteItem', 'The item could not be deleted. Try again.');

    $component->assertSet('deleteDialog', true);
    expect($item->fresh())->not->toBeNull();
});

it('keeps catalog errors generic when the database refuses a write', function (bool $deleting) {
    invdbActor();
    $component = Livewire::test(CatalogsPage::class);
    if ($deleting) {
        $id = app(SaveCatalog::class)->handle(app(Access::class), 'category', null, 'Computers');
        $component->call('confirmDelete', 'category', $id);
    } else {
        $component->call('openCatalog', 'category')->set('name', 'Computers');
    }
    invdbFail('inventory_categories', $deleting ? 'delete' : 'insert');

    invdbAssertFailure($component, $deleting ? 'deleteCatalog' : 'saveCatalog', $deleting ? 'The catalog entry could not be deleted. Try again.' : 'The catalog entry could not be saved. Try again.');

    $component->assertSet($deleting ? 'deleteDialog' : 'dialogOpen', true);
    expect(InventoryCategory::query()->count())->toBe($deleting ? 1 : 0);
})->with(['save' => false, 'delete' => true]);

it('keeps condition report errors generic when the database refuses a write', function (bool $reviewing) {
    invdbActor();
    $item = invdbItem();
    if ($reviewing) {
        $id = app(RecordIncident::class)->save(app(Access::class), ['item_id' => $item->id, 'kind' => 'DAMAGE', 'reported_on' => '2026-02-01', 'reference' => 'REPORT-001', 'details' => 'Cracked case.']);
        invdbActor();
        $component = Livewire::test(IncidentsPage::class)->call('openReview', $id)
            ->set('resolution', 'Repair confirmed.')->set('resolutionReference', 'REPAIR-001')->set('condition', 'SERVICEABLE');
    } else {
        $component = Livewire::test(IncidentsPage::class)->call('openAdd')->set('itemId', $item->id)
            ->set('reference', 'REPORT-001')->set('details', 'Cracked case.');
    }
    invdbFail('inventory_incidents', $reviewing ? 'update' : 'insert');

    invdbAssertFailure($component, $reviewing ? 'resolve' : 'save', $reviewing ? 'The report review could not be saved. Try again.' : 'The report could not be saved. Try again.', null);

    $component->assertSet($reviewing ? 'reviewOpen' : 'dialogOpen', true);
    expect(InventoryIncident::query()->count())->toBe($reviewing ? 1 : 0)
        ->and($item->fresh()->condition)->toBe($reviewing ? 'DAMAGED' : 'SERVICEABLE');
    if ($reviewing) {
        expect(InventoryIncident::query()->findOrFail($id)->status)->toBe('OPEN');
    }
})->with(['record' => false, 'review' => true]);

it('keeps count errors generic when the database refuses a write', function (bool $reviewing) {
    invdbActor();
    $location = app(SaveCatalog::class)->handle(app(Access::class), 'location', null, 'Main office');
    $item = invdbItem($location);
    if ($reviewing) {
        $sheet = app(InventoryLifecycle::class)->countSheet(app(Access::class), $location)->first();
        $id = app(RecordCount::class)->save(app(Access::class), ['location_id' => $location, 'counted_on' => '2026-02-01', 'reference' => 'COUNT-001', 'lines' => [['item_id' => $item->id, 'version' => $sheet->version, 'result' => 'FOUND']]]);
        invdbActor();
        $component = Livewire::test(CountsPage::class)->call('openReview', $id)
            ->set('resolution', 'All items found.')->set('resolutionReference', 'REVIEW-001');
    } else {
        $component = Livewire::test(CountsPage::class)->call('openAdd')->set('locationId', $location)
            ->set('reference', 'COUNT-001')->set('lines.0.result', 'FOUND');
    }
    invdbFail('inventory_counts', $reviewing ? 'update' : 'insert');

    invdbAssertFailure($component, $reviewing ? 'resolve' : 'save', $reviewing ? 'The count review could not be saved. Try again.' : 'The count could not be saved. Try again.', null);

    $component->assertSet($reviewing ? 'reviewOpen' : 'dialogOpen', true);
    expect(InventoryCount::query()->count())->toBe($reviewing ? 1 : 0);
    if ($reviewing) {
        expect(InventoryCount::query()->findOrFail($id)->status)->toBe('OPEN');
    }
})->with(['record' => false, 'review' => true]);

it('keeps the count sheet intact when an unexpected item lookup fails', function () {
    invdbActor();
    $item = invdbItem();
    $component = Livewire::test(CountsPage::class)->call('openAdd')->set('unexpectedCode', $item->code);
    invdbFail('inventory_items', 'select');

    invdbAssertFailure($component, 'addUnexpected', 'The item could not be added to the count sheet. Try again.', null);

    $component->assertSet('dialogOpen', true)->assertSet('sheet', [])->assertSet('lines', [])->assertSet('unexpectedCode', $item->code);
});

it('keeps disposal errors generic and preserves stock when the database refuses its write', function () {
    Storage::fake('local');
    invdbActor();
    $item = invdbItem();
    $item->forceFill(['condition' => 'UNSERVICEABLE'])->save();
    $authority = invdbEmployee();
    $component = Livewire::test(DisposalsPage::class)->call('openAdd')->set('itemId', $item->id)
        ->call('employeePicked', 'inventory-disposal-authority', $authority->id)
        ->set('method', 'Recycling')->set('reason', 'Beyond repair')->set('authorizationReference', 'AUTH-001')
        ->set('upload', UploadedFile::fake()->image('authority.png'));
    invdbFail('inventory_disposals', 'insert');

    invdbAssertFailure($component, 'save', 'The disposal could not be saved. Try again.', null);

    $component->assertSet('dialogOpen', true);
    expect(InventoryDisposal::query()->count())->toBe(0)
        ->and(InventoryAttachment::query()->count())->toBe(0)
        ->and($item->fresh()->status)->toBe('IN_STOCK')
        ->and(Storage::disk('local')->allFiles('inventory-evidence'))->toBe([]);
});

it('keeps signed receipt errors generic and removes staged evidence when the database refuses its write', function () {
    Storage::fake('local');
    invdbActor();
    $item = invdbItem();
    $recipient = invdbEmployee();
    $issuer = invdbEmployee();
    $receipt = app(IssueItems::class)->handle(app(Access::class), [$item->id], $recipient->id, $issuer->id, '2026-02-01', 'MR-001');
    $component = Livewire::test(SignedReceipt::class, ['receiptId' => $receipt])->set('upload', UploadedFile::fake()->image('signed.png'));
    invdbFail('inventory_attachments', 'insert');

    invdbAssertFailure($component, 'attach', 'The signed MR could not be attached. Try again.', 'upload');

    expect(InventoryAttachment::query()->count())->toBe(0)
        ->and(Storage::disk('local')->allFiles('inventory-evidence'))->toBe([])
        ->and($item->fresh()->current_receipt_id)->toBe($receipt);
});
