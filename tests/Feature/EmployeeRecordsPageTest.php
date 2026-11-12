<?php

declare(strict_types=1);

use App\Domain\Enums\ModuleCode;
use App\Livewire\Employees\EmployeeFields;
use App\Livewire\Employees\Index;
use App\Livewire\Kit\Support\DepartmentFilter;
use App\Models\EmployeeProfile;
use App\Models\OrgNode;
use App\Models\Position;
use App\Models\Role;
use App\Models\RoleModuleGrant;
use App\Models\User;
use App\Modules\Workforce\Application\Queries\EmployeeRecords;
use App\Modules\Workforce\Contracts\Views\EmployeeProfileView;
use App\Support\Access;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Support\LegacyEmployeeRows;

/**
 * Employee Records pages in SQL and returns only the page's records — and
 * lists exactly what the PHP pipeline listed, in its order, for every
 * filter, search, sort and direction (Tests\Support\LegacyEmployeeRows is
 * the oracle). Ties fall to last name, first name and the key, the same
 * in both: the PHP pipeline kept its input order on a tie, so the oracle
 * is handed its input in that order (erpExpected). The scope still decides
 * who is in it: a filter only narrows.
 */
function erpActor(bool $allData = true, ?EmployeeProfile $employee = null, string $module = ModuleCode::EMPLOYEES): Access
{
    $role = new Role;
    $role->forceFill(['name' => 'Records page '.Str::random(6), 'all_data' => $allData, 'is_system' => false])->save();
    $grant = new RoleModuleGrant;
    $grant->forceFill(['role_id' => $role->getKey(), 'module' => $module])->save();
    $user = User::factory()->create(['role_id' => $role->getKey(), 'employee_id' => $employee?->getKey()]);
    test()->actingAs($user);
    app()->forgetInstance(Access::class);

    return Access::forActor($user);
}

function erpNode(string $code, string $kind, string $name, ?string $parent = null): OrgNode
{
    $node = new OrgNode;
    $node->forceFill(['code' => $code, 'kind' => $kind, 'name' => $name, 'parent_id' => $parent, 'sort_order' => (int) $code])->save();

    return $node;
}

function erpPosition(string $code, string $name, ?string $node): Position
{
    $position = new Position;
    $position->forceFill(['code' => $code, 'name' => $name, 'org_node_id' => $node, 'sort_order' => (int) $code])->save();

    return $position;
}

/** @param array<string, mixed> $attributes */
function erpEmployee(array $attributes, ?Position $position = null): EmployeeProfile
{
    $employee = new EmployeeProfile;
    $employee->forceFill($attributes + [
        'employment_status' => 'Regular', 'is_active' => true, 'date_hired' => '2024-01-15', 'monthly_rate' => 20000,
        'position' => $position?->name, 'position_id' => $position?->getKey(),
    ])->save();

    return $employee;
}

/** @return array<string, mixed> thirteen records chosen to hit every rule's edge, and the places they sit in */
function erpWorld(): array
{
    $engineering = erpNode('1', OrgNode::KIND_DEPARTMENT, 'Engineering');
    $software = erpNode('2', OrgNode::KIND_UNIT, 'Software', $engineering->getKey());
    $hr = erpNode('3', OrgNode::KIND_DEPARTMENT, 'HR');
    $cto = erpPosition('10', 'Chief Technology Officer', $engineering->getKey());
    $dev = erpPosition('11', 'Senior Developer', $software->getKey());
    $admin = erpPosition('12', 'Administrative Officer', $hr->getKey());
    $driver = erpPosition('13', 'Driver', null);

    return [
        'engineering' => $engineering->getKey(), 'software' => $software->getKey(), 'hr' => $hr->getKey(),
        'rows' => [
            // Bare numbers sort as numbers (2 before 10), a prefixed code after them.
            erpEmployee(['employee_code' => '2', 'first_name' => 'Juan', 'last_name' => 'Dela Cruz', 'suffix' => 'Jr.', 'employee_id_no' => '2024-118', 'email' => 'juan@example.ph'], $dev),
            erpEmployee(['employee_code' => '10', 'first_name' => 'Juan', 'last_name' => 'Dela Cruz', 'employment_status' => 'REGULAR'], $dev),
            erpEmployee(['employee_code' => '1', 'first_name' => 'Ana', 'last_name' => 'Garcia', 'employee_id_no' => 'A-1', 'employment_status' => 'Probationary', 'email' => 'ana@example.ph'], $cto),
            erpEmployee(['employee_code' => 'COP-00042', 'first_name' => ' Ben ', 'last_name' => 'reyes', 'employee_id_no' => 'a-2', 'employment_status' => 'JOB_ORDER', 'email' => 'BEN@example.ph'], $admin),
            erpEmployee(['employee_code' => '11', 'first_name' => 'Cara', 'last_name' => 'Reyes', 'employee_id_no' => '  ', 'employment_status' => 'Job Order', 'is_active' => false, 'email' => 'cara@example.ph'], $admin),
            erpEmployee(['employee_code' => '3', 'first_name' => 'Dan', 'last_name' => 'Santos', 'suffix' => 'III', 'employment_status' => 'Contractual'], $driver),
            erpEmployee(['employee_code' => '4', 'first_name' => 'Eve', 'last_name' => 'Aquino', 'position' => 'Messenger', 'is_active' => false, 'email' => 'eve@example.ph']),
            erpEmployee(['employee_code' => '5', 'first_name' => 'Fe', 'last_name' => 'Bautista', 'employee_id_no' => '2023-001', 'position' => 'driver', 'employment_status' => 'Casual', 'email' => 'fe@example.ph']),
            erpEmployee(['employee_code' => '12', 'first_name' => 'Gil', 'last_name' => 'Castro', 'email' => 'gil@example.ph'], $cto),
            erpEmployee(['employee_code' => '20', 'first_name' => 'Hana', 'last_name' => 'Dizon', 'employee_id_no' => 'Z-9', 'employment_status' => 'Probationary', 'is_active' => false], $dev),
            erpEmployee(['employee_code' => '6', 'first_name' => 'Ivy', 'last_name' => 'Evangelista', 'position' => '   ', 'email' => 'ivy@example.ph']),
            // A status that differs from another only in case keeps its own label.
            erpEmployee(['employee_code' => '8', 'first_name' => 'Kai', 'last_name' => 'Galang', 'position' => 'Clerk', 'employment_status' => 'CASUAL']),
            erpEmployee(['employee_code' => '7', 'first_name' => 'Jo', 'last_name' => 'Flores', 'suffix' => 'Sr.', 'employee_id_no' => '2024-119', 'email' => 'jo@example.ph'], $driver),
        ],
    ];
}

/**
 * The oracle's order for the same arguments, its input handed over in the
 * SQL tiebreak (last name, first name, key) so ties land the same way.
 *
 * @param  array{search: string, status: string, position: string, department: string}  $filters
 * @return list<string>
 */
function erpExpected(Access $access, array $filters, string $sort, string $dir): array
{
    $views = app(EmployeeRecords::class)->list($access)->sort(static fn (EmployeeProfileView $a, EmployeeProfileView $b): int => EmployeeFields::compareText($a->last_name, $b->last_name)
        ?: EmployeeFields::compareText($a->first_name, $b->first_name)
        ?: strcmp($a->id, $b->id))->values();

    return array_map(
        static fn (EmployeeProfileView $view): string => $view->id,
        LegacyEmployeeRows::arrange($views, $filters['search'], $filters['status'], $filters['position'], $filters['department'], $sort, $dir),
    );
}

/**
 * The ids of one page of up to 100 rows, ascending.
 *
 * @param  array{search: string, status: string, position: string, department: string}  $filters
 * @return list<string>
 */
function erpPageIds(Access $access, array $filters, string $sort = 'employee'): array
{
    return array_values(collect(app(EmployeeRecords::class)->page($access, $filters, $sort, 'asc', 1, 100)->items())->pluck('id')->all());
}

/** @return array{search: string, status: string, position: string, department: string} */
function erpFilters(string $search = '', string $status = 'ALL', string $position = 'ALL', string $department = DepartmentFilter::ALL): array
{
    return ['search' => $search, 'status' => $status, 'position' => $position, 'department' => $department];
}

/*
|--------------------------------------------------------------------------
| The SQL page lists what the PHP pipeline listed
|--------------------------------------------------------------------------
*/

it('lists exactly what the PHP pipeline listed for every filter, sort and direction', function () {
    $w = erpWorld();
    $access = erpActor();
    $records = app(EmployeeRecords::class);
    $cases = [
        'nothing' => erpFilters(),
        'inactive' => erpFilters(status: 'INACTIVE'),
        'a status key matching a legacy token and its spelling' => erpFilters(status: 'Job Order'),
        'a status key matching two spellings, active or not' => erpFilters(status: 'Regular'),
        'a status key over two cases with two labels' => erpFilters(status: 'Casual'),
        'a job title, exactly' => erpFilters(position: 'Driver'),
        'a title of spaces' => erpFilters(position: '   '),
        'a department with its unit' => erpFilters(department: $w['engineering']),
        'a unit' => erpFilters(department: $w['software']),
        'not placed' => erpFilters(department: DepartmentFilter::NOT_PLACED),
        'a phrase through the suffix' => erpFilters(search: 'dela cruz jr'),
        'a phrase from the name into the title' => erpFilters(search: 'cruz senior'),
        'a phrase from the suffix into the title' => erpFilters(search: 'jr. senior'),
        'a trimmed first name and an email, any case' => erpFilters(search: 'BEN'),
        'an Employee ID' => erpFilters(search: '2024'),
        'an id fragment, any case' => erpFilters(search: 'a-'),
        'everything at once' => erpFilters(search: 'juan', status: 'Regular', department: $w['engineering']),
    ];
    $checked = 0;

    foreach ($cases as $label => $filters) {
        foreach (['id', 'employee_id_no', 'employee', 'department', 'email', 'status'] as $sort) {
            foreach (['asc', 'desc'] as $dir) {
                $expected = erpExpected($access, $filters, $sort, $dir);
                $page = $records->page($access, $filters, $sort, $dir, 1, 100);
                $arranged = $records->arranged($access, $filters, $sort, $dir);

                expect(collect($page->items())->pluck('id')->all())->toBe($expected, "{$label} · {$sort} {$dir}")
                    ->and($page->total())->toBe(count($expected), "{$label} · total")
                    ->and($arranged->pluck('id')->all())->toBe($expected, "{$label} · CSV {$sort} {$dir}");
                $checked++;
            }
        }
    }

    expect($checked)->toBe(17 * 6 * 2);

    // Known answers, so the oracle cannot agree with the query by both being
    // empty: a phrase across the name's boundary finds the record without a
    // suffix only; a status key finds the token and the spelling; a title
    // matches its own case alone; and codes sort as numbers, a prefix last.
    $rows = $w['rows'];
    expect(erpPageIds($access, erpFilters(search: 'cruz senior')))->toBe([$rows[1]->id])
        ->and(erpPageIds($access, erpFilters(search: 'jr. senior')))->toBe([$rows[0]->id])
        ->and(erpPageIds($access, erpFilters(status: 'Job Order')))->toEqualCanonicalizing([$rows[3]->id, $rows[4]->id])
        ->and(erpPageIds($access, erpFilters(position: 'Driver')))->toEqualCanonicalizing([$rows[5]->id, $rows[12]->id])
        ->and(array_map(static fn (string $id): string => EmployeeProfile::query()->findOrFail($id)->employee_code, erpPageIds($access, erpFilters(), 'id')))
        ->toBe(['1', '2', '3', '4', '5', '6', '7', '8', '10', '11', '12', '20', 'COP-00042']);
});

/*
|--------------------------------------------------------------------------
| Paging in the database
|--------------------------------------------------------------------------
*/

it('reads only the page from the database and clamps an overflowing page to the last', function () {
    erpWorld();
    $access = erpActor();
    $records = app(EmployeeRecords::class);

    DB::enableQueryLog();
    $first = $records->page($access, erpFilters(), 'employee', 'asc', 1, 5);
    $sql = collect(DB::getQueryLog())->map(static fn (array $entry): string => mb_strtolower(str_replace('`', '"', (string) $entry['query'])));
    DB::disableQueryLog();

    $all = $records->arranged($access, erpFilters(), 'employee', 'asc')->pluck('id')->all();
    $last = $records->page($access, erpFilters(), 'employee', 'asc', 99, 5);

    expect($first->total())->toBe(13)
        ->and(collect($first->items())->pluck('id')->all())->toBe(array_slice($all, 0, 5))
        ->and($sql->contains(static fn (string $q): bool => str_contains($q, 'from "employee_profiles"') && str_contains($q, 'limit 5')))->toBeTrue()
        // The children are read for the page's records alone.
        ->and($sql->first(static fn (string $q): bool => str_contains($q, 'from "employee_children"')))->toContain('in (?, ?, ?, ?, ?)')
        ->and($last->currentPage())->toBe(3)
        ->and(collect($last->items())->pluck('id')->all())->toBe(array_slice($all, 10));
});

it('shows one page on the screen while counting every match', function () {
    erpWorld();
    erpActor();

    $page = Livewire::test(Index::class)->set('perPage', 20);
    expect($page->viewData('visibleCount'))->toBe(13)
        ->and($page->viewData('list')->count())->toBe(13);

    $page->set('search', 'reyes');
    expect($page->viewData('visibleCount'))->toBe(2);
});

/*
|--------------------------------------------------------------------------
| Scope, and the options held on records
|--------------------------------------------------------------------------
*/

it('keeps the record visibility rule, whatever the filters', function () {
    $w = erpWorld();
    $own = $w['rows'][3];
    $records = app(EmployeeRecords::class);

    $self = erpActor(allData: false, employee: $own);
    expect(collect($records->page($self, erpFilters(), 'employee', 'asc', 1, 20)->items())->pluck('id')->all())->toBe([$own->id])
        ->and($records->page($self, erpFilters(department: $w['engineering']), 'employee', 'asc', 1, 20)->total())->toBe(0)
        ->and($records->arranged($self, erpFilters(search: 'juan'), 'employee', 'asc'))->toHaveCount(0)
        ->and($records->heldValues($self))->toBe(['positions' => ['Administrative Officer'], 'statuses' => ['JOB_ORDER']]);

    $unlinked = erpActor(allData: false);
    expect($records->page($unlinked, erpFilters(), 'employee', 'asc', 1, 20)->total())->toBe(0)
        ->and($records->heldValues($unlinked))->toBe(['positions' => [], 'statuses' => []]);

    // An all-data role without EMPLOYEES or PAYROLL reads no records either.
    expect($records->page(erpActor(module: ModuleCode::ROSTER), erpFilters(), 'employee', 'asc', 1, 20)->total())->toBe(0);
});

it('offers every title and status still held on a record, told apart exactly', function () {
    erpWorld();
    $held = app(EmployeeRecords::class)->heldValues(erpActor());
    sort($held['positions']);
    sort($held['statuses']);

    // "Driver" and "driver" are two titles, and a title of spaces is one; a
    // record with no title adds nothing.
    expect($held['positions'])->toBe(['   ', 'Administrative Officer', 'Chief Technology Officer', 'Clerk', 'Driver', 'Messenger', 'Senior Developer', 'driver'])
        ->and($held['statuses'])->toBe(['CASUAL', 'Casual', 'Contractual', 'JOB_ORDER', 'Job Order', 'Probationary', 'REGULAR', 'Regular']);
});
