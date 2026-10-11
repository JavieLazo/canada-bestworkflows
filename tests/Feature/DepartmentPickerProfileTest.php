<?php

declare(strict_types=1);

use App\Domain\Enums\ModuleCode;
use App\Livewire\Edtr\EdtrPage;
use App\Livewire\Kit\EmployeePicker;
use App\Livewire\Profile\ProfilePage;
use App\Models\EmployeeProfile;
use App\Models\OrgNode;
use App\Models\Position;
use App\Models\Role;
use App\Models\RoleModuleGrant;
use App\Models\User;
use App\Modules\Workforce\Contracts\DirectoryEmployee;
use App\Modules\Workforce\Contracts\OperationalDirectory;
use App\Support\Access;
use Illuminate\Support\Str;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

/**
 * The department narrowing outside Employee Records. The eDTR Daily Time
 * Record's "Department or unit" select narrows the employee picker through
 * its #[Locked] `department` prop: a department lists its own people and its
 * units', a unit only its own, Not placed the people linked to a position
 * placed nowhere — always AFTER the directory's row scope, so it never shows
 * anyone the actor could not already pick, and a pick from outside it is
 * refused. My Profile's Employment section shows the actor's OWN Department
 * and Unit beside Position, and nobody else's.
 */
/** @param  list<string>  $modules  ModuleCode constants, one grant each */
function deptpActor(array $modules, bool $allData = true, ?EmployeeProfile $employee = null): User
{
    $role = Role::query()->create(['name' => 'Dept picker '.Str::random(6), 'all_data' => $allData, 'is_system' => false]);
    foreach ($modules as $module) {
        RoleModuleGrant::query()->create(['role_id' => $role->id, 'module' => $module]);
    }
    $user = User::factory()->create();
    $user->forceFill(['role_id' => $role->id, 'employee_id' => $employee?->id])->save();
    test()->actingAs($user->refresh());
    app()->forgetInstance(Access::class);

    return $user;
}

function deptpNode(string $code, string $kind, string $name, ?string $parent = null, int $order = 0): OrgNode
{
    $node = new OrgNode;
    $node->forceFill(['code' => $code, 'kind' => $kind, 'name' => $name, 'parent_id' => $parent, 'sort_order' => $order])->save();

    return $node;
}

function deptpPosition(string $code, string $name, ?string $node = null, int $order = 0): Position
{
    $position = new Position;
    $position->forceFill(['code' => $code, 'name' => $name, 'org_node_id' => $node, 'sort_order' => $order])->save();

    return $position;
}

function deptpEmployee(string $code, string $last, ?Position $position, ?string $title = null): EmployeeProfile
{
    $employee = EmployeeProfile::query()->create([
        'employee_code' => $code, 'first_name' => 'Test', 'last_name' => $last,
        'position' => $position === null ? $title : $position->name, 'employment_status' => 'Regular',
        'date_hired' => '2024-01-15', 'monthly_rate' => 20000,
    ]);

    if ($position !== null) {
        $employee->forceFill(['position_id' => $position->id])->save();
    }

    return $employee;
}

/** @return array<string, mixed> */
function deptpWorld(): array
{
    $engineering = deptpNode('1', OrgNode::KIND_DEPARTMENT, 'Engineering Department', null, 1);
    $software = deptpNode('2', OrgNode::KIND_UNIT, 'Software Development', $engineering->id, 1);
    $hr = deptpNode('3', OrgNode::KIND_DEPARTMENT, 'HR Department', null, 2);

    return [
        'engineering' => $engineering, 'software' => $software, 'hr' => $hr,
        'cto' => deptpEmployee('101', 'Castro', deptpPosition('10', 'Chief Technology Officer', $engineering->id, 1)),
        'dev' => deptpEmployee('102', 'Bautista', deptpPosition('11', 'Senior Full-Stack Developer', $software->id, 1)),
        'admin' => deptpEmployee('103', 'Evangelista', deptpPosition('12', 'Administrative Officer', $hr->id, 1)),
        'driver' => deptpEmployee('104', 'Flores', deptpPosition('13', 'Driver')),
        'messenger' => deptpEmployee('105', 'Aquino', null, 'Messenger'),
    ];
}

/**
 * @param  array<string, string>  $filters
 * @return list<string>
 */
function deptpDirectory(array $filters): array
{
    return array_values(app(OperationalDirectory::class)->list(app(Access::class), filters: $filters)
        ->map(static fn (DirectoryEmployee $e): string => (string) $e->id)->all());
}

/** @return list<string> the employee ids a picker's list offers, in order */
function deptpOffered(string $html): array
{
    preg_match_all('/role="option"[^>]*data-id="([0-9A-Z]{26})"/s', $html, $matches);

    return $matches[1];
}

/* |---| The directory's place filter |---| */

it('narrows the operational directory to a department with its units, a unit, or Not placed', function () {
    $w = deptpWorld();
    deptpActor([ModuleCode::EDTR]);

    expect(deptpDirectory([]))->toHaveCount(5)
        ->and(deptpDirectory(['place' => OperationalDirectory::PLACE_ALL]))->toHaveCount(5)
        ->and(deptpDirectory(['place' => $w['engineering']->id]))->toEqualCanonicalizing([$w['cto']->id, $w['dev']->id])
        ->and(deptpDirectory(['place' => $w['software']->id]))->toBe([$w['dev']->id])
        ->and(deptpDirectory(['place' => $w['hr']->id]))->toBe([$w['admin']->id])
        // A record with no linked position is in no place, Not placed included.
        ->and(deptpDirectory(['place' => OperationalDirectory::PLACE_NOT_PLACED]))->toBe([$w['driver']->id])
        ->and(deptpDirectory(['place' => '01ZZZZZZZZZZZZZZZZZZZZZZZZ']))->toBe([]);
});

it('applies the place after the row scope, so a scoped actor never sees a colleague', function () {
    $w = deptpWorld();
    $colleague = deptpEmployee('106', 'Garcia', Position::query()->where('code', '12')->firstOrFail());

    deptpActor([ModuleCode::EDTR], allData: false, employee: $w['admin']);
    expect(deptpDirectory(['place' => $w['hr']->id]))->toBe([$w['admin']->id])
        ->and(deptpDirectory(['place' => $w['hr']->id]))->not->toContain($colleague->id)
        ->and(deptpDirectory(['place' => $w['engineering']->id]))->toBe([]);

    deptpActor([ModuleCode::EDTR], allData: false);
    expect(deptpDirectory(['place' => $w['hr']->id]))->toBe([]);
});

/* |---| The picker's department prop |---| */

it('lists only the chosen department in the picker and refuses a pick from outside it', function () {
    $w = deptpWorld();
    deptpActor([ModuleCode::EDTR]);

    $all = Livewire::test(EmployeePicker::class, ['name' => 'dtr-employee']);
    expect(deptpOffered($all->html()))->toHaveCount(5);

    $picker = Livewire::test(EmployeePicker::class, ['name' => 'dtr-employee', 'department' => $w['engineering']->id]);
    expect(deptpOffered($picker->html()))->toEqualCanonicalizing([$w['cto']->id, $w['dev']->id]);

    $picker->call('choose', $w['admin']->id)->assertNotDispatched('kit-employee-picked');
    expect($picker->get('value'))->toBe('');

    $picker->call('choose', $w['dev']->id)->assertDispatched('kit-employee-picked', name: 'dtr-employee', id: $w['dev']->id);
    expect($picker->get('value'))->toBe($w['dev']->id);

    $unit = Livewire::test(EmployeePicker::class, ['department' => $w['software']->id]);
    expect(deptpOffered($unit->html()))->toBe([$w['dev']->id]);

    $none = Livewire::test(EmployeePicker::class, ['department' => OperationalDirectory::PLACE_NOT_PLACED]);
    expect(deptpOffered($none->html()))->toBe([$w['driver']->id]);
});

it('keeps the department prop locked against the client', function () {
    $w = deptpWorld();
    deptpActor([ModuleCode::EDTR]);

    $picker = Livewire::test(EmployeePicker::class, ['department' => $w['hr']->id]);

    expect(fn () => $picker->set('department', OperationalDirectory::PLACE_ALL))
        ->toThrow(CannotUpdateLockedPropertyException::class);
    expect(deptpOffered($picker->html()))->toBe([$w['admin']->id]);
});

it('keeps showing an already chosen employee outside the department on the trigger', function () {
    $w = deptpWorld();
    deptpActor([ModuleCode::EDTR]);

    $html = Livewire::test(EmployeePicker::class, ['value' => $w['admin']->id, 'department' => $w['engineering']->id])->html();

    expect($html)->toContain('Evangelista')
        ->and(deptpOffered($html))->not->toContain($w['admin']->id);
});

/* |---| The DTR tab |---| */

it('draws "Department or unit" beside the picker and narrows the picker with it', function () {
    $w = deptpWorld();
    deptpActor([ModuleCode::EDTR]);

    $page = Livewire::test(EdtrPage::class)->set('tab', 'dtr');
    $html = $page->html();

    expect($html)->toContain('id="dtr-department"')
        ->and($html)->toContain('aria-label="Department or unit"')
        ->and($html)->toContain('Narrows the employee list to one department and its units, or to one unit.')
        ->and($html)->toContain('sm:grid-cols-2 lg:grid-cols-4');

    preg_match('/aria-label="Department or unit".*?<\/select>/s', $html, $select);
    preg_match_all('/<option[^>]*>([^<]*)<\/option>/', $select[0] ?? '', $options);
    $nbsp = "\u{00A0}\u{00A0}\u{00A0}";
    expect(array_map(html_entity_decode(...), $options[1]))
        ->toBe(['All departments', 'Not placed', 'Engineering Department', $nbsp.'Software Development', 'HR Department']);

    // The department comes before the employee, as the mockup draws it.
    expect(strpos($html, 'id="dtr-department"'))->toBeLessThan((int) strpos($html, 'id="dtr-employee-label"'));

    $page->set('department', $w['software']->id);
    expect($page->get('department'))->toBe($w['software']->id)
        ->and($page->html())->toContain('wire:key="dtr-employee-picker--'.$w['software']->id.'"');

    // A value the select does not offer falls back to every department.
    $page->set('department', '01ZZZZZZZZZZZZZZZZZZZZZZZZ');
    expect($page->get('department'))->toBe('ALL');
});

it('shows no department select to a viewer who only sees their own record', function () {
    $w = deptpWorld();
    deptpActor([ModuleCode::EDTR], allData: false, employee: $w['admin']);

    $html = Livewire::test(EdtrPage::class)->set('tab', 'dtr')->html();

    expect($html)->not->toContain('id="dtr-department"')
        ->and($html)->not->toContain('Department or unit')
        ->and($html)->toContain('sm:grid-cols-3');
});

/* |---| My Profile |---| */

/** The Employment section of My Profile, cut from the page. */
function deptpEmployment(string $html): string
{
    $start = strpos($html, '<h2 class="text-base font-bold">Employee Details</h2>');
    expect($start)->not->toBeFalse();
    $end = strpos($html, '</dl>', (int) $start);

    $section = preg_replace('/<!--.*?-->/s', '', substr($html, (int) $start, $end === false ? null : $end - (int) $start));

    return (string) preg_replace('/\s+/', ' ', (string) $section);
}

it('shows the actor\'s own Department and Unit beside Position on My Profile', function () {
    $w = deptpWorld();
    deptpActor([ModuleCode::PROFILE], allData: false, employee: $w['dev']);

    $section = deptpEmployment(Livewire::test(ProfilePage::class)->html());

    expect($section)->toContain('Position</dt> <dd class="mt-1 break-words text-sm">Senior Full-Stack Developer</dd>')
        ->and($section)->toContain('Department</dt> <dd class="mt-1 break-words text-sm">Engineering Department</dd>')
        ->and($section)->toContain('Unit</dt> <dd class="mt-1 break-words text-sm">Software Development</dd>')
        ->and($section)->not->toContain('HR Department');
});

it('reads Unit "—" in the department itself, "Not placed", and "—" with no linked position', function () {
    $w = deptpWorld();

    deptpActor([ModuleCode::PROFILE], allData: false, employee: $w['cto']);
    $cto = deptpEmployment(Livewire::test(ProfilePage::class)->html());
    expect($cto)->toContain('Department</dt> <dd class="mt-1 break-words text-sm">Engineering Department</dd>')
        ->and($cto)->toContain('Unit</dt> <dd class="mt-1 break-words text-sm">—</dd>');

    deptpActor([ModuleCode::PROFILE], allData: false, employee: $w['driver']);
    $driver = deptpEmployment(Livewire::test(ProfilePage::class)->html());
    expect($driver)->toContain('Department</dt> <dd class="mt-1 break-words text-sm">Not placed</dd>')
        ->and($driver)->toContain('Unit</dt> <dd class="mt-1 break-words text-sm">—</dd>');

    deptpActor([ModuleCode::PROFILE], allData: false, employee: $w['messenger']);
    $messenger = deptpEmployment(Livewire::test(ProfilePage::class)->html());
    expect($messenger)->toContain('Position</dt> <dd class="mt-1 break-words text-sm">Messenger<span')
        ->and($messenger)->toContain('Messenger is not on the Position list.')
        ->and($messenger)->toContain('Department</dt> <dd class="mt-1 break-words text-sm">—</dd>')
        ->and($messenger)->toContain('Unit</dt> <dd class="mt-1 break-words text-sm">—</dd>');
});

it('pins My Profile to the actor\'s own placement even for an all-data holder', function () {
    $w = deptpWorld();
    deptpActor([ModuleCode::PROFILE, ModuleCode::EMPLOYEES], allData: true, employee: $w['admin']);

    $section = deptpEmployment(Livewire::test(ProfilePage::class)->html());

    expect($section)->toContain('Department</dt> <dd class="mt-1 break-words text-sm">HR Department</dd>')
        ->and($section)->not->toContain('Engineering Department')
        ->and($section)->not->toContain('Software Development');
});
