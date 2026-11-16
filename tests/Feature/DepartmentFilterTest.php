<?php

declare(strict_types=1);

use App\Domain\Enums\ModuleCode;
use App\Livewire\Employees\Index;
use App\Livewire\Kit\Support\DepartmentFilter;
use App\Models\EmployeeProfile;
use App\Models\OrgNode;
use App\Models\Position;
use App\Models\Role;
use App\Models\RoleModuleGrant;
use App\Models\User;
use App\Modules\Workforce\Application\EmployeeColumns;
use App\Modules\Workforce\Application\Queries\EmployeeRecords;
use App\Support\Access;
use App\Support\Csv;
use Illuminate\Support\Str;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

/**
 * Employee Records' "Filter by department" and Department column. A
 * department lists the people whose position sits in it OR in one of its
 * units; a unit lists only its own; Not placed lists people linked to a
 * position placed in no department; a record with no linked position is in
 * no department, so only All departments lists it. The filter runs on the
 * rows the actor may already see and never widens them, and the CSV follows
 * the filter while its columns stay exactly as they were.
 */
function deptfActor(bool $allData = true, ?EmployeeProfile $employee = null): User
{
    $role = Role::query()->create(['name' => 'Dept filter '.Str::random(6), 'all_data' => $allData, 'is_system' => false]);
    RoleModuleGrant::query()->create(['role_id' => $role->id, 'module' => ModuleCode::EMPLOYEES]);
    $user = User::factory()->create();
    $user->forceFill(['role_id' => $role->id, 'employee_id' => $employee?->id])->save();
    test()->actingAs($user->refresh());
    app()->forgetInstance(Access::class);

    return $user;
}

function deptfNode(string $code, string $kind, string $name, ?string $parent = null, int $order = 0): OrgNode
{
    $node = new OrgNode;
    $node->forceFill(['code' => $code, 'kind' => $kind, 'name' => $name, 'parent_id' => $parent, 'sort_order' => $order])->save();

    return $node;
}

function deptfPosition(string $code, string $name, ?string $node = null, int $order = 0): Position
{
    $position = new Position;
    $position->forceFill(['code' => $code, 'name' => $name, 'org_node_id' => $node, 'sort_order' => $order])->save();

    return $position;
}

function deptfEmployee(string $code, string $last, ?Position $position, ?string $title = null): EmployeeProfile
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

/** @return array<string, mixed> Engineering (with two units), HR, and one person in each place */
function deptfWorld(): array
{
    $engineering = deptfNode('1', OrgNode::KIND_DEPARTMENT, 'Engineering Department', null, 1);
    $software = deptfNode('2', OrgNode::KIND_UNIT, 'Software Development', $engineering->id, 1);
    $platform = deptfNode('3', OrgNode::KIND_UNIT, 'Platform and Infrastructure', $engineering->id, 2);
    $hr = deptfNode('4', OrgNode::KIND_DEPARTMENT, 'HR Department', null, 2);

    return [
        'engineering' => $engineering, 'software' => $software, 'platform' => $platform, 'hr' => $hr,
        // Engineering itself, each of its units, HR, Not placed, and a title off the list.
        'cto' => deptfEmployee('101', 'Castro', deptfPosition('10', 'Chief Technology Officer', $engineering->id, 1)),
        'dev' => deptfEmployee('102', 'Bautista', deptfPosition('11', 'Senior Full-Stack Developer', $software->id, 1)),
        'sre' => deptfEmployee('103', 'Dizon', deptfPosition('12', 'Site Reliability Engineer', $platform->id, 1)),
        'admin' => deptfEmployee('104', 'Evangelista', deptfPosition('13', 'Administrative Officer', $hr->id, 1)),
        'driver' => deptfEmployee('105', 'Flores', deptfPosition('14', 'Driver')),
        'messenger' => deptfEmployee('106', 'Aquino', null, 'Messenger'),
    ];
}

/**
 * The employee ids the list shows, in order.
 *
 * @param  Testable<Index>  $page
 * @return list<string>
 */
function deptfShown(Testable $page): array
{
    preg_match_all('/<tr\s+wire:key="([0-9A-Z]{26})"/', $page->html(), $matches);

    return $matches[1];
}

/** Markup with Livewire's block markers dropped and whitespace collapsed. */
function deptfText(string $html): string
{
    return (string) preg_replace('/\s+/', ' ', (string) preg_replace('/<!--\[if (BLOCK|ENDBLOCK)\]><!\[endif\]-->/', '', $html));
}

/** @return list<string> the export's lines, header first */
function deptfCsv(string $url): array
{
    $csv = test()->get($url)->assertOk()->streamedContent();

    return array_values(array_filter(explode("\r\n", substr($csv, strlen(Csv::BOM))), static fn (string $line): bool => $line !== ''));
}

it('lists a department with the people of its units, and a unit with its own only', function () {
    $w = deptfWorld();
    deptfActor();

    $page = Livewire::test(Index::class);
    expect(deptfShown($page))->toHaveCount(6);

    $page->set('department', $w['engineering']->id);
    expect(deptfShown($page))->toEqualCanonicalizing([$w['cto']->id, $w['dev']->id, $w['sre']->id]);

    $page->set('department', $w['software']->id);
    expect(deptfShown($page))->toBe([$w['dev']->id]);

    $page->set('department', $w['hr']->id);
    expect(deptfShown($page))->toBe([$w['admin']->id]);
});

it('lists only people linked to a position placed nowhere under Not placed', function () {
    $w = deptfWorld();
    deptfActor();

    $page = Livewire::test(Index::class)->set('department', DepartmentFilter::NOT_PLACED);

    // The Messenger record has no linked position: it sits in no
    // department, so only All departments lists it.
    expect(deptfShown($page))->toBe([$w['driver']->id]);
});

it('falls back to All departments for a value the select does not offer', function () {
    deptfWorld();
    deptfActor();

    $page = Livewire::test(Index::class)->set('department', '01ZZZZZZZZZZZZZZZZZZZZZZZZ');

    expect($page->get('department'))->toBe(DepartmentFilter::ALL)
        ->and(deptfShown($page))->toHaveCount(6);

    // The rule itself, in the owner query: an unknown id matches nobody.
    $page = app(EmployeeRecords::class)->page(app(Access::class), [
        'search' => '', 'status' => 'ALL', 'position' => 'ALL', 'department' => '01ZZZZZZZZZZZZZZZZZZZZZZZZ',
    ], 'employee', 'asc', 1, 20);
    expect($page->total())->toBe(0);
});

it('never shows a scoped actor a row outside their scope, whatever department is chosen', function () {
    $w = deptfWorld();
    $colleague = deptfEmployee('107', 'Garcia', Position::query()->where('code', '13')->firstOrFail());
    deptfActor(allData: false, employee: $w['admin']);

    $page = Livewire::test(Index::class);
    expect(deptfShown($page))->toBe([$w['admin']->id]);

    // HR holds the actor and a colleague; the colleague stays out of sight.
    $page->set('department', $w['hr']->id);
    expect(deptfShown($page))->toBe([$w['admin']->id])
        ->and($page->html())->not->toContain($colleague->id);

    $page->set('department', $w['engineering']->id);
    expect(deptfShown($page))->toBe([]);

    // An unlinked account still sees nobody.
    deptfActor(allData: false);
    expect(deptfShown(Livewire::test(Index::class)->set('department', $w['hr']->id)))->toBe([]);
});

it('draws the Department column and the filter in the mockup\'s wording and order', function () {
    $w = deptfWorld();
    deptfActor();

    $html = Livewire::test(Index::class)->html();

    expect($html)->toContain('aria-label="Filter by Department"')
        ->and($html)->toContain('x-on:click="$wire.sortBy(\'department\')"');

    preg_match('/aria-label="Filter by Department".*?<\/select>/s', $html, $select);
    preg_match_all('/<option[^>]*value="([^"]*)"[^>]*>([^<]*)<\/option>/', $select[0] ?? '', $options, PREG_SET_ORDER);
    $labels = array_map(static fn (array $o): string => html_entity_decode($o[2]), $options);
    $nbsp = "\u{00A0}\u{00A0}\u{00A0}";

    expect($labels)->toBe(['All departments', 'Not placed', 'Engineering Department', $nbsp.'Software Development', $nbsp.'Platform and Infrastructure', 'HR Department']);

    // Department name with the unit on a muted second line; "Not placed";
    // "—" for a record with no linked position.
    $row = static function (string $id) use ($html): string {
        preg_match('/<tr\s+wire:key="'.$id.'".*?<\/tr>/s', $html, $m);

        return $m[0] ?? '';
    };

    expect($row($w['dev']->id))->toContain('<span class="block">Engineering Department</span>')
        ->and($row($w['dev']->id))->toContain('<span class="block text-xs text-muted-foreground">Software Development</span>')
        ->and($row($w['cto']->id))->toContain('<span class="block">Engineering Department</span>')
        ->and($row($w['cto']->id))->not->toContain('text-xs text-muted-foreground">Software')
        ->and(deptfText($row($w['driver']->id)))->toContain('<td class="whitespace-nowrap align-middle"> Not placed </td>')
        ->and(deptfText($row($w['messenger']->id)))->toContain('<td class="whitespace-nowrap align-middle"> — </td>');
});

it('sorts by department name, the department\'s own people before its units\', no department last', function () {
    $w = deptfWorld();
    deptfActor();

    $page = Livewire::test(Index::class)->call('sortBy', 'department');

    expect($page->get('sortKey'))->toBe('department');

    $order = deptfShown($page);
    // Engineering (its own CTO, then Platform before Software by unit name),
    // HR, then the two with no department by name: Aquino, Flores.
    expect($order)->toBe([$w['cto']->id, $w['sre']->id, $w['dev']->id, $w['admin']->id, $w['messenger']->id, $w['driver']->id]);
});

it('narrows the CSV by the department while its header stays the 112 frozen columns', function () {
    $w = deptfWorld();
    deptfActor();

    $all = deptfCsv('/employees/export');
    $engineering = deptfCsv('/employees/export?department='.$w['engineering']->id);
    $unknown = deptfCsv('/employees/export?department=01ZZZZZZZZZZZZZZZZZZZZZZZZ');

    $header = str_getcsv($all[0], ',', '"', '');
    expect($header)->toBe(EmployeeColumns::row())
        ->and($header)->toHaveCount(112)
        ->and(md5(implode('|', $header)))->toBe('5e5582038827b55f12dae5fc134683a4')
        ->and($header)->not->toContain('Department')
        ->and($header)->not->toContain('Unit');

    expect($engineering[0])->toBe($all[0])
        ->and(count($all) - 1)->toBe(6)
        ->and(count($engineering) - 1)->toBe(3)
        // A department the page cannot offer exports what the page shows: everyone.
        ->and(count($unknown) - 1)->toBe(6);

    // The Export CSV link carries the chosen department.
    $html = Livewire::test(Index::class)->set('department', $w['software']->id)->html();
    expect($html)->toContain('department='.$w['software']->id);
});

it('exports only a scoped actor\'s own row whatever department the link names', function () {
    $w = deptfWorld();
    deptfActor(allData: false, employee: $w['admin']);

    expect(count(deptfCsv('/employees/export?department='.$w['hr']->id)) - 1)->toBe(1)
        ->and(count(deptfCsv('/employees/export?department='.$w['engineering']->id)) - 1)->toBe(0);
});
