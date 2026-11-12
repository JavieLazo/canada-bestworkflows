<?php

declare(strict_types=1);

use App\Domain\Enums\ModuleCode;
use App\Livewire\Employees\AddEmployeeDialog;
use App\Livewire\Employees\Show;
use App\Livewire\Employees\Support\PositionPicker;
use App\Models\AuditLog;
use App\Models\EmployeeProfile;
use App\Models\EmploymentStatusOption;
use App\Models\OrgNode;
use App\Models\Position;
use App\Models\Role;
use App\Models\RoleModuleGrant;
use App\Models\User;
use App\Models\WorkSite;
use App\Modules\Workforce\Application\EmployeePosition;
use App\Support\Access;
use Illuminate\Support\Str;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

/**
 * Employee Details on the record and in the hire wizard place a position in
 * three selects — Department, Unit, Position — and store the position alone.
 * Read: Department | Unit, then Position | Position Level; Unit "—" when the
 * position sits in the department itself; both "—" for a record with no
 * linked position, and a title off the Position list ("Messenger") reads
 * with "Messenger is not on the Position list.". Edit: Department lists
 * "Choose a department", "Not placed" and the departments; Unit lists the
 * chosen department's units after "No unit — in the department itself", or
 * "Choose a department first"; Position lists only the positions of the
 * chosen place, plus "Messenger (not on the Position list)" (KEEP) on a
 * legacy record, so HR can save the rest. Department and Unit narrow; the
 * server re-reads the placement from the position.
 */
function posrecActor(bool $allData = true, ?EmployeeProfile $employee = null): User
{
    $role = Role::query()->create(['name' => 'Pos rec '.Str::random(6), 'all_data' => $allData, 'is_system' => false]);
    RoleModuleGrant::query()->create(['role_id' => $role->id, 'module' => ModuleCode::EMPLOYEES]);
    $user = User::factory()->create();
    $user->forceFill(['role_id' => $role->id, 'employee_id' => $employee?->id])->save();
    test()->actingAs($user->refresh());
    app()->forgetInstance(Access::class);

    return $user;
}

function posrecNode(string $code, string $kind, string $name, ?string $parent = null, int $order = 0): OrgNode
{
    $node = new OrgNode;
    $node->forceFill(['code' => $code, 'kind' => $kind, 'name' => $name, 'parent_id' => $parent, 'sort_order' => $order])->save();

    return $node;
}

function posrecPosition(string $code, string $name, ?string $node = null, int $order = 0): Position
{
    $position = new Position;
    $position->forceFill(['code' => $code, 'name' => $name, 'org_node_id' => $node, 'sort_order' => $order])->save();

    return $position;
}

/** @return array<string, mixed> the mockup's structure */
function posrecWorld(): array
{
    EmploymentStatusOption::query()->create(['code' => '1', 'name' => 'Regular']);
    EmploymentStatusOption::query()->create(['code' => '2', 'name' => 'Probationary']);
    $site = WorkSite::query()->create(['name' => 'Head Office', 'latitude' => 14.5995, 'longitude' => 120.9842, 'radius_meters' => 100, 'geofence_exempt' => false]);
    $president = posrecNode('1', OrgNode::KIND_DEPARTMENT, 'Office of the President', null, 1);
    $engineering = posrecNode('2', OrgNode::KIND_DEPARTMENT, 'Engineering Department', null, 2);
    $software = posrecNode('3', OrgNode::KIND_UNIT, 'Software Development', $engineering->id, 1);
    $platform = posrecNode('5', OrgNode::KIND_UNIT, 'Platform and Infrastructure', $engineering->id, 2);
    $hr = posrecNode('4', OrgNode::KIND_DEPARTMENT, 'HR Department', null, 3);

    return [
        'site' => $site, 'president' => $president, 'engineering' => $engineering, 'software' => $software, 'platform' => $platform, 'hr' => $hr,
        'cto' => posrecPosition('10', 'Chief Technology Officer', $engineering->id, 1),
        'director' => posrecPosition('13', 'Engineering Director / Technical Lead Manager', $software->id, 1),
        'senior' => posrecPosition('16', 'Senior Full-Stack Developer', $software->id, 2),
        'junior' => posrecPosition('8', 'Junior Full-Stack Developer', $software->id, 3),
        'sre' => posrecPosition('20', 'Site Reliability Engineer', $platform->id, 1),
        'admin' => posrecPosition('21', 'Administrative Officer', $hr->id, 1),
        'driver' => posrecPosition('7', 'Driver'),
    ];
}

function posrecEmployee(WorkSite $site, ?Position $position, ?string $title = null): EmployeeProfile
{
    static $sequence = 0;

    $employee = EmployeeProfile::query()->create([
        'employee_code' => 'POSREC-'.++$sequence, 'first_name' => 'Ramon', 'last_name' => 'Aquino',
        'position' => $position === null ? $title : $position->name, 'position_level' => 'Senior', 'employment_status' => 'Regular',
        'date_hired' => '2024-01-15', 'work_site_id' => $site->id, 'monthly_rate' => 20000,
    ]);

    if ($position !== null) {
        $employee->forceFill(['position_id' => $position->id])->save();
    }

    return $employee;
}

/**
 * The Profile tab's Employee Details card, cut from the record's HTML.
 *
 * @param  Testable<Show>  $page
 */
function posrecCard(Testable $page): string
{
    $html = $page->html();
    $start = strpos($html, 'wire:key="section-employment"');
    expect($start)->not->toBeFalse();
    $end = strpos($html, 'wire:key="section-', $start + 30);

    return substr($html, (int) $start, $end === false ? null : $end - $start);
}

/** @return list<string> the text of each `<dt>` in order */
function posrecLabels(string $card): array
{
    preg_match_all('/<dt[^>]*>(.*?)<\/dt>/s', $card, $m);

    return array_map(static fn (string $t): string => trim(strip_tags($t)), $m[1]);
}

/** @return array<string, string> label => text of each read row */
function posrecRows(string $card): array
{
    preg_match_all('/<dt[^>]*>(.*?)<\/dt>\s*<dd[^>]*>(.*?)<\/dd>/s', $card, $m);
    $rows = [];
    foreach ($m[1] as $i => $label) {
        $rows[trim(strip_tags($label))] = trim(preg_replace('/\s+/', ' ', strip_tags(str_replace('<span', ' <span', $m[2][$i]))) ?? '');
    }

    return $rows;
}

/**
 * The options of the select bound to `$model`, value => text.
 *
 * @return array<string, string>
 */
function posrecOptions(string $html, string $model): array
{
    $at = strpos($html, 'wire:model.live="'.$model.'"');
    expect($at)->not->toBeFalse();
    $end = strpos($html, '</select>', (int) $at);
    preg_match_all('/<option([^>]*)>(.*?)<\/option>/s', substr($html, (int) $at, (int) $end - (int) $at), $m);
    $options = [];
    foreach ($m[1] as $i => $attributes) {
        preg_match('/value="([^"]*)"/', $attributes, $value);
        $options[html_entity_decode($value[1] ?? '')] = html_entity_decode(trim($m[2][$i]));
    }

    return $options;
}

/* |------------------------------------------------------------------| Read |---| */

it('reads Department | Unit, then Position | Position Level', function () {
    $world = posrecWorld();
    $employee = posrecEmployee($world['site'], $world['director']);
    posrecActor();

    $card = posrecCard(Livewire::test(Show::class, ['employee' => $employee->id]));

    expect(array_slice(posrecLabels($card), 0, 4))->toBe(['Department', 'Unit', 'Position', 'Position Level']);
    $rows = posrecRows($card);
    expect($rows['Department'])->toBe('Engineering Department')
        ->and($rows['Unit'])->toBe('Software Development')
        ->and($rows['Position'])->toBe('Engineering Director / Technical Lead Manager')
        ->and($rows['Position Level'])->toBe('Senior');

    // In the department itself: no unit.
    $cto = posrecEmployee($world['site'], $world['cto']);
    $rows = posrecRows(posrecCard(Livewire::test(Show::class, ['employee' => $cto->id])));
    expect($rows['Department'])->toBe('Engineering Department')->and($rows['Unit'])->toBe('—');
});

it('reads a title that is not on the Position list with no department, and says so', function () {
    $world = posrecWorld();
    $employee = posrecEmployee($world['site'], null, 'Messenger');
    posrecActor();

    $rows = posrecRows(posrecCard(Livewire::test(Show::class, ['employee' => $employee->id])));

    expect($rows['Department'])->toBe('—')
        ->and($rows['Unit'])->toBe('—')
        ->and($rows['Position'])->toBe('Messenger Messenger is not on the Position list.');

    // A record with no position at all reads three dashes and no note.
    $blank = posrecEmployee($world['site'], null);
    $rows = posrecRows(posrecCard(Livewire::test(Show::class, ['employee' => $blank->id])));
    expect([$rows['Department'], $rows['Unit'], $rows['Position']])->toBe(['—', '—', '—']);
});

/* |------------------------------------------------------------------| Edit |---| */

it('edits with three selects that start where the position sits and list only its place', function () {
    $world = posrecWorld();
    $employee = posrecEmployee($world['site'], $world['director']);
    posrecActor();

    $page = Livewire::test(Show::class, ['employee' => $employee->id])->call('startEdit', 'employment')
        ->assertSet('form.department_id', $world['engineering']->id)
        ->assertSet('form.unit_id', $world['software']->id)
        ->assertSet('form.position_id', $world['director']->id);
    $html = posrecCard($page);

    expect(posrecOptions($html, 'form.department_id'))->toBe([
        '' => 'Choose a department',
        PositionPicker::NOT_PLACED => 'Not placed',
        $world['president']->id => 'Office of the President',
        $world['engineering']->id => 'Engineering Department',
        $world['hr']->id => 'HR Department',
    ])->and(posrecOptions($html, 'form.unit_id'))->toBe([
        '' => 'No unit — in the department itself',
        $world['software']->id => 'Software Development',
        $world['platform']->id => 'Platform and Infrastructure',
    ])->and(posrecOptions($html, 'form.position_id'))->toBe([
        '' => 'Not set',
        $world['director']->id => 'Engineering Director / Technical Lead Manager',
        $world['senior']->id => 'Senior Full-Stack Developer',
        $world['junior']->id => 'Junior Full-Stack Developer',
    ]);
    expect($html)->toContain('Only the positions of the chosen department or unit are listed.')
        ->not->toContain('(not on the Position list)');

    // The department itself lists its own positions; Not placed the unplaced ones.
    $html = posrecCard($page->set('form.unit_id', ''));
    expect(posrecOptions($html, 'form.position_id'))->toBe(['' => 'Not set', $world['cto']->id => 'Chief Technology Officer']);
    $page->set('form.department_id', PositionPicker::NOT_PLACED);
    $html = posrecCard($page);
    expect(posrecOptions($html, 'form.position_id'))->toBe(['' => 'Not set', $world['driver']->id => 'Driver'])
        ->and(posrecOptions($html, 'form.unit_id'))->toBe(['' => '—']);
});

it('drops a unit and a position the new department does not list', function () {
    $world = posrecWorld();
    $employee = posrecEmployee($world['site'], $world['director']);
    posrecActor();

    Livewire::test(Show::class, ['employee' => $employee->id])->call('startEdit', 'employment')
        ->set('form.department_id', $world['hr']->id)
        ->assertSet('form.unit_id', '')
        ->assertSet('form.position_id', '')
        ->set('form.position_id', $world['admin']->id)
        ->call('saveSection', 'employment')
        ->assertHasNoErrors();

    expect($employee->refresh()->position_id)->toBe($world['admin']->id)
        ->and($employee->position)->toBe('Administrative Officer');
});

it('keeps a title off the list while HR saves the rest, and links it once one is picked', function () {
    $world = posrecWorld();
    $employee = posrecEmployee($world['site'], null, 'Messenger');
    posrecActor();

    $page = Livewire::test(Show::class, ['employee' => $employee->id])->call('startEdit', 'employment')
        ->assertSet('form.department_id', '')
        ->assertSet('form.position_id', EmployeePosition::KEEP);
    $html = posrecCard($page);

    expect(posrecOptions($html, 'form.unit_id'))->toBe(['' => 'Choose a department first'])
        ->and(posrecOptions($html, 'form.position_id'))->toBe(['' => 'Not set', EmployeePosition::KEEP => 'Messenger (not on the Position list)'])
        ->and($html)->toContain('Choose a department to pick a position from the list.');

    $page->set('form.position_level', 'Lead')->call('saveSection', 'employment');
    expect($employee->refresh()->position)->toBe('Messenger')
        ->and($employee->position_id)->toBeNull()
        ->and($employee->position_level)->toBe('Lead');

    // Choosing a department lists its positions and still keeps the title
    // chosen until a listed one replaces it.
    $page->call('startEdit', 'employment')
        ->set('form.department_id', PositionPicker::NOT_PLACED)
        ->assertSet('form.position_id', EmployeePosition::KEEP);
    expect(posrecOptions(posrecCard($page), 'form.position_id'))->toBe([
        '' => 'Not set', EmployeePosition::KEEP => 'Messenger (not on the Position list)', $world['driver']->id => 'Driver',
    ]);

    $page->set('form.position_id', $world['driver']->id)->call('saveSection', 'employment');
    expect($employee->refresh()->position_id)->toBe($world['driver']->id)->and($employee->position)->toBe('Driver');

    $rows = posrecRows(posrecCard($page));
    expect($rows['Department'])->toBe('Not placed')->and($rows['Position'])->toBe('Driver');
});

it('saves the placement of the position, not the department and unit the page sent', function () {
    $world = posrecWorld();
    $employee = posrecEmployee($world['site'], $world['cto']);
    posrecActor();

    // Drafts pointing at HR, a position from Engineering's unit sent by hand.
    Livewire::test(Show::class, ['employee' => $employee->id])->call('startEdit', 'employment')
        ->set('form.department_id', $world['hr']->id)
        ->set('form.position_id', $world['senior']->id)
        ->call('saveSection', 'employment');

    expect($employee->refresh()->position_id)->toBe($world['senior']->id);
    $rows = posrecRows(posrecCard(Livewire::test(Show::class, ['employee' => $employee->id])));
    expect($rows['Department'])->toBe('Engineering Department')->and($rows['Unit'])->toBe('Software Development');
});

it('changes nothing for a viewer who may not edit Employee Details', function () {
    $world = posrecWorld();
    $employee = posrecEmployee($world['site'], null, 'Messenger');
    posrecActor(false, $employee);

    Livewire::test(Show::class, ['employee' => $employee->id])->call('startEdit', 'employment')
        ->set('form.position_id', $world['driver']->id)
        ->call('saveSection', 'employment');

    expect($employee->refresh()->position_id)->toBeNull()
        ->and($employee->position)->toBe('Messenger')
        ->and(AuditLog::query()->count())->toBe(0);
});

/* |------------------------------------------------------------------| Wizard |---| */

it('hires through the wizard with the same three selects and no KEEP', function () {
    $world = posrecWorld();
    posrecActor();

    $page = Livewire::test(AddEmployeeDialog::class)->call('openDialog')
        ->set('step', 3);
    $html = $page->html();
    expect(posrecOptions($html, 'form.department_id'))->toHaveKeys(['', PositionPicker::NOT_PLACED, $world['engineering']->id])
        ->and(posrecOptions($html, 'form.unit_id'))->toBe(['' => 'Choose a department first'])
        ->and(posrecOptions($html, 'form.position_id'))->toBe(['' => 'Not set'])
        ->and($html)->toContain('Choose a department to pick a position from the list.');

    $page->set('form.department_id', $world['engineering']->id)->set('form.unit_id', $world['platform']->id);
    expect(posrecOptions($page->html(), 'form.position_id'))->toBe(['' => 'Not set', $world['sre']->id => 'Site Reliability Engineer']);

    $page->set('form.employee_id_no', '2026-POS')->set('form.first_name', 'Juan')->set('form.last_name', 'Dela Cruz')->set('form.phone', '09171234567')
        ->set('form.email', 'juan.wizard.pos@company.com')->set('form.birth_date', '1990-05-01')
        ->set('form.res_street', '123 Mabini St.')->set('form.sex', 'Male')->set('form.civil_status', 'Single')
        ->set('form.emergency_name', 'Maria Dela Cruz')->set('form.emergency_relation', 'Spouse')->set('form.emergency_phone', '09179876543')
        ->set('form.employment_status', 'Probationary')->set('form.work_site_id', $world['site']->id)
        ->set('form.date_hired', '2026-08-01')->set('form.monthly_rate', '25000');

    // Without a position the hire stops on Employee Details.
    $page->call('create')->assertSet('step', 3)->assertSee('Position is required.');

    $page->set('form.position_id', $world['sre']->id)->call('create')->assertDispatched('employee-created');

    $hired = EmployeeProfile::query()->where('email', 'juan.wizard.pos@company.com')->sole();
    expect($hired->position_id)->toBe($world['sre']->id)->and($hired->position)->toBe('Site Reliability Engineer');
});
