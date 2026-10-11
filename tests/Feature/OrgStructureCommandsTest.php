<?php

declare(strict_types=1);

use App\Domain\Enums\ModuleCode;
use App\Models\AuditLog;
use App\Models\EmployeeEmploymentHistory;
use App\Models\EmployeeProfile;
use App\Models\OrgNode;
use App\Models\Position;
use App\Models\Role;
use App\Models\RoleModuleGrant;
use App\Models\User;
use App\Modules\Organization\Application\Commands\DeleteOrgNode;
use App\Modules\Organization\Application\Commands\DeletePosition;
use App\Modules\Organization\Application\Commands\MoveOrgNode;
use App\Modules\Organization\Application\Commands\MovePosition;
use App\Modules\Organization\Application\Commands\SaveDepartment;
use App\Modules\Organization\Application\Commands\SavePosition;
use App\Modules\Organization\Application\Commands\SaveUnit;
use App\Modules\Organization\Application\PositionRenames;
use App\Modules\Organization\Contracts\OrganizationDirectory;
use App\Modules\Organization\Contracts\OrganizationReferences;
use App\Modules\Organization\Contracts\Views\PositionPlacementView;
use App\Modules\Organization\Contracts\Views\StructureOptionView;
use App\Modules\Workforce\Application\Queries\PositionHolders;
use App\Platform\Records\PositionNameCopy;
use App\Support\Access;
use Database\Seeders\ReferenceListSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * The Organization structure commands, called directly with no page: every
 * write needs all_data + RECORDS and a refused write changes nothing and
 * audits nothing; department names are unique among departments, unit names
 * inside their department, position titles inside one department or unit
 * and among the Not placed — the same title is allowed in different places;
 * a unit's parent must be a department; a department with units, or any row
 * with positions, cannot be deleted, nor can a position anyone holds
 * (inactive included); Move up / Move down swap with the neighbour in the
 * row's own list; a rename rewrites every holder's position text in the
 * same transaction and writes no Employment History row.
 */

/** @param array<int, string> $modules */
function orgcmdActor(array $modules = [ModuleCode::RECORDS], bool $allData = true): Access
{
    $role = new Role;
    $role->forceFill(['name' => 'Org '.Str::random(6), 'all_data' => $allData, 'is_system' => false])->save();
    foreach ($modules as $module) {
        $grant = new RoleModuleGrant;
        $grant->forceFill(['role_id' => $role->getKey(), 'module' => $module])->save();
    }
    $user = User::factory()->create(['role_id' => $role->getKey()]);
    test()->actingAs($user);
    app()->forgetInstance(Access::class);

    return Access::forActor($user);
}

function orgcmdDepartment(string $name, int $order = 0): OrgNode
{
    $node = new OrgNode;
    $node->forceFill(['code' => (string) (OrgNode::query()->count() + 1), 'kind' => OrgNode::KIND_DEPARTMENT, 'name' => $name, 'parent_id' => null, 'sort_order' => $order])->save();

    return $node;
}

function orgcmdUnit(OrgNode $department, string $name, int $order = 0): OrgNode
{
    $node = new OrgNode;
    $node->forceFill(['code' => (string) (OrgNode::query()->count() + 1), 'kind' => OrgNode::KIND_UNIT, 'name' => $name, 'parent_id' => $department->id, 'sort_order' => $order])->save();

    return $node;
}

function orgcmdPosition(string $name, ?OrgNode $node = null, int $order = 0): Position
{
    $position = new Position;
    $position->forceFill(['code' => (string) (Position::query()->count() + 1), 'name' => $name, 'org_node_id' => $node?->id, 'sort_order' => $order])->save();

    return $position;
}

function orgcmdEmployee(string $code, ?Position $position, bool $active = true, ?string $text = null): EmployeeProfile
{
    $employee = new EmployeeProfile;
    $employee->forceFill([
        'employee_code' => $code,
        'first_name' => 'Emp',
        'last_name' => $code,
        'position' => $text ?? $position?->name,
        'position_id' => $position?->id,
        'employment_status' => 'Regular',
        'date_hired' => '2024-01-01',
        'is_active' => $active,
    ])->save();

    return $employee;
}

/** Run a write expected to be refused; return its message. */
function orgcmdRefused(Closure $write): string
{
    try {
        $write();
    } catch (RuntimeException $e) {
        return $e->getMessage();
    }
    throw new LogicException('The write was not refused.');
}

/* |---| Who may write |---| */

it('refuses every write to an actor without all_data + RECORDS, with no effect and no audit', function (array $modules, bool $allData) {
    $department = orgcmdDepartment('Finance');
    $unit = orgcmdUnit($department, 'Payroll');
    $position = orgcmdPosition('Clerk', $unit);
    $access = orgcmdActor($modules, $allData);
    $before = [OrgNode::query()->count(), Position::query()->count(), AuditLog::query()->count()];

    $writes = [
        fn () => app(SaveDepartment::class)->handle($access, null, 'Sales', ''),
        fn () => app(SaveDepartment::class)->handle($access, $department->id, 'Renamed', ''),
        fn () => app(SaveUnit::class)->handle($access, null, 'Billing', $department->id, ''),
        fn () => app(DeleteOrgNode::class)->handle($access, $unit->id),
        fn () => app(MoveOrgNode::class)->handle($access, $department->id, 'down'),
        fn () => app(SavePosition::class)->handle($access, null, 'Driver', '', null),
        fn () => app(SavePosition::class)->handle($access, $position->id, 'Chief Clerk', '', null),
        fn () => app(DeletePosition::class)->handle($access, $position->id),
        fn () => app(MovePosition::class)->handle($access, $position->id, 'up'),
    ];
    foreach ($writes as $write) {
        expect(orgcmdRefused($write))->toBe('Your role cannot manage organization-wide data.');
    }

    expect([OrgNode::query()->count(), Position::query()->count(), AuditLog::query()->count()])->toBe($before)
        ->and($department->fresh()?->name)->toBe('Finance')
        ->and($position->fresh()?->name)->toBe('Clerk');
})->with([
    'RECORDS without all_data' => [[ModuleCode::RECORDS], false],
    'all_data without RECORDS' => [[ModuleCode::EMPLOYEES], true],
    'no module at all' => [[], true],
]);

/* |---| Departments and units |---| */

it('adds and edits departments with bare minted ids, last in order, and audits each', function () {
    $access = orgcmdActor();
    orgcmdDepartment('Existing', 4);

    expect(app(SaveDepartment::class)->handle($access, null, '  HR Department ', ' People and payroll '))->toBe('HR Department added.');
    $hr = OrgNode::query()->where('name', 'HR Department')->firstOrFail();

    expect($hr->code)->toBe('2')
        ->and($hr->kind)->toBe(OrgNode::KIND_DEPARTMENT)
        ->and($hr->parent_id)->toBeNull()
        ->and($hr->sort_order)->toBe(5)
        ->and($hr->description)->toBe('People and payroll');
    expect(AuditLog::query()->where('action', 'STRUCTURE_CREATED')->value('details'))->toBe('Department 2 "HR Department" added');

    app(SaveDepartment::class)->handle($access, $hr->id, 'Human Resources', '');
    expect($hr->fresh()?->name)->toBe('Human Resources')
        ->and($hr->fresh()?->description)->toBeNull()
        ->and(AuditLog::query()->where('action', 'STRUCTURE_UPDATED')->value('details'))->toBe('Department 2 updated to "Human Resources"');
});

it('refuses a department name another department has, but allows a unit of that name', function () {
    $access = orgcmdActor();
    $finance = orgcmdDepartment('Finance');
    $other = orgcmdDepartment('Operations');
    $audits = AuditLog::query()->count();

    expect(orgcmdRefused(fn () => app(SaveDepartment::class)->handle($access, null, 'Finance', '')))->toBe('"Finance" is already a department.')
        ->and(orgcmdRefused(fn () => app(SaveDepartment::class)->handle($access, $other->id, 'Finance', '')))->toBe('"Finance" is already a department.')
        ->and(OrgNode::query()->count())->toBe(2)
        ->and($other->fresh()?->name)->toBe('Operations')
        ->and(AuditLog::query()->count())->toBe($audits);

    // A department keeping its own name is not a duplicate of itself.
    app(SaveDepartment::class)->handle($access, $finance->id, 'Finance', 'Money');
    app(SaveUnit::class)->handle($access, null, 'Finance', $other->id, '');
    expect(OrgNode::query()->where('name', 'Finance')->count())->toBe(2);
});

it('compares names the way the column\'s collation does: case- and accent-blind on MariaDB', function () {
    $access = orgcmdActor();
    orgcmdDepartment('Finance');
    orgcmdPosition('Résumé Reviewer');
    // What the database itself calls equal: MariaDB's utf8mb4_unicode_ci folds
    // case and accents; sqlite compares bytes.
    $folds = OrgNode::query()->where('name', 'fínance')->exists();

    $department = fn () => app(SaveDepartment::class)->handle($access, null, 'fínance', '');
    $position = fn () => app(SavePosition::class)->handle($access, null, 'resume reviewer', '', null);
    if ($folds) {
        expect(orgcmdRefused($department))->toBe('"fínance" is already a department.')
            ->and(orgcmdRefused($position))->toBe('"resume reviewer" is already on the list.')
            ->and([OrgNode::query()->count(), Position::query()->count()])->toBe([1, 1]);
    } else {
        $department();
        $position();
        expect([OrgNode::query()->count(), Position::query()->count()])->toBe([2, 2]);
    }
});

it('keeps a unit name once inside its department and allows it in another', function () {
    $access = orgcmdActor();
    $finance = orgcmdDepartment('Finance');
    $hr = orgcmdDepartment('HR');

    expect(app(SaveUnit::class)->handle($access, null, 'Payroll', $finance->id, ''))->toBe('Payroll added.');
    expect(orgcmdRefused(fn () => app(SaveUnit::class)->handle($access, null, 'Payroll', $finance->id, '')))->toBe('"Payroll" is already a unit of Finance.');
    app(SaveUnit::class)->handle($access, null, 'Payroll', $hr->id, '');

    expect(OrgNode::query()->where('name', 'Payroll')->pluck('parent_id')->sort()->values()->all())->toBe(collect([$finance->id, $hr->id])->sort()->values()->all())
        ->and(AuditLog::query()->where('action', 'STRUCTURE_CREATED')->pluck('details')->all())->toContain('Unit 3 "Payroll" added to Finance');
});

it('refuses a unit whose department is a unit, unknown or blank', function () {
    $access = orgcmdActor();
    $finance = orgcmdDepartment('Finance');
    $unit = orgcmdUnit($finance, 'Payroll');

    foreach ([$unit->id, 'no-such-id', ''] as $parent) {
        expect(orgcmdRefused(fn () => app(SaveUnit::class)->handle($access, null, 'Billing', $parent, '')))->toBe('Choose the department this unit belongs to.');
    }
    expect(OrgNode::query()->count())->toBe(2);
});

it('moves a unit to another department, last there, and its positions and holders go with it', function () {
    $access = orgcmdActor();
    $finance = orgcmdDepartment('Finance');
    $hr = orgcmdDepartment('HR');
    orgcmdUnit($hr, 'Recruitment', 3);
    $payroll = orgcmdUnit($finance, 'Payroll');
    $clerk = orgcmdPosition('Clerk', $payroll);
    $holder = orgcmdEmployee('100', $clerk);

    app(SaveUnit::class)->handle($access, $payroll->id, 'Payroll', $hr->id, '');

    expect($payroll->fresh()?->parent_id)->toBe($hr->id)
        ->and($payroll->fresh()?->sort_order)->toBe(4)
        ->and($clerk->fresh()?->org_node_id)->toBe($payroll->id)
        ->and(app(OrganizationReferences::class)->positionForAssignment($clerk->id)?->department_name)->toBe('HR')
        ->and($holder->fresh()?->position)->toBe('Clerk')
        ->and(AuditLog::query()->where('action', 'STRUCTURE_UPDATED')->value('details'))->toBe('Unit 4 "Payroll" moved to HR');
});

it('refuses to delete a department with units or positions, or a unit with positions, and deletes an empty one', function () {
    $access = orgcmdActor();
    $finance = orgcmdDepartment('Finance');
    $payroll = orgcmdUnit($finance, 'Payroll');
    $billing = orgcmdUnit($finance, 'Billing');
    orgcmdPosition('Controller', $finance);
    orgcmdPosition('Analyst', $finance);
    orgcmdPosition('Clerk', $payroll);
    $audits = AuditLog::query()->count();

    expect(orgcmdRefused(fn () => app(DeleteOrgNode::class)->handle($access, $finance->id)))->toBe('Move or delete its 2 units and 2 positions before deleting Finance.')
        ->and(orgcmdRefused(fn () => app(DeleteOrgNode::class)->handle($access, $payroll->id)))->toBe('Move or delete its 1 position before deleting Payroll.')
        ->and(OrgNode::query()->count())->toBe(3)
        ->and(AuditLog::query()->count())->toBe($audits);

    expect(app(DeleteOrgNode::class)->handle($access, $billing->id))->toBe('Billing deleted.')
        ->and(OrgNode::query()->whereKey($billing->id)->exists())->toBeFalse()
        ->and(AuditLog::query()->where('action', 'STRUCTURE_DELETED')->value('details'))->toBe('Unit 3 "Billing" deleted')
        ->and(orgcmdRefused(fn () => app(DeleteOrgNode::class)->handle($access, $billing->id)))->toBe('That department or unit no longer exists.');
});

it('moves departments among departments and units among their own department\'s units, refusing at the ends', function () {
    $access = orgcmdActor();
    $a = orgcmdDepartment('Alpha', 5);
    $b = orgcmdDepartment('Bravo', 5);
    $c = orgcmdDepartment('Charlie', 9);
    $u1 = orgcmdUnit($a, 'One', 0);
    $u2 = orgcmdUnit($a, 'Two', 0);
    $other = orgcmdUnit($b, 'Elsewhere', 0);

    expect(app(MoveOrgNode::class)->handle($access, $c->id, 'up'))->toBe('Charlie moved up.');
    expect([$a->fresh()?->sort_order, $c->fresh()?->sort_order, $b->fresh()?->sort_order])->toBe([0, 1, 2]);

    app(MoveOrgNode::class)->handle($access, $u2->id, 'up');
    expect([$u2->fresh()?->sort_order, $u1->fresh()?->sort_order])->toBe([0, 1])
        ->and($other->fresh()?->sort_order)->toBe(0)
        ->and(orgcmdRefused(fn () => app(MoveOrgNode::class)->handle($access, $u2->id, 'up')))->toBe('Two is already first.')
        ->and(orgcmdRefused(fn () => app(MoveOrgNode::class)->handle($access, $other->id, 'down')))->toBe('Elsewhere is already last.')
        ->and(orgcmdRefused(fn () => app(MoveOrgNode::class)->handle($access, $a->id, 'sideways')))->toBe('Choose Move up or Move down.')
        ->and(AuditLog::query()->where('action', 'STRUCTURE_UPDATED')->pluck('details')->all())->toBe(['Department 3 "Charlie" moved up', 'Unit 5 "Two" moved up']);
});

it('refuses a structure write readably while the database lock is missing', function () {
    $access = orgcmdActor();
    DB::table('locks')->where('name', OrganizationReferences::STRUCTURE_LOCK)->delete();

    expect(orgcmdRefused(fn () => app(SaveDepartment::class)->handle($access, null, 'Finance', '')))->toBe('The database lock is not ready. Ask an administrator to complete the database update.')
        ->and(OrgNode::query()->count())->toBe(0);
});

/* |---| Positions |---| */

it('allows the same title in different places and refuses it twice in one place or twice Not placed', function () {
    $access = orgcmdActor();
    $finance = orgcmdDepartment('Finance');
    $hr = orgcmdDepartment('HR');
    $people = orgcmdUnit($hr, 'People Operations');

    foreach ([$finance->id, $hr->id, $people->id, null] as $place) {
        app(SavePosition::class)->handle($access, null, 'Administrative Officer', '', $place);
    }
    expect(Position::query()->where('name', 'Administrative Officer')->count())->toBe(4);

    expect(orgcmdRefused(fn () => app(SavePosition::class)->handle($access, null, 'Administrative Officer', '', $hr->id)))->toBe('"Administrative Officer" is already a position in HR.')
        ->and(orgcmdRefused(fn () => app(SavePosition::class)->handle($access, null, 'Administrative Officer', '', $people->id)))->toBe('"Administrative Officer" is already a position in People Operations.')
        ->and(orgcmdRefused(fn () => app(SavePosition::class)->handle($access, null, 'Administrative Officer', '', null)))->toBe('"Administrative Officer" is already on the list.')
        ->and(orgcmdRefused(fn () => app(SavePosition::class)->handle($access, null, 'Administrative Officer', '', '')))->toBe('"Administrative Officer" is already on the list.')
        ->and(Position::query()->count())->toBe(4);
});

it('mints position ids, places new positions last, and refuses names over 120 characters or a missing place', function () {
    $access = orgcmdActor();
    $finance = orgcmdDepartment('Finance');
    orgcmdPosition('Existing', $finance, 7);

    expect(app(SavePosition::class)->handle($access, null, 'Controller', 'Signs off', $finance->id))->toBe('Controller added.');
    $controller = Position::query()->where('name', 'Controller')->firstOrFail();
    expect($controller->code)->toBe('2')
        ->and($controller->sort_order)->toBe(8)
        ->and(AuditLog::query()->where('action', 'REFERENCE_CREATED')->value('details'))->toBe('"Controller" added as position 2 in Department "Finance"');

    expect(orgcmdRefused(fn () => app(SavePosition::class)->handle($access, null, str_repeat('x', 121), '', null)))->toBe('name: must be at most 120 characters.')
        ->and(orgcmdRefused(fn () => app(SavePosition::class)->handle($access, null, 'Driver', '', 'no-such-node')))->toBe('Choose a department or unit that still exists.')
        ->and(orgcmdRefused(fn () => app(SavePosition::class)->handle($access, null, '   ', '', null)))->toBe('Name is required.')
        ->and(Position::query()->count())->toBe(2);

    app(SavePosition::class)->handle($access, null, str_repeat('y', 120), '', null);
    expect(Position::query()->count())->toBe(3);
});

it('renames every holder\'s position text in the same transaction and writes no Employment History', function () {
    $access = orgcmdActor();
    $finance = orgcmdDepartment('Finance');
    $clerk = orgcmdPosition('Clerk', $finance);
    $active = orgcmdEmployee('100', $clerk);
    $inactive = orgcmdEmployee('101', $clerk, active: false);
    $unlinked = orgcmdEmployee('102', null, text: 'Clerk');
    $history = EmployeeEmploymentHistory::query()->count();

    expect(app(SavePosition::class)->handle($access, $clerk->id, 'Records Clerk', '', $finance->id))->toBe('Records Clerk updated.');

    expect($clerk->fresh()?->name)->toBe('Records Clerk')
        ->and($active->fresh()?->position)->toBe('Records Clerk')
        ->and($inactive->fresh()?->position)->toBe('Records Clerk')
        ->and($unlinked->fresh()?->position)->toBe('Clerk')
        ->and(EmployeeEmploymentHistory::query()->count())->toBe($history)
        ->and(AuditLog::query()->where('action', 'REFERENCE_UPDATED')->value('details'))->toBe('Position 1 updated to "Records Clerk"; 2 employee records follow');
});

it('rolls the rename back when a holder copy cannot follow', function () {
    $access = orgcmdActor();
    $clerk = orgcmdPosition('Clerk');
    $holder = orgcmdEmployee('100', $clerk);
    app()->bind(PositionRenames::class, fn () => new PositionRenames([new class implements PositionNameCopy
    {
        public function follow(string $positionId, string $name): int
        {
            throw new RuntimeException('The copy failed.');
        }
    }]));

    expect(orgcmdRefused(fn () => app(SavePosition::class)->handle($access, $clerk->id, 'Records Clerk', '', null)))->toBe('The copy failed.')
        ->and($clerk->fresh()?->name)->toBe('Clerk')
        ->and($holder->fresh()?->position)->toBe('Clerk')
        ->and(AuditLog::query()->count())->toBe(0);
});

it('moves a position to another place, last there, without touching any employee record', function () {
    $access = orgcmdActor();
    $finance = orgcmdDepartment('Finance');
    $hr = orgcmdDepartment('HR');
    orgcmdPosition('Recruiter', $hr, 2);
    $clerk = orgcmdPosition('Clerk', $finance);
    $holder = orgcmdEmployee('100', $clerk);
    $stamp = $holder->fresh()?->updated_at;

    app(SavePosition::class)->handle($access, $clerk->id, 'Clerk', '', $hr->id);

    expect($clerk->fresh()?->org_node_id)->toBe($hr->id)
        ->and($clerk->fresh()?->sort_order)->toBe(3)
        ->and($holder->fresh()?->position)->toBe('Clerk')
        ->and($holder->fresh()?->updated_at?->format('Y-m-d H:i:s.v'))->toBe($stamp?->format('Y-m-d H:i:s.v'))
        ->and(AuditLog::query()->where('action', 'REFERENCE_UPDATED')->value('details'))->toBe('Position 2 updated to "Clerk"; moved to Department "HR"');

    app(SavePosition::class)->handle($access, $clerk->id, 'Clerk', '', null);
    expect($clerk->fresh()?->org_node_id)->toBeNull()
        ->and($clerk->fresh()?->sort_order)->toBe(0);
});

it('refuses to delete a position anyone holds, inactive included, and deletes a free one', function () {
    $access = orgcmdActor();
    $clerk = orgcmdPosition('Clerk');
    $driver = orgcmdPosition('Driver');
    $free = orgcmdPosition('Messenger');
    orgcmdEmployee('100', $clerk, active: false);
    orgcmdEmployee('101', $driver);
    orgcmdEmployee('102', $driver, active: false);
    // Unlinked text naming the title does not hold the position.
    orgcmdEmployee('103', null, text: 'Messenger');

    expect(orgcmdRefused(fn () => app(DeletePosition::class)->handle($access, $clerk->id)))->toBe('Reassign 1 employee before deleting this position.')
        ->and(orgcmdRefused(fn () => app(DeletePosition::class)->handle($access, $driver->id)))->toBe('Reassign 2 employees before deleting this position.')
        ->and(Position::query()->count())->toBe(3)
        ->and(AuditLog::query()->count())->toBe(0);

    expect(app(DeletePosition::class)->handle($access, $free->id))->toBe('Messenger deleted.')
        ->and(Position::query()->whereKey($free->id)->exists())->toBeFalse()
        ->and(AuditLog::query()->where('action', 'REFERENCE_DELETED')->value('details'))->toBe('Position 3 "Messenger" deleted');
});

it('moves a position within its own place and refuses a Not placed one', function () {
    $access = orgcmdActor();
    $finance = orgcmdDepartment('Finance');
    $a = orgcmdPosition('Analyst', $finance, 4);
    $b = orgcmdPosition('Bookkeeper', $finance, 4);
    $elsewhere = orgcmdPosition('Elsewhere', orgcmdDepartment('HR'), 0);
    $loose = orgcmdPosition('Loose');

    expect(app(MovePosition::class)->handle($access, $b->id, 'up'))->toBe('Bookkeeper moved up.');
    expect([$b->fresh()?->sort_order, $a->fresh()?->sort_order, $elsewhere->fresh()?->sort_order])->toBe([0, 1, 0])
        ->and(orgcmdRefused(fn () => app(MovePosition::class)->handle($access, $b->id, 'up')))->toBe('Bookkeeper is already first.')
        ->and(orgcmdRefused(fn () => app(MovePosition::class)->handle($access, $loose->id, 'down')))->toBe('A position that is not placed has no order to change.')
        ->and(AuditLog::query()->where('action', 'REFERENCE_UPDATED')->pluck('details')->all())->toBe(['Position 2 "Bookkeeper" moved up']);
});

/* |---| What other modules read |---| */

it('tells other modules where positions sit, and lists structure and titles in HR order', function () {
    $access = orgcmdActor();
    $eng = orgcmdDepartment('Engineering', 1);
    $pres = orgcmdDepartment('Office of the President', 0);
    $dev = orgcmdUnit($eng, 'Software Development');
    $loose = orgcmdPosition('Driver');
    $inUnit = orgcmdPosition('Developer', $dev);
    $inDept = orgcmdPosition('Driver', $eng);
    $top = orgcmdPosition('President', $pres);

    $placements = app(OrganizationReferences::class)->placements([$inUnit->id, $loose->id, 'missing']);
    expect(array_keys($placements))->toEqualCanonicalizing([$inUnit->id, $loose->id])
        ->and($placements[$inUnit->id]->department_name)->toBe('Engineering')
        ->and($placements[$inUnit->id]->unit_name)->toBe('Software Development')
        ->and($placements[$loose->id]->department_id)->toBeNull()
        ->and(app(OrganizationReferences::class)->positionForAssignment('missing'))->toBeNull()
        ->and(app(OrganizationReferences::class)->positionForAssignment($inDept->id)?->unit_id)->toBeNull();

    $directory = app(OrganizationDirectory::class);
    expect(array_map(static fn (StructureOptionView $o): string => $o->name, $directory->structureOptions($access)))->toBe(['Office of the President', 'Engineering', 'Software Development'])
        ->and(array_map(static fn (PositionPlacementView $p): string => $p->id, $directory->positionChoices($access)))->toBe([$top->id, $inDept->id, $inUnit->id, $loose->id])
        // A title entered in two places is offered once as a title.
        ->and($directory->positions($access))->toBe(['Developer', 'Driver', 'President']);
});

it('counts every holder of a position for a RECORDS reader and refuses anyone else', function () {
    $clerk = orgcmdPosition('Clerk');
    orgcmdEmployee('100', $clerk);
    orgcmdEmployee('101', $clerk);
    orgcmdEmployee('102', $clerk, active: false);

    // RECORDS without all_data still sees every holder: the count decides a delete.
    $reader = orgcmdActor([ModuleCode::RECORDS], false);
    expect(app(PositionHolders::class)->counts($reader, [$clerk->id, 'missing']))->toBe([$clerk->id => ['active' => 2, 'inactive' => 1]]);

    $outsider = orgcmdActor([ModuleCode::EMPLOYEES], true);
    expect(fn () => app(PositionHolders::class)->counts($outsider, [$clerk->id]))->toThrow(HttpException::class);
});

/* |---| Seeding |---| */

it('re-seeds the position list without undoing a rename or a placement made on the page', function () {
    $this->seed(ReferenceListSeeder::class);
    $access = orgcmdActor();
    $finance = orgcmdDepartment('Finance');
    $first = Position::query()->where('code', '1')->firstOrFail();
    app(SavePosition::class)->handle($access, $first->id, 'People Manager', '', $finance->id);
    $count = Position::query()->count();

    $this->seed(ReferenceListSeeder::class);

    expect(Position::query()->count())->toBe($count)
        ->and($first->fresh()?->name)->toBe('People Manager')
        ->and($first->fresh()?->org_node_id)->toBe($finance->id)
        ->and(Position::query()->where('name', 'HR Manager')->exists())->toBeFalse();
});
