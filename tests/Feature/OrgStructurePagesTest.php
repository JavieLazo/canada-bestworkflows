<?php

declare(strict_types=1);

use App\Domain\Enums\ModuleCode;
use App\Livewire\Records\DepartmentsPage;
use App\Livewire\Records\PositionsPage;
use App\Models\AuditLog;
use App\Models\EmployeeProfile;
use App\Models\OrgNode;
use App\Models\Position;
use App\Models\Role;
use App\Models\RoleModuleGrant;
use App\Models\User;
use App\Support\Access;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Once;
use Illuminate\Support\Str;
use Livewire\Livewire;

/**
 * The Departments & Units and Position pages: both sit behind the RECORDS
 * gate under General Records (Position, Departments, Document, Employment),
 * /records still lands on Position, and the sidebar lights the page in
 * view. Each draws the approved mockup's columns, wording and row menus —
 * Delete disabled with its reason, Move up / Move down disabled at the ends
 * and absent for a Not placed position — and every action re-checks Access
 * through its command, so a RECORDS reader without all_data changes nothing.
 */

/** @param list<string> $modules */
function orgpageActor(array $modules = [ModuleCode::RECORDS], bool $allData = true): User
{
    $role = new Role;
    $role->forceFill(['name' => 'Org page '.Str::random(6), 'all_data' => $allData, 'is_system' => false])->save();
    foreach ($modules as $module) {
        $grant = new RoleModuleGrant;
        $grant->forceFill(['role_id' => $role->getKey(), 'module' => $module])->save();
    }
    $user = User::factory()->create(['role_id' => $role->getKey()]);
    test()->actingAs($user);
    app()->forgetInstance(Access::class);

    return $user;
}

function orgpageNode(string $name, ?OrgNode $department = null, int $order = 0, ?string $description = null): OrgNode
{
    $node = new OrgNode;
    $node->forceFill([
        'code' => (string) (OrgNode::query()->count() + 1),
        'kind' => $department === null ? OrgNode::KIND_DEPARTMENT : OrgNode::KIND_UNIT,
        'name' => $name,
        'description' => $description,
        'parent_id' => $department?->id,
        'sort_order' => $order,
    ])->save();

    return $node;
}

function orgpagePosition(string $name, ?OrgNode $node = null, int $order = 0): Position
{
    $position = new Position;
    $position->forceFill(['code' => (string) (Position::query()->count() + 1), 'name' => $name, 'org_node_id' => $node?->id, 'sort_order' => $order])->save();

    return $position;
}

function orgpageHolder(string $code, Position $position, bool $active = true): EmployeeProfile
{
    $employee = new EmployeeProfile;
    $employee->forceFill([
        'employee_code' => $code, 'first_name' => 'Emp', 'last_name' => $code, 'position' => $position->name,
        'position_id' => $position->id, 'employment_status' => 'Regular', 'date_hired' => '2024-01-01', 'is_active' => $active,
    ])->save();

    return $employee;
}

/**
 * The text of the row whose wire:key is `$key`, whitespace collapsed.
 */
function orgpageRow(string $html, string $key): string
{
    $doc = new DOMDocument;
    @$doc->loadHTML('<?xml encoding="utf-8"?>'.$html);
    $rows = (new DOMXPath($doc))->query('//tr[@*[name()="wire:key"]="'.$key.'"]');
    $row = $rows === false ? null : $rows->item(0);

    return $row instanceof DOMElement ? trim((string) preg_replace('/\s+/', ' ', $row->textContent)) : '';
}

/**
 * Where `$needle` first appears in `$html`; a missing needle fails the test.
 *
 * @param  non-empty-string  $needle
 */
function orgpageAt(string $html, string $needle): int
{
    $parts = explode($needle, $html, 2);
    if (count($parts) < 2) {
        throw new LogicException("\"{$needle}\" is not on the page.");
    }

    return strlen($parts[0]);
}

/* |---| Routes and navigation |---| */

it('gates both pages on RECORDS and keeps /records landing on Position', function () {
    $this->get('/records/departments')->assertRedirect(route('login'));

    orgpageActor([ModuleCode::LEAVES], false);
    $this->get('/records/departments')->assertRedirect('/leaves');
    $this->get('/records/positions')->assertRedirect('/leaves');

    orgpageActor([ModuleCode::RECORDS], false);
    $this->get('/records')->assertRedirect('/records/positions');
    $this->get('/records/positions')->assertOk()->assertSee('Job positions offered when hiring or editing an employee record. Place each one in a department or in one of its units; the same title may be entered in more than one.');
    $this->get('/records/departments')->assertOk()->assertSee('Departments &amp; Units', false);
});

it('lists Position, Departments, Document and Employment under General Records and lights the page in view', function () {
    orgpageActor();
    /** @var list<array{code: string, groups?: list<array{label: string, items?: list<array{label: string, path: string}>}>}> $modules */
    $modules = config('modules');
    $records = array_values(array_filter($modules, static fn (array $module): bool => $module['code'] === ModuleCode::RECORDS))[0];
    $general = $records['groups'][0]['items'] ?? [];
    expect($records['groups'][0]['label'] ?? null)->toBe('General Records')
        ->and(array_column($general, 'label'))->toBe(['Position', 'Departments', 'Document', 'Employment'])
        ->and(array_column($general, 'path'))->toBe(['/records/positions', '/records/departments', '/records/documents', '/records/employment']);

    $current = static fn (string $html): array => preg_match_all('/<a href="[^"]*(\/records[^"]*)" class="[^"]*"\s+aria-current="page"\s*>/', $html, $m) ? $m[1] : [];
    expect($current((string) $this->get('/records/departments')->getContent()))->toBe(['/records/departments'])
        ->and($current((string) $this->get('/records/positions')->getContent()))->toBe(['/records/positions']);
});

/* |---| Departments & Units |---| */

it('draws each department with its units under it, the mockup columns and the row menus', function () {
    orgpageActor();
    $eng = orgpageNode('Engineering Department', order: 1, description: 'Builds the products.');
    $pres = orgpageNode('Office of the President', order: 0);
    $dev = orgpageNode('Software Development', $eng, 0, 'Designs and builds the products.');
    $qa = orgpageNode('Quality', $eng, 1);
    orgpagePosition('Engineer', $eng);
    orgpagePosition('Lead', $eng);
    foreach (['Developer', 'Tester', 'Architect'] as $title) {
        orgpagePosition($title, $dev);
    }

    $html = Livewire::test(DepartmentsPage::class)->html();

    expect($html)->toContain('Departments, and the units inside each one, in the order you set. Each position is placed in a department or in one of its units on the Position page.')
        ->toContain('placeholder="Search ID, name or description"')
        ->toContain('Add Department')
        ->not->toContain('Export CSV')
        ->not->toContain('Import CSV')
        ->toContain('departments and units');
    // HR order: the President's office, then Engineering followed by its two units.
    expect(orgpageAt($html, 'Office of the President'))->toBeLessThan(orgpageAt($html, 'Engineering Department'))
        ->and(orgpageAt($html, 'Engineering Department'))->toBeLessThan(orgpageAt($html, 'Software Development'))
        ->and(orgpageAt($html, 'Software Development'))->toBeLessThan(orgpageAt($html, 'Quality'));

    expect(orgpageRow($html, 'node-'.$eng->id))->toContain('Engineering Department Builds the products.')
        ->toContain('Add Unit')
        ->toContain('Delete · has 2 units, 2 positions')
        ->and(orgpageRow($html, 'node-'.$dev->id))->toContain('└ Software Development')
        ->toContain('Delete · has 3 positions')
        ->not->toContain('Add Unit')
        ->and(orgpageRow($html, 'node-'.$qa->id))->toContain('Delete')
        ->not->toContain('Delete · has')
        ->and(orgpageRow($html, 'node-'.$pres->id))->not->toContain('Delete · has');
});

it('adds a department, adds a unit with its department preselected, and edits it through the page', function () {
    orgpageActor();
    $hr = orgpageNode('HR Department');

    Livewire::test(DepartmentsPage::class)
        ->call('openAddDepartment')
        ->set('formName', 'Finance')
        ->call('save')
        ->assertSet('dialogOpen', false);
    expect(OrgNode::query()->where('name', 'Finance')->where('kind', 'DEPARTMENT')->exists())->toBeTrue();

    $page = Livewire::test(DepartmentsPage::class)
        ->call('openAddUnit', $hr->id)
        ->assertSet('formDepartmentId', $hr->id)
        ->assertSet('dialogKind', 'UNIT')
        ->assertSee('Unit Name')
        ->assertDontSee('Moving a unit to another department')
        ->set('formName', 'People Operations')
        ->call('save')
        ->assertSet('dialogOpen', false);
    $unit = OrgNode::query()->where('name', 'People Operations')->firstOrFail();
    expect($unit->parent_id)->toBe($hr->id);

    $page->call('startEdit', $unit->id)
        ->assertSet('formName', 'People Operations')
        ->assertSee('Edit unit')
        ->assertSee('Moving a unit to another department takes its positions, and the people who hold them, along with it. Employment History is not changed.')
        ->assertSee('Save Changes');
});

it('requires a name inline and lets a RECORDS reader without all_data change nothing', function () {
    orgpageActor();
    Livewire::test(DepartmentsPage::class)
        ->call('openAddDepartment')
        ->set('formName', '  ')
        ->call('save')
        ->assertSet('formError', 'Name is required.')
        ->assertSet('dialogOpen', true);
    expect(OrgNode::query()->count())->toBe(0);

    $hr = orgpageNode('HR');
    $empty = orgpageNode('Empty');
    orgpageActor([ModuleCode::RECORDS], false);
    Livewire::test(DepartmentsPage::class)
        ->call('openAddDepartment')->set('formName', 'Finance')->call('save')
        ->call('openAddUnit', $hr->id)->set('formName', 'Payroll')->call('save')
        ->call('move', $empty->id, 'up')
        ->call('confirmDelete', $empty->id)->call('deleteConfirmed');

    expect(OrgNode::query()->pluck('name')->sort()->values()->all())->toBe(['Empty', 'HR'])
        ->and($empty->fresh()?->sort_order)->toBe(0)
        ->and(AuditLog::query()->count())->toBe(0);
});

it('moves and deletes from the row menu', function () {
    orgpageActor();
    $a = orgpageNode('Alpha', order: 0);
    $b = orgpageNode('Bravo', order: 1);

    Livewire::test(DepartmentsPage::class)->call('move', $b->id, 'up');
    expect([$b->fresh()?->sort_order, $a->fresh()?->sort_order])->toBe([0, 1]);

    Livewire::test(DepartmentsPage::class)->call('confirmDelete', $a->id)->call('deleteConfirmed');
    expect(OrgNode::query()->whereKey($a->id)->exists())->toBeFalse();
});

/* |---| Position |---| */

it('draws the Position page columns, placements, holders and row menus', function () {
    orgpageActor();
    $hr = orgpageNode('HR Department');
    $people = orgpageNode('People Operations', $hr);
    $officer = orgpagePosition('Administrative Officer', $people);
    $head = orgpagePosition('HR Head', $hr);
    $driver = orgpagePosition('Driver');
    orgpageHolder('100', $officer);
    orgpageHolder('101', $officer, active: false);

    $html = Livewire::test(PositionsPage::class)->html();

    expect($html)->toContain('Filter by Department')
        ->toContain('All departments')
        ->toContain('Add Position')
        ->not->toContain('Export CSV')
        ->not->toContain('Import CSV');
    foreach (['<th>ID</th>', '<th>Position</th>', '<th>Department</th>', '<th>Description</th>', 'Action</th>'] as $header) {
        expect($html)->toContain($header);
    }
    expect($html)->not->toContain('Held by</th>');
    // The department's own position, then its unit's, then Not placed.
    expect(orgpageAt($html, 'HR Head'))->toBeLessThan(orgpageAt($html, 'Administrative Officer'))
        ->and(orgpageAt($html, 'Administrative Officer'))->toBeLessThan(orgpageAt($html, '>Driver<'));

    expect(orgpageRow($html, 'position-'.$officer->id))->toContain('Administrative Officer HR Department People Operations')
        ->not->toContain('inactive')
        ->toContain('Delete · held by 2')
        ->toContain('Move Up')
        ->and(orgpageRow($html, 'position-'.$head->id))->toContain('HR Head HR Department')
        ->not->toContain('Delete · held by')
        ->and(orgpageRow($html, 'position-'.$driver->id))->toContain('Not placed')
        ->not->toContain('Move Up')
        ->not->toContain('Move Down');
});

it('filters by department, its units included, by one unit, or by Not placed', function () {
    orgpageActor();
    $hr = orgpageNode('HR Department');
    $people = orgpageNode('People Operations', $hr);
    $finance = orgpageNode('Finance', order: 1);
    $inHr = orgpagePosition('HR Head', $hr);
    $inUnit = orgpagePosition('Officer', $people);
    $inFinance = orgpagePosition('Controller', $finance);
    $loose = orgpagePosition('Driver');

    $ids = static fn ($page): array => array_map(static fn ($row): string => $row->id, $page->viewData('positions')->items());

    expect($ids(Livewire::test(PositionsPage::class)->set('department', $hr->id)))->toBe([$inHr->id, $inUnit->id])
        ->and($ids(Livewire::test(PositionsPage::class)->set('department', $people->id)))->toBe([$inUnit->id])
        ->and($ids(Livewire::test(PositionsPage::class)->set('department', 'NONE')))->toBe([$loose->id])
        ->and($ids(Livewire::test(PositionsPage::class)->set('department', 'no-such-id')))->toBe([])
        ->and($ids(Livewire::test(PositionsPage::class)))->toBe([$inHr->id, $inUnit->id, $inFinance->id, $loose->id]);
});

it('edits a position with the holder sentence, places it in a unit, and drops a unit from another department', function () {
    orgpageActor();
    $hr = orgpageNode('HR Department');
    $people = orgpageNode('People Operations', $hr);
    $finance = orgpageNode('Finance');
    $billing = orgpageNode('Billing', $finance);
    $officer = orgpagePosition('Administrative Officer', $hr);
    $holder = orgpageHolder('100', $officer);

    $page = Livewire::test(PositionsPage::class)
        ->call('startEdit', $officer->id)
        ->assertSet('formDepartmentId', $hr->id)
        ->assertSee('Edit Position')
        ->assertSee('1 employee holds this position. A new name shows on their record at once; Employment History and posted payroll keep the old name. Moving it changes the department and unit its holder shows.')
        ->assertSee('No unit — in the department itself')
        ->assertSee('Only the units of the chosen department are listed.')
        ->set('formUnitId', $people->id)
        ->set('formName', 'Admin Officer')
        ->call('save')
        ->assertSet('dialogOpen', false);

    expect($officer->fresh()?->org_node_id)->toBe($people->id)
        ->and($holder->fresh()?->position)->toBe('Admin Officer');

    // A unit of another department, sent by hand, is not kept.
    $page->call('startEdit', $officer->id)
        ->set('formDepartmentId', $hr->id)
        ->set('formUnitId', $billing->id)
        ->call('save');
    expect($officer->fresh()?->org_node_id)->toBe($hr->id);

    // Choosing another department drops the chosen unit; Not placed hides the Unit select.
    $page->call('startEdit', $officer->id)
        ->set('formUnitId', $people->id)
        ->set('formDepartmentId', $finance->id)
        ->assertSet('formUnitId', '')
        ->set('formDepartmentId', '')
        ->assertDontSee('Only the units of the chosen department are listed.');
});

it('omits the holder sentence when nobody holds the position and adds one through the page', function () {
    orgpageActor();
    $free = orgpagePosition('Messenger');

    Livewire::test(PositionsPage::class)
        ->call('startEdit', $free->id)
        ->assertDontSee('holds this position')
        ->assertDontSee('hold this position')
        ->call('openAdd')
        ->assertSee('Add Position')
        ->set('formName', 'Courier')
        ->call('save')
        ->assertSet('dialogOpen', false);

    expect(Position::query()->where('name', 'Courier')->whereNull('org_node_id')->exists())->toBeTrue();
});

it('lets a RECORDS reader without all_data change no position', function () {
    $hr = orgpageNode('HR');
    $a = orgpagePosition('Alpha', $hr, 0);
    $b = orgpagePosition('Bravo', $hr, 1);
    orgpageActor([ModuleCode::RECORDS], false);

    Livewire::test(PositionsPage::class)
        ->call('openAdd')->set('formName', 'Courier')->call('save')
        ->call('startEdit', $a->id)->set('formName', 'Renamed')->call('save')
        ->call('move', $b->id, 'up')
        ->call('confirmDelete', $b->id)->call('deleteConfirmed');

    expect(Position::query()->pluck('name')->sort()->values()->all())->toBe(['Alpha', 'Bravo'])
        ->and([$a->fresh()?->sort_order, $b->fresh()?->sort_order])->toBe([0, 1])
        ->and(AuditLog::query()->count())->toBe(0);
});

it('refuses through the page to delete a held position and deletes a free one', function () {
    orgpageActor();
    $held = orgpagePosition('Clerk');
    orgpageHolder('100', $held, active: false);
    $free = orgpagePosition('Messenger');

    Livewire::test(PositionsPage::class)->call('confirmDelete', $held->id)->call('deleteConfirmed');
    Livewire::test(PositionsPage::class)->call('confirmDelete', $free->id)->call('deleteConfirmed');

    expect(Position::query()->whereKey($held->id)->exists())->toBeTrue()
        ->and(Position::query()->whereKey($free->id)->exists())->toBeFalse();
});

it('renders both pages with every position Not placed before the structure migrations have run', function () {
    orgpageActor();
    orgpagePosition('Driver');
    $files = [
        '2026_10_07_0011_create_org_nodes_table',
        '2026_10_07_0012_add_org_node_to_positions_table',
        '2026_10_07_0013_add_position_id_to_employee_profiles_table',
        '2026_10_07_0014_link_employee_profiles_to_positions_table',
        '2026_10_07_0015_drop_global_name_unique_from_positions_table',
    ];
    $engine = in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true);
    foreach (array_reverse($files) as $file) {
        (require base_path("database/migrations/{$file}.php"))->down();
    }
    Once::flush();
    // On MySQL/MariaDB the DDL commits the open transaction: this test's
    // reads get one of their own, rolled back before the schema comes back.
    if ($engine) {
        DB::connection()->getPdo()->beginTransaction();
    }

    try {
        $this->get('/records/positions')->assertOk()->assertSee('Driver')->assertSee('Not placed');
        $this->get('/records/departments')->assertOk()->assertSee('Departments &amp; Units', false);
        expect(Livewire::test(PositionsPage::class)->set('department', 'NONE')->html())->toContain('Driver');
    } finally {
        if ($engine) {
            DB::connection()->getPdo()->rollBack();
        }
        foreach ($files as $file) {
            (require base_path("database/migrations/{$file}.php"))->up();
        }
        Once::flush();
        if ($engine) {
            DB::connection()->getPdo()->beginTransaction();
        }
    }
});
