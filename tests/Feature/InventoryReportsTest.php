<?php

declare(strict_types=1);

use App\Domain\Enums\ModuleCode;
use App\Livewire\Inventory\ReportsPage;
use App\Livewire\Inventory\Support\InventoryReportsExport;
use App\Models\EmployeeProfile;
use App\Models\InventoryCount;
use App\Models\InventoryCountLine;
use App\Models\InventoryItem;
use App\Models\Role;
use App\Models\RoleModuleGrant;
use App\Models\User;
use App\Modules\Inventory\Application\Commands\IssueItems;
use App\Modules\Inventory\Application\Commands\SaveCatalog;
use App\Modules\Inventory\Application\Commands\SaveItem;
use App\Modules\Inventory\Application\Queries\InventoryReports;
use App\Support\Access;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** Reports share scoped projections with CSV and retain unresolved count exceptions without changing custody. */
function invreportActor(bool $all = true, string $module = ModuleCode::INVENTORY): Access
{
    $role = Role::query()->create(['name' => 'InvReport '.Str::random(8), 'all_data' => $all, 'is_system' => false]);
    $grant = new RoleModuleGrant;
    $grant->forceFill(['role_id' => $role->id, 'module' => $module])->save();
    $user = User::factory()->create(['role_id' => $role->id]);
    test()->actingAs($user);
    app()->forgetInstance(Access::class);

    return Access::forActor($user);
}

function invreportEmployee(string $name): string
{
    $row = new EmployeeProfile;
    $row->forceFill(['employee_code' => (string) (EmployeeProfile::query()->count() + 1), 'first_name' => $name, 'last_name' => 'Holder', 'date_hired' => '2024-01-01', 'employment_status' => 'Regular', 'is_active' => true])->save();

    return $row->id;
}

function invreportItem(Access $actor, string $name, ?string $category = null, ?string $location = null): string
{
    return app(SaveItem::class)->handle($actor, null, ['code' => 'RPT-'.Str::slug($name), 'name' => $name, 'serial_no' => '', 'acquired_on' => '2026-01-01', 'cost' => 1250, 'category_id' => $category, 'location_id' => $location, 'remarks' => '']);
}

it('requires Inventory and all-data on report queries, pages and downloads', function () {
    $reports = app(InventoryReports::class);
    foreach ([[false, ModuleCode::INVENTORY], [true, ModuleCode::EMPLOYEES]] as [$all, $module]) {
        $actor = invreportActor($all, $module);
        expect(fn () => $reports->rows($actor))->toThrow(HttpException::class)
            ->and(fn () => $reports->totals($actor))->toThrow(HttpException::class)
            ->and(fn () => app(InventoryReportsExport::class)->export($actor, []))->toThrow(HttpException::class);
        $this->getJson(route('inventory.reports'))->assertForbidden();
    }
});

it('projects catalog and receipt labels, filters employees and clamps the report page', function () {
    $actor = invreportActor();
    $person = invreportEmployee('One');
    $other = invreportEmployee('Two');
    $category = app(SaveCatalog::class)->handle($actor, 'category', null, 'Technology');
    $location = app(SaveCatalog::class)->handle($actor, 'location', null, 'Supply room');
    $first = invreportItem($actor, 'First laptop', $category, $location);
    $second = invreportItem($actor, 'Second laptop');
    invreportItem($actor, 'Stock laptop');
    app(IssueItems::class)->handle($actor, [$first], $person, $other, '2026-02-01', 'RPT-2026-001');
    app(IssueItems::class)->handle($actor, [$second], $other, $other, '2026-02-01', 'RPT-2026-002');
    $reports = app(InventoryReports::class);
    $filtered = $reports->rows($actor, 'holdings', 'laptop', $person, 999, 1);
    expect($filtered->currentPage())->toBe(1)->and($filtered->total())->toBe(1)
        ->and($filtered->items()[0]->cells)->toBe(['RPT-first-laptop', 'First laptop', 'ISSUED', 'SERVICEABLE', 'One Holder', 'RPT-2026-001', 'Technology', 'Supply room', '1250.00']);
    $stock = $reports->rows($actor, 'stock');
    expect($stock->total())->toBe(1)->and($stock->items()[0]->cells[1])->toBe('Stock laptop');
    $export = app(InventoryReportsExport::class)->export($actor, ['report' => 'holdings', 'q' => 'laptop', 'employee' => $person]);
    expect(iterator_to_array(($export->rows)()))->toBe([$filtered->items()[0]->cells]);
    $movement = $reports->rows($actor, 'movements', 'First laptop', $person)->items()[0];
    expect($movement->cells[0])->toBe('2026-02-01')->and($movement->cells[5])->toBe('One Holder')->and($movement->cells[6])->toBe('RPT-2026-001');
});

it('reports unresolved count discrepancies separately from physical condition', function () {
    $actor = invreportActor();
    $location = app(SaveCatalog::class)->handle($actor, 'location', null, 'Office');
    $item = invreportItem($actor, 'Counted laptop', null, $location);
    $count = new InventoryCount;
    $count->forceFill(['location_id' => $location, 'location_name' => 'Office', 'counted_on' => '2026-02-01', 'reference' => 'COUNT-TEST', 'notes' => '', 'status' => 'OPEN', 'actor_id' => (int) $actor->userId, 'actor_name' => $actor->fullName])->save();
    InventoryCountLine::query()->create(['count_id' => $count->id, 'item_id' => $item, 'item_snapshot' => ['name' => 'Counted laptop'], 'result' => 'NOT_FOUND', 'notes' => 'Awaiting check']);
    $reports = app(InventoryReports::class);
    $exception = $reports->rows($actor, 'exceptions')->items()[0];
    expect($exception->cells[3])->toBe('SERVICEABLE')->and($exception->cells[9])->toContain('Open count: not found')
        ->and($reports->totals($actor)['exceptions'])->toBe(1)
        ->and(InventoryItem::query()->findOrFail($item)->status)->toBe('IN_STOCK');
    $count->update(['status' => 'RESOLVED']);
    expect($reports->rows($actor, 'exceptions')->total())->toBe(0);
});

it('renders all report modes and applies the employee-picker event contract', function () {
    $actor = invreportActor();
    $employee = invreportEmployee('Employee');
    $item = invreportItem($actor, 'Report laptop');
    app(IssueItems::class)->handle($actor, [$item], $employee, $employee, '2026-02-01', 'RPT-2026-003');
    Livewire::test(ReportsPage::class)
        ->assertSee('Report laptop')
        ->dispatch('kit-employee-picked', name: 'inventory-report-employee', id: $employee)
        ->assertSet('employeeId', $employee)
        ->set('report', 'movements')->assertSee('ISSUED')
        ->set('report', 'exceptions')->assertSee('No items match this report.')
        ->call('clearEmployee')->assertSet('employeeId', '')
        ->set('report', 'stock')->assertSee('No items match this report.');
});
