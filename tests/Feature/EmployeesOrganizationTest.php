<?php

declare(strict_types=1);

use App\Domain\Enums\ModuleCode;
use App\Livewire\Employees\Organization;
use App\Models\EmployeeProfile;
use App\Models\OrgNode;
use App\Models\Position;
use App\Models\Role;
use App\Models\RoleModuleGrant;
use App\Models\User;
use App\Modules\Workforce\Application\Queries\OrganizationChart;
use App\Modules\Workforce\Contracts\Views\OrgChartDepartmentView;
use App\Modules\Workforce\Contracts\Views\OrgChartPersonView;
use App\Support\Access;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Once;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * The Organization view of Employee Records: every holder of EMPLOYEES sees
 * every department, unit and placed ACTIVE employee (name, photo, position),
 * even a holder whose Employees list shows only their own record — and a
 * name links to the record only where that viewer may open it. Positions
 * nobody holds, unplaced, legacy-text, unlinked and inactive records never
 * appear; empty departments and units do, with their sentences. Departments
 * follow HR order, a department's own people come before its units, and
 * people follow their positions' HR order, then their names.
 */

/** @param list<string> $modules */
function orgtabActor(array $modules = [ModuleCode::EMPLOYEES], bool $allData = false, ?EmployeeProfile $employee = null): User
{
    $role = new Role;
    $role->forceFill(['name' => 'Org tab '.Str::random(6), 'all_data' => $allData, 'is_system' => false])->save();
    foreach ($modules as $module) {
        $grant = new RoleModuleGrant;
        $grant->forceFill(['role_id' => $role->getKey(), 'module' => $module])->save();
    }
    $user = User::factory()->create(['role_id' => $role->getKey(), 'employee_id' => $employee?->id]);
    test()->actingAs($user);
    app()->forgetInstance(Access::class);

    return $user;
}

function orgtabNode(string $name, ?OrgNode $department = null, int $order = 0): OrgNode
{
    $node = new OrgNode;
    $node->forceFill([
        'code' => (string) (OrgNode::query()->count() + 1),
        'kind' => $department === null ? OrgNode::KIND_DEPARTMENT : OrgNode::KIND_UNIT,
        'name' => $name,
        'parent_id' => $department?->id,
        'sort_order' => $order,
    ])->save();

    return $node;
}

function orgtabPosition(string $name, ?OrgNode $node = null, int $order = 0): Position
{
    $position = new Position;
    $position->forceFill(['code' => (string) (Position::query()->count() + 1), 'name' => $name, 'org_node_id' => $node?->id, 'sort_order' => $order])->save();

    return $position;
}

/** A record holding `$position` — or, with null, only the stored `$title` (unlinked). */
function orgtabPerson(string $first, string $last, ?Position $position, bool $active = true, ?string $title = null): EmployeeProfile
{
    $employee = new EmployeeProfile;
    $employee->forceFill([
        'employee_code' => 'ORG-'.Str::upper(Str::random(6)), 'first_name' => $first, 'last_name' => $last,
        'position' => $position === null ? $title : $position->name, 'position_id' => $position?->id,
        'employment_status' => 'Regular', 'date_hired' => '2024-01-01', 'is_active' => $active,
    ])->save();

    return $employee;
}

/**
 * The fixture every test reads:
 *  - Board (dept 0, no units): Chairman Ana Abad; Board Member held by Cruz then Bautista (name order puts Bautista first)
 *  - Bids and Awards (dept 1): nobody
 *  - HR and Administration (dept 2): Vice President Vera Valdez; units Human Resources (HR Officer Hana Reyes), MIS (empty)
 *  - Audit Committee (dept 3): no own people; unit Internal Audit (Internal Auditor Ivan Yap)
 *
 * @return array<string, EmployeeProfile>
 */
function orgtabStructure(): array
{
    $board = orgtabNode('Board of Directors', null, 0);
    orgtabNode('Bids and Awards Committee', null, 1);
    $hr = orgtabNode('Office for HR and Administration', null, 2);
    $audit = orgtabNode('Board Audit Committee', null, 3);
    // Created out of order, so only sort_order can put them right.
    $mis = orgtabNode('MIS', $hr, 1);
    $people = orgtabNode('Human Resources', $hr, 0);
    $internal = orgtabNode('Internal Audit Office', $audit, 0);

    $member = orgtabPosition('Board Member', $board, 1);
    $chair = orgtabPosition('Chairman', $board, 0);
    orgtabPosition('Corporate Treasurer', $board, 2); // nobody holds it
    $vp = orgtabPosition('Vice President', $hr, 0);
    $officer = orgtabPosition('HR Officer', $people, 0);
    orgtabPosition('MIS Supervisor', $mis, 0); // nobody holds it
    $auditor = orgtabPosition('Internal Auditor', $internal, 0);
    $loose = orgtabPosition('Messenger'); // Not placed

    return [
        'cruz' => orgtabPerson('Carlo', 'Cruz', $member),
        'bautista' => orgtabPerson('Bea', 'Bautista', $member),
        'abad' => orgtabPerson('Ana', 'Abad', $chair),
        'valdez' => orgtabPerson('Vera', 'Valdez', $vp),
        'reyes' => orgtabPerson('Hana', 'Reyes', $officer),
        'yap' => orgtabPerson('Ivan', 'Yap', $auditor),
        'inactive' => orgtabPerson('Ines', 'Inactive', $vp, false),
        'unplaced' => orgtabPerson('Nora', 'Notplaced', $loose),
        'legacy' => orgtabPerson('Lito', 'Legacy', null, true, 'Driver'),
        'unlinked' => orgtabPerson('Uma', 'Unlinked', null),
    ];
}

/**
 * @param  list<OrgChartPersonView>  $people
 * @return list<string>
 */
function orgtabNames(array $people): array
{
    return array_map(static fn (OrgChartPersonView $person): string => $person->name, $people);
}

/** @param non-empty-string $needle */
function orgtabAt(string $html, string $needle): int
{
    $at = strpos($html, $needle);
    if ($at === false) {
        throw new LogicException("\"{$needle}\" is not on the page.");
    }

    return $at;
}

/* |---| The owner query, called directly |---| */

it('refuses the query to an actor without EMPLOYEES, however wide their role', function () {
    orgtabStructure();
    $actor = Access::forActor(orgtabActor([ModuleCode::PAYROLL, ModuleCode::RECORDS], true));

    expect(fn () => app(OrganizationChart::class)->departments($actor))->toThrow(HttpException::class);
    expect(fn () => app(OrganizationChart::class)->departments(Access::forGuest()))->toThrow(HttpException::class);
});

it('lists every department in HR order with its own people before its units, in position then name order', function () {
    orgtabStructure();
    $actor = Access::forActor(orgtabActor());
    $chart = app(OrganizationChart::class)->departments($actor);

    expect($chart)->each->toBeInstanceOf(OrgChartDepartmentView::class)
        ->and(array_map(static fn (OrgChartDepartmentView $d): string => $d->name, $chart))
        ->toBe(['Board of Directors', 'Bids and Awards Committee', 'Office for HR and Administration', 'Board Audit Committee']);

    [$board, $bids, $hr, $audit] = $chart;
    // Chairman before Board Member (HR order); two Board Members by last name.
    expect(orgtabNames($board->people))->toBe(['Ana Abad', 'Bea Bautista', 'Carlo Cruz'])
        ->and(array_map(static fn (OrgChartPersonView $p): string => $p->position, $board->people))->toBe(['Chairman', 'Board Member', 'Board Member'])
        ->and($board->units)->toBe([])
        ->and($bids->people)->toBe([])
        ->and($bids->units)->toBe([])
        ->and(orgtabNames($hr->people))->toBe(['Vera Valdez'])
        ->and(array_map(static fn ($u): string => $u->name, $hr->units))->toBe(['Human Resources', 'MIS'])
        ->and(orgtabNames($hr->units[0]->people))->toBe(['Hana Reyes'])
        ->and($hr->units[1]->people)->toBe([])
        ->and($audit->people)->toBe([])
        ->and(orgtabNames($audit->units[0]->people))->toBe(['Ivan Yap']);
});

it('shows a self-only EMPLOYEES holder every placed active employee, and leaves out everyone else', function () {
    $people = orgtabStructure();
    $actor = Access::forActor(orgtabActor([ModuleCode::EMPLOYEES], false, $people['reyes']));
    $ids = [];
    foreach (app(OrganizationChart::class)->departments($actor) as $department) {
        foreach ($department->people as $person) {
            $ids[] = $person->id;
        }
        foreach ($department->units as $unit) {
            foreach ($unit->people as $person) {
                $ids[] = $person->id;
            }
        }
    }
    sort($ids);
    $placed = array_map(static fn (string $key): string => $people[$key]->id, ['cruz', 'bautista', 'abad', 'valdez', 'reyes', 'yap']);
    sort($placed);

    expect($ids)->toBe($placed);
});

it('lets a name open the record only where the viewer may open it', function () {
    $people = orgtabStructure();
    $flags = static function (Access $actor): array {
        $flags = [];
        foreach (app(OrganizationChart::class)->departments($actor) as $department) {
            foreach ([$department->people, ...array_map(static fn ($u): array => $u->people, $department->units)] as $group) {
                foreach ($group as $person) {
                    $flags[$person->name] = $person->canOpen;
                }
            }
        }

        return $flags;
    };

    $own = $flags(Access::forActor(orgtabActor([ModuleCode::EMPLOYEES], false, $people['reyes'])));
    expect($own['Hana Reyes'])->toBeTrue()
        ->and(array_keys(array_filter($own)))->toBe(['Hana Reyes']);

    expect(array_filter($flags(Access::forActor(orgtabActor([ModuleCode::EMPLOYEES], false)))))->toBe([]);
    expect(array_unique(array_values($flags(Access::forActor(orgtabActor([ModuleCode::EMPLOYEES], true))))))->toBe([true]);
    // PAYROLL all-data opens records too (EmployeeRecords::find's rule).
    expect(array_unique(array_values($flags(Access::forActor(orgtabActor([ModuleCode::EMPLOYEES, ModuleCode::PAYROLL], true))))))->toBe([true]);
});

it('carries only the card facts on each person, never pay or private profile fields', function () {
    $properties = array_map(static fn (ReflectionProperty $p): string => $p->getName(), (new ReflectionClass(OrgChartPersonView::class))->getProperties());

    expect($properties)->toBe(['id', 'name', 'initials', 'position', 'photoPath', 'canOpen']);
});

/* |---| The page |---| */

it('gates the page on EMPLOYEES', function () {
    $this->get(route('employees.organization'))->assertRedirect(route('login'));

    orgtabActor([ModuleCode::LEAVES], true);
    $this->get(route('employees.organization'))->assertRedirect();

    orgtabActor([ModuleCode::EMPLOYEES], false);
    $this->get(route('employees.organization'))->assertOk()->assertSee('Expand All')->assertDontSee('Click a department to see its people');
});

it('puts Organization third in the view switch and marks it current', function () {
    orgtabActor();
    $html = $this->get('/employees/organization')->assertOk()->content();
    $nav = substr($html, orgtabAt($html, '<nav aria-label="Employee Records views"'));
    $nav = substr($nav, 0, orgtabAt($nav, '</nav>'));

    expect(substr_count($nav, '<a'))->toBe(3)
        ->and(orgtabAt($nav, 'Employees'))->toBeLessThan(orgtabAt($nav, 'Employment History'))
        ->and(orgtabAt($nav, 'Employment History'))->toBeLessThan(orgtabAt($nav, 'Organization'))
        ->and(substr_count($nav, 'aria-current="page"'))->toBe(1)
        ->and($nav)->toMatch('#href="[^"]*/employees/organization"\s+aria-current="page"#');

    $list = $this->get('/employees')->content();
    expect($list)->toContain(route('employees.organization'));
});

it('draws the cards, previews and tables of the approved mockup', function () {
    $people = orgtabStructure();
    orgtabActor([ModuleCode::EMPLOYEES], false, $people['reyes']);
    $html = Livewire::test(Organization::class)->html();

    expect($html)->toContain('Expand All')
        ->and($html)->toContain('Collapse All')
        // Every department is on the page, in HR order.
        ->and(orgtabAt($html, 'Board of Directors'))->toBeLessThan(orgtabAt($html, 'Bids and Awards Committee'))
        ->and(orgtabAt($html, 'Bids and Awards Committee'))->toBeLessThan(orgtabAt($html, 'Office for HR and Administration'))
        ->and(orgtabAt($html, 'Office for HR and Administration'))->toBeLessThan(orgtabAt($html, 'Board Audit Committee'))
        // An empty department, an empty unit, a department with no own people.
        ->and(substr_count($html, 'No one in this department yet'))->toBe(2)
        ->and($html)->toContain('No one in this unit yet')
        ->and($html)->toContain('No one is placed in the department itself')
        ->and($html)->toContain('In the department itself')
        // The Audit Committee card names its units instead of a person.
        ->and($html)->toContain('Internal Audit Office')
        // Positions nobody holds and people who are not placed are absent.
        ->and($html)->not->toContain('Corporate Treasurer')
        ->and($html)->not->toContain('MIS Supervisor')
        ->and($html)->not->toContain('Messenger')
        ->and($html)->not->toContain('Nora Notplaced')
        ->and($html)->not->toContain('Lito Legacy')
        ->and($html)->not->toContain('Uma Unlinked')
        ->and($html)->not->toContain('Ines Inactive')
        // Every panel starts shut, linked to its card.
        ->and(substr_count($html, 'aria-expanded="false"'))->toBe(4)
        ->and(substr_count($html, 'role="region" aria-labelledby="org-panel-title-'))->toBe(4);

    // Inside HR: own people, then Human Resources, then MIS.
    $panel = substr($html, orgtabAt($html, 'aria-label="Close Office for HR and Administration"'));
    expect(orgtabAt($panel, 'In the department itself'))->toBeLessThan(orgtabAt($panel, 'Vera Valdez'))
        ->and(orgtabAt($panel, 'Vera Valdez'))->toBeLessThan(orgtabAt($panel, 'Human Resources'))
        ->and(orgtabAt($panel, 'Human Resources'))->toBeLessThan(orgtabAt($panel, 'Hana Reyes'))
        ->and(orgtabAt($panel, 'Hana Reyes'))->toBeLessThan(orgtabAt($panel, 'No one in this unit yet'));

    // The Board's table: Chairman, then the two Board Members by name.
    $board = substr($html, orgtabAt($html, 'aria-label="Close Board of Directors"'));
    expect(orgtabAt($board, 'Ana Abad'))->toBeLessThan(orgtabAt($board, 'Bea Bautista'))
        ->and(orgtabAt($board, 'Bea Bautista'))->toBeLessThan(orgtabAt($board, 'Carlo Cruz'));
});

it('links only the names whose record the viewer may open', function () {
    $people = orgtabStructure();
    orgtabActor([ModuleCode::EMPLOYEES], false, $people['reyes']);
    $html = Livewire::test(Organization::class)->html();

    expect($html)->toContain('href="'.route('employees.show', $people['reyes']->id).'"')
        ->and($html)->not->toContain(route('employees.show', $people['valdez']->id))
        ->and($html)->not->toContain(route('employees.show', $people['abad']->id));

    orgtabActor([ModuleCode::EMPLOYEES], true);
    $html = Livewire::test(Organization::class)->html();
    foreach (['cruz', 'bautista', 'abad', 'valdez', 'reyes', 'yap'] as $key) {
        expect($html)->toContain('href="'.route('employees.show', $people[$key]->id).'"');
    }
});

it('shows the empty state, and never fails, before the structure migrations have run', function () {
    // The DDL runs FIRST (testing guide): on MariaDB it commits, so a row
    // seeded before it would leak into every later test.
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
        orgtabActor();
        $this->get('/employees/organization')->assertOk()->assertSee('Expand All')->assertSee('No departments have been set up yet.');
        expect(app(OrganizationChart::class)->departments(app(Access::class)))->toBe([]);
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

it('draws the departments with nobody in them when only the employee link is missing', function () {
    // The structure exists but employee_profiles.position_id does not yet (a
    // cron killed between 0012 and 0013). The DDL runs FIRST (testing guide).
    $files = [
        '2026_10_07_0013_add_position_id_to_employee_profiles_table',
        '2026_10_07_0014_link_employee_profiles_to_positions_table',
    ];
    $engine = in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true);
    foreach (array_reverse($files) as $file) {
        (require base_path("database/migrations/{$file}.php"))->down();
    }
    Once::flush();
    if ($engine) {
        DB::connection()->getPdo()->beginTransaction();
    }

    try {
        $finance = orgtabNode('Office for Finance');
        orgtabPosition('Accountant', $finance);
        orgtabActor();
        $this->get('/employees/organization')->assertOk()
            ->assertSee('Office for Finance')
            ->assertSee('No one in this department yet');
        $departments = app(OrganizationChart::class)->departments(app(Access::class));
        expect($departments)->toHaveCount(1)
            ->and($departments[0]->name)->toBe('Office for Finance')
            ->and($departments[0]->people)->toBe([]);
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
