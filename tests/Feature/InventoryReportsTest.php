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
use App\Modules\Inventory\Contracts\Views\ReportRow;
use App\Support\Access;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Reports share scoped projections with CSV and retain unresolved count
 * exceptions without changing custody. A report with a Cost column also
 * totals it: `InventoryReports::value` adds up the acquisition cost of every
 * item the report lists under the same manager check and filters as its
 * rows — never the page on screen, never an item the table leaves out —
 * counts an item with no cost as adding nothing and says how many there
 * are, and splits Stock on hand by category. The screen, the cards above it
 * and the CSV's closing row all read that one figure.
 */
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

function invreportItem(Access $actor, string $name, ?string $category = null, ?string $location = null, float $cost = 1250.0): string
{
    return app(SaveItem::class)->handle($actor, null, ['code' => 'RPT-'.Str::slug($name), 'name' => $name, 'serial_no' => '', 'acquired_on' => '2026-01-01', 'cost' => $cost, 'category_id' => $category, 'location_id' => $location, 'remarks' => '']);
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
    // The one item this selection lists, then the total of that selection.
    expect(iterator_to_array(($export->rows)(), false))->toBe([$filtered->items()[0]->cells, ['Total', '1 item', '', '', '', '', '', '', '1250.00']]);
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

/*
|--------------------------------------------------------------------------
| What a report's items add up to
|--------------------------------------------------------------------------
*/

it('totals the cost of every item a report lists, across its pages, and counts the ones with no cost', function () {
    $actor = invreportActor();
    $technology = app(SaveCatalog::class)->handle($actor, 'category', null, 'Technology');
    $furniture = app(SaveCatalog::class)->handle($actor, 'category', null, 'Furniture');
    invreportItem($actor, 'Stock laptop', $technology, cost: 100.10);
    invreportItem($actor, 'Stock tablet', $technology, cost: 0.0);
    invreportItem($actor, 'Stock desk', $furniture, cost: 200.20);
    invreportItem($actor, 'Stock crate', cost: 50.0);
    $reports = app(InventoryReports::class);

    // One row a page: the total still covers all four.
    expect($reports->rows($actor, 'stock', '', '', 1, 1)->count())->toBe(1)
        ->and($reports->value($actor, 'stock'))->toBe([
            'items' => 4, 'value' => 350.3, 'without_cost' => 1,
            'categories' => [
                ['name' => 'Furniture', 'items' => 1, 'value' => 200.2, 'without_cost' => 0],
                ['name' => 'Technology', 'items' => 2, 'value' => 100.1, 'without_cost' => 1],
                ['name' => '', 'items' => 1, 'value' => 50.0, 'without_cost' => 0],
            ],
        ]);
});

it('totals only what the report’s own rule, search and employee filter leave in', function () {
    $actor = invreportActor();
    $one = invreportEmployee('One');
    $two = invreportEmployee('Two');
    $laptop = invreportItem($actor, 'Issued laptop', cost: 900.0);
    $phone = invreportItem($actor, 'Issued phone', cost: 300.0);
    $chair = invreportItem($actor, 'Issued chair', cost: 75.5);
    invreportItem($actor, 'Shelf laptop', cost: 1000.0);
    app(IssueItems::class)->handle($actor, [$laptop, $phone], $one, $two, '2026-02-01', 'VAL-2026-001');
    app(IssueItems::class)->handle($actor, [$chair], $two, $two, '2026-02-01', 'VAL-2026-002');
    $reports = app(InventoryReports::class);

    // Holdings leave the item still on the shelf out; stock holds only it.
    expect($reports->value($actor, 'holdings'))->toMatchArray(['items' => 3, 'value' => 1275.5, 'without_cost' => 0, 'categories' => []])
        ->and($reports->value($actor, 'stock'))->toMatchArray(['items' => 1, 'value' => 1000.0])
        // One employee's holdings, then one searched item of them.
        ->and($reports->value($actor, 'holdings', '', $one))->toMatchArray(['items' => 2, 'value' => 1200.0])
        ->and($reports->value($actor, 'holdings', 'phone', $one))->toMatchArray(['items' => 1, 'value' => 300.0])
        ->and($reports->value($actor, 'holdings', 'laptop'))->toMatchArray(['items' => 1, 'value' => 900.0])
        ->and($reports->value($actor, 'holdings', 'no such item'))->toMatchArray(['items' => 0, 'value' => 0.0, 'without_cost' => 0]);

    // Every total is the sum of exactly the rows the same call lists.
    foreach ([['holdings', '', ''], ['holdings', '', $one], ['holdings', 'laptop', ''], ['stock', '', '']] as [$kind, $search, $employee]) {
        $rows = $reports->rows($actor, $kind, $search, $employee, 1, 100);
        $summed = array_sum(array_map(static fn (ReportRow $row): float => (float) $row->cells[8], $rows->items()));
        $value = $reports->value($actor, $kind, $search, $employee);
        expect($value['items'] ?? null)->toBe($rows->total())
            ->and($value['value'] ?? null)->toBe($summed);
    }
});

it('totals the exceptions report over its exception rule, and has no value for the movement history', function () {
    $actor = invreportActor();
    $location = app(SaveCatalog::class)->handle($actor, 'location', null, 'Office');
    $counted = invreportItem($actor, 'Counted laptop', null, $location, cost: 640.0);
    invreportItem($actor, 'Sound laptop', null, $location, cost: 999.0);
    $count = new InventoryCount;
    $count->forceFill(['location_id' => $location, 'location_name' => 'Office', 'counted_on' => '2026-02-01', 'reference' => 'COUNT-VALUE', 'notes' => '', 'status' => 'OPEN', 'actor_id' => (int) $actor->userId, 'actor_name' => $actor->fullName])->save();
    InventoryCountLine::query()->create(['count_id' => $count->id, 'item_id' => $counted, 'item_snapshot' => ['name' => 'Counted laptop'], 'result' => 'NOT_FOUND', 'notes' => '']);
    $reports = app(InventoryReports::class);

    expect($reports->value($actor, 'exceptions'))->toMatchArray(['items' => 1, 'value' => 640.0, 'categories' => []])
        ->and($reports->value($actor, 'movements'))->toBeNull();

    // The cards above the report carry the register-wide figure of each item report.
    expect($reports->totals($actor)['values'])->toBe([
        'stock' => ['items' => 2, 'value' => 1639.0, 'without_cost' => 0],
        'issued' => ['items' => 0, 'value' => 0.0, 'without_cost' => 0],
        'exceptions' => ['items' => 1, 'value' => 640.0, 'without_cost' => 0],
    ]);
});

it('refuses the value to anyone who may not read the report’s rows', function () {
    $manager = invreportActor();
    invreportItem($manager, 'Guarded laptop');
    $reports = app(InventoryReports::class);

    foreach ([[false, ModuleCode::INVENTORY], [true, ModuleCode::EMPLOYEES]] as [$all, $module]) {
        $actor = invreportActor($all, $module);
        expect(fn () => $reports->value($actor, 'stock'))->toThrow(HttpException::class);
    }
    expect(fn () => $reports->value(invreportActor(), 'everything'))->toThrow(HttpException::class);
});

it('closes the CSV with one total row under the same filters, and none on the movement history', function () {
    $actor = invreportActor();
    $person = invreportEmployee('One');
    $priced = invreportItem($actor, 'Priced laptop', cost: 1250.0);
    $unpriced = invreportItem($actor, 'Unpriced laptop', cost: 0.0);
    $other = invreportItem($actor, 'Other phone', cost: 400.0);
    app(IssueItems::class)->handle($actor, [$priced, $unpriced, $other], $person, $person, '2026-02-01', 'CSV-2026-001');

    $rows = iterator_to_array((app(InventoryReportsExport::class)->export($actor, ['report' => 'holdings', 'q' => 'laptop', 'employee' => $person])->rows)(), false);
    expect($rows)->toHaveCount(3)
        ->and(array_column($rows, 1))->toBe(['Priced laptop', 'Unpriced laptop', '2 items; 1 item has no cost recorded'])
        ->and($rows[2])->toBe(['Total', '2 items; 1 item has no cost recorded', '', '', '', '', '', '', '1250.00']);

    // The exceptions report has one more column; the total stays under Cost.
    $exceptions = app(InventoryReportsExport::class)->export($actor, ['report' => 'exceptions']);
    expect(iterator_to_array(($exceptions->rows)(), false))->toBe([['Total', '0 items', '', '', '', '', '', '', '0.00', '']]);

    $movements = iterator_to_array((app(InventoryReportsExport::class)->export($actor, ['report' => 'movements'])->rows)(), false);
    expect($movements)->toHaveCount(3)
        ->and(array_column($movements, 0))->not->toContain('Total');
});

it('shows the total value and the items with no cost on the report, and the category split on Stock on hand only', function () {
    $actor = invreportActor();
    $technology = app(SaveCatalog::class)->handle($actor, 'category', null, 'Technology');
    invreportItem($actor, 'Shelf laptop', $technology, cost: 2000.0);
    invreportItem($actor, 'Shelf cable', $technology, cost: 0.0);
    invreportItem($actor, 'Shelf crate', cost: 500.0);

    $page = Livewire::test(ReportsPage::class)->set('report', 'stock');
    $html = $page->html();
    expect($html)->toContain('data-report-value')
        ->toContain('Total Value')
        ->toContain('$2,500.00')
        ->toContain('1 item has no cost recorded, so the total leaves it out.')
        ->toContain('Stock Value by Category')
        ->toContain('No category');
    // The Cost column reads as money.
    expect($html)->toContain('$2,000.00')->toContain('$500.00');

    // Searching narrows the total with the rows.
    expect($page->set('search', 'crate')->html())->toContain('data-report-total>$500.00<')
        ->not->toContain('has no cost recorded');

    // Holdings: a total, no category split. Movements: neither.
    $holdings = $page->set('search', '')->set('report', 'holdings')->html();
    expect($holdings)->toContain('data-report-total>$0.00<')->not->toContain('Stock Value by Category');
    expect($page->set('report', 'movements')->html())->not->toContain('data-report-value')->not->toContain('Stock Value by Category');
});
