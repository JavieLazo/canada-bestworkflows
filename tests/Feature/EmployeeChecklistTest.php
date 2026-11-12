<?php

declare(strict_types=1);

use App\Domain\Enums\ModuleCode;
use App\Livewire\Kit\EmployeeChecklist;
use App\Models\EmployeeProfile;
use App\Models\Role;
use App\Models\RoleModuleGrant;
use App\Models\User;
use App\Platform\Reports\ReportCriteria;
use App\Support\Access;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\View\ViewException;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

/** Report selections may include former staff, but never widen their source's employee scope. */
function eclEmployee(string $name, ?string $position = null, bool $active = true): EmployeeProfile
{
    $employee = new EmployeeProfile;
    $employee->forceFill([
        'employee_code' => 'ECL-'.Str::random(8),
        'first_name' => $name,
        'last_name' => 'Employee',
        'position' => $position,
        'employment_status' => 'Regular',
        'date_hired' => '2024-01-01',
        'is_active' => $active,
    ])->save();

    return $employee;
}

/** @param list<string> $modules */
function eclActor(array $modules, bool $allData = true, ?string $employeeId = null): User
{
    $role = new Role;
    $role->forceFill(['name' => 'Checklist '.Str::random(8), 'all_data' => $allData, 'is_system' => false])->save();

    foreach ($modules as $module) {
        $grant = new RoleModuleGrant;
        $grant->forceFill(['role_id' => $role->getKey(), 'module' => $module])->save();
    }

    $actor = User::factory()->create(['role_id' => $role->getKey(), 'employee_id' => $employeeId]);
    test()->actingAs($actor);
    app()->forgetInstance(Access::class);

    return $actor;
}

/**
 * Employees named "Person 01" … with fixed codes, so a numeric search term
 * matches only its own row (eclEmployee's random code could contain it).
 *
 * @return Collection<int, EmployeeProfile>
 */
function eclNumbered(int $count, ?string $position = null): Collection
{
    return collect(range(1, $count))->map(static function (int $n) use ($position): EmployeeProfile {
        $employee = eclEmployee(sprintf('Person %02d', $n), $position);
        $employee->forceFill(['employee_code' => sprintf('ECL-%04d', $n)])->save();

        return $employee;
    });
}

it('preserves active-only assignments and allows an existing inactive assignee to be removed', function () {
    $active = eclEmployee('Active', 'Driver');
    $former = eclEmployee('Former', 'Clerk', active: false);
    eclActor([ModuleCode::PROJECTS]);

    Livewire::test(EmployeeChecklist::class)
        ->assertSet('activeOnly', true)
        ->assertSet('scopeModules', [])
        ->assertSee('Active Employee')
        ->assertDontSee('Former Employee')
        ->call('toggle', $former->getKey())
        ->assertSet('value', [])
        ->call('toggle', $active->getKey())
        ->assertSet('value', [(string) $active->getKey()]);

    Livewire::test(EmployeeChecklist::class, ['value' => [(string) $former->getKey()]])
        ->assertSee('1 selected — Former Employee')
        ->assertSee('inactive')
        ->call('toggle', $former->getKey())
        ->assertSet('value', [])
        ->assertDontSee('Former Employee');
});

it('selects former staff for reports and searches their displayed position', function () {
    $former = eclEmployee('Former', 'Senior Driver', active: false);
    eclEmployee('Active', 'Clerk');
    eclEmployee('Unassigned', '   ');
    eclActor([ModuleCode::PAYROLL]);

    Livewire::test(EmployeeChecklist::class, ['activeOnly' => false, 'scopeModules' => [ModuleCode::PAYROLL]])
        ->assertSee('Former Employee')
        ->assertSee('Senior Driver')
        ->assertSee('inactive')
        ->assertSee('No position')
        ->set('search', 'senior driver')
        ->assertSee('Former Employee')
        ->assertDontSee('Active Employee')
        ->assertDontSee('Unassigned Employee')
        ->call('toggle', $former->getKey())
        ->assertSet('value', [(string) $former->getKey()])
        ->set('search', 'no-match')
        ->assertSee('No employee matches that search.')
        ->assertSee('1 selected — Former Employee');
});

/** @param list<string> $sourceModules */
it('narrows report options toggles and smuggled summaries despite an unrelated all-data directory grant', function (array $sourceModules, string $otherModule) {
    $own = eclEmployee('Own', 'Clerk');
    $other = eclEmployee('Other', 'Foreign Position', active: false);
    eclActor([$otherModule], employeeId: (string) $own->getKey());

    Livewire::test(EmployeeChecklist::class, ['activeOnly' => false, 'scopeModules' => $sourceModules])
        ->assertSee('Own Employee')
        ->assertDontSee('Other Employee')
        ->assertDontSee('Foreign Position')
        ->call('toggle', $other->getKey())
        ->assertSet('value', [])
        ->call('toggle', $own->getKey())
        ->assertSet('value', [(string) $own->getKey()])
        ->set('value', [(string) $other->getKey(), (string) $own->getKey(), 'missing-employee'])
        ->assertDontSee('Other Employee')
        ->set('search', 'no-match')
        ->assertSee('1 selected — Own Employee')
        ->assertDontSee('Other Employee');
})->with([
    'employee list' => [[ModuleCode::EMPLOYEES, ModuleCode::PAYROLL], ModuleCode::ROSTER],
    'leaves' => [[ModuleCode::LEAVES], ModuleCode::PAYROLL],
    'attendance' => [[ModuleCode::EDTR], ModuleCode::PAYROLL],
    'finances' => [[ModuleCode::PAYROLL], ModuleCode::LEAVES],
]);

it('shows no report options or selected names for an unlinked actor with only another directory grant', function () {
    $other = eclEmployee('Other', 'Driver');
    eclActor([ModuleCode::EMPLOYEES]);

    Livewire::test(EmployeeChecklist::class, [
        'scopeModules' => [ModuleCode::LEAVES],
        'value' => [(string) $other->getKey()],
    ])
        ->assertSee('No one selected')
        ->assertSee('No employee matches that search.')
        ->assertDontSee('Other Employee')
        ->set('value', [])
        ->call('toggle', $other->getKey())
        ->assertSet('value', []);
});

/** @param list<string> $sourceModules */
it('allows an unlinked all-data source holder to select report employees', function (array $sourceModules, string $grant) {
    $employee = eclEmployee('Other');
    eclActor([$grant]);

    Livewire::test(EmployeeChecklist::class, ['scopeModules' => $sourceModules])
        ->assertSee('Other Employee')
        ->call('toggle', $employee->getKey())
        ->assertSet('value', [(string) $employee->getKey()])
        ->assertSee('1 selected — Other Employee');
})->with([
    'employee list via employees' => [[ModuleCode::EMPLOYEES, ModuleCode::PAYROLL], ModuleCode::EMPLOYEES],
    'employee list via payroll' => [[ModuleCode::EMPLOYEES, ModuleCode::PAYROLL], ModuleCode::PAYROLL],
    'leaves' => [[ModuleCode::LEAVES], ModuleCode::LEAVES],
    'attendance' => [[ModuleCode::EDTR], ModuleCode::EDTR],
    'finances' => [[ModuleCode::PAYROLL], ModuleCode::PAYROLL],
]);

it('keeps a source holder without all-data limited to their own employee', function () {
    $own = eclEmployee('Own');
    $other = eclEmployee('Other');
    eclActor([ModuleCode::LEAVES], allData: false, employeeId: (string) $own->getKey());

    Livewire::test(EmployeeChecklist::class, ['scopeModules' => [ModuleCode::LEAVES]])
        ->assertSee('Own Employee')
        ->assertDontSee('Other Employee')
        ->call('toggle', $other->getKey())
        ->assertSet('value', []);
});

it('rejects a scope that is not an operational directory module', function (string $module) {
    eclActor([ModuleCode::EMPLOYEES]);

    expect(fn () => Livewire::test(EmployeeChecklist::class, ['scopeModules' => [$module]]))
        ->toThrow(ViewException::class, 'Employee checklist scope must use operational directory modules.');
})->with([ModuleCode::AUDIT, 'unknown-module']);

it('locks report directory configuration against client changes', function (string $property, mixed $value) {
    eclActor([ModuleCode::EMPLOYEES]);

    expect(fn () => Livewire::test(EmployeeChecklist::class)->set($property, $value))
        ->toThrow(CannotUpdateLockedPropertyException::class);
})->with([
    'active staff option' => ['activeOnly', false],
    'source scope option' => ['scopeModules', [ModuleCode::EMPLOYEES]],
    'report filters option' => ['withFilters', true],
    'selection bound' => ['selectionLimit', 1000],
]);

it('selects matching employees and preserves hidden selections across filter and sort changes', function () {
    $driver = eclEmployee('Driver', 'Driver');
    $clerk = eclEmployee('Clerk', 'Clerk');
    $former = eclEmployee('Former', 'Driver', active: false);
    $former->forceFill(['employment_status' => 'JOB_ORDER'])->save();
    eclActor([ModuleCode::PAYROLL]);

    Livewire::test(EmployeeChecklist::class, ['activeOnly' => false, 'withFilters' => true, 'scopeModules' => [ModuleCode::PAYROLL]])
        ->call('toggle', $clerk->id)
        ->set('position', 'Driver')
        ->set('active', 'ACTIVE')
        ->set('sort', 'employee-id-desc')
        ->assertSet('value', [$clerk->id])
        ->call('selectAll')
        ->assertSet('value', [$clerk->id, $driver->id])
        ->set('active', 'INACTIVE')
        ->set('employmentStatus', 'JOB_ORDER')
        ->call('selectAll')
        ->assertSet('value', [$clerk->id, $driver->id, $former->id])
        ->call('deselectMatching')
        ->assertSet('value', [$clerk->id, $driver->id])
        ->call('resetFilters')
        ->assertSet('position', 'ALL')
        ->assertSet('active', 'ALL')
        ->assertSet('employmentStatus', 'ALL')
        ->assertSet('sort', 'name-asc')
        ->assertSet('value', [$clerk->id, $driver->id])
        ->call('selectAll')
        ->assertSet('value', [$clerk->id, $driver->id, $former->id])
        ->set('search', 'no-match')
        ->call('deselectAll')
        ->assertSet('value', []);
});

it('limits bulk additions atomically and allows deselection before trying again', function () {
    $first = eclEmployee('First', 'Driver');
    $second = eclEmployee('Second', 'Clerk');
    $third = eclEmployee('Third', 'Driver');
    eclActor([ModuleCode::PAYROLL]);

    Livewire::test(EmployeeChecklist::class, ['withFilters' => true, 'selectionLimit' => 2, 'value' => [$second->id]])
        ->set('position', 'Driver')
        ->call('selectAll')
        ->assertHasErrors(['value'])
        ->assertSet('value', [$second->id])
        ->call('deselectAll')
        ->assertHasNoErrors()
        ->call('selectAll')
        ->assertSet('value', [$first->id, $third->id])
        ->call('toggle', $second->id)
        ->assertHasErrors(['value'])
        ->assertSet('value', [$first->id, $third->id])
        ->call('toggle', $first->id)
        ->assertHasNoErrors()
        ->assertSet('value', [$third->id])
        ->call('toggle', $second->id)
        ->assertHasNoErrors()
        ->assertSet('value', [$third->id, $second->id]);
});

it('keeps bulk actions disabled for legacy or disabled checklist controls', function (array $options) {
    $employee = eclEmployee('Selected');
    eclEmployee('Other');
    eclActor([ModuleCode::PAYROLL]);

    Livewire::test(EmployeeChecklist::class, $options + ['value' => [$employee->id]])
        ->call('selectAll')
        ->assertSet('value', [$employee->id])
        ->call('deselectMatching')
        ->assertSet('value', [$employee->id])
        ->call('deselectAll')
        ->assertSet('value', [$employee->id]);
})->with([
    'legacy control' => [[]],
    'disabled report control' => [['withFilters' => true, 'disabled' => true]],
]);

it('keeps bulk selection and filter options within the narrower report source scope', function () {
    $own = eclEmployee('Own', 'Own Position');
    $other = eclEmployee('Other', 'Foreign Position');
    $other->forceFill(['employment_status' => 'JOB_ORDER'])->save();
    eclActor([ModuleCode::EMPLOYEES], employeeId: $own->id);

    Livewire::test(EmployeeChecklist::class, [
        'withFilters' => true, 'activeOnly' => false, 'scopeModules' => [ModuleCode::LEAVES],
        'value' => [$other->id, $own->id, 'missing'],
    ])
        ->assertDontSee('Foreign Position')
        ->assertDontSee('Job Order')
        ->call('selectAll')
        ->assertSet('value', [$own->id])
        ->set('position', 'Foreign Position')
        ->call('selectAll')
        ->assertSet('value', [$own->id])
        ->call('deselectMatching')
        ->assertSet('value', [$own->id])
        ->call('resetFilters')
        ->call('deselectMatching')
        ->assertSet('value', []);
});

it('rechecks grants when bulk selection is called after access is revoked', function () {
    eclEmployee('Other', 'Driver');
    $actor = eclActor([ModuleCode::PAYROLL]);
    $component = Livewire::test(EmployeeChecklist::class, ['withFilters' => true, 'scopeModules' => [ModuleCode::PAYROLL]])
        ->assertSee('Other Employee');

    RoleModuleGrant::query()->where('role_id', $actor->role_id)->where('module', ModuleCode::PAYROLL)->delete();
    $this->actingAs($actor->fresh());
    app()->forgetInstance(Access::class);

    $component->call('selectAll')
        ->assertSet('value', [])
        ->assertDontSee('Other Employee')
        ->assertDontSee('Driver');
});

it('can explicitly select the complete 201-person workforce and preserve it in download filters', function () {
    $employees = collect(range(1, 201))->map(static fn (int $number): EmployeeProfile => eclEmployee('Employee '.$number));
    eclActor([ModuleCode::PAYROLL]);

    $component = Livewire::test(EmployeeChecklist::class, ['withFilters' => true, 'selectionLimit' => ReportCriteria::MAX_EMPLOYEES])
        ->call('selectAll')->assertHasNoErrors();
    $selected = $component->get('value');
    $criteria = ReportCriteria::from([
        'type' => 'payroll-register', 'from' => '2026-09-01', 'to' => '2026-09-30', 'employeeIds' => $selected,
    ]);

    expect($selected)->toHaveCount(201)
        ->and(array_diff($employees->pluck('id')->all(), $selected))->toBe([])
        ->and(ReportCriteria::from($criteria->query())->employeeIds)->toBe($selected);
});

it('draws one page of matches while the counts and bulk selection cover every match', function () {
    $employees = eclNumbered(25, 'Driver');
    eclActor([ModuleCode::PAYROLL]);

    // The list is a window of one table page; "25 matching", Select all and
    // Deselect matching still mean all 25.
    $component = Livewire::test(EmployeeChecklist::class, ['withFilters' => true, 'activeOnly' => false]);
    expect(substr_count($component->html(), 'type="checkbox"'))->toBe(EmployeeChecklist::PAGE_SIZE);
    $component->assertViewHas('matchingCount', 25)
        ->assertViewHas('matchedSelectedCount', 0)
        ->assertSeeHtml('Showing <span class="num">20</span> of <span class="num">25</span>')
        ->assertSee('search or narrow the filters to find the others.');
    $component->call('selectAll')->assertViewHas('matchedSelectedCount', 25);
    expect($component->get('value'))->toHaveCount(25);

    $component->set('search', 'person 25')
        ->assertViewHas('matchingCount', 1)
        ->assertViewHas('matchedSelectedCount', 1)
        ->assertDontSeeHtml('data-ecl-more');
    $component->call('deselectMatching');
    expect($component->get('value'))->toHaveCount(24)
        ->not->toContain((string) $employees->last()?->getKey());
});

it('says to keep typing when a task checklist has more matches than it draws', function () {
    eclNumbered(25);
    eclActor([ModuleCode::PROJECTS]);

    // The live line says it for a screen reader, which never reaches the
    // visible (aria-hidden) footer by Tab.
    $component = Livewire::test(EmployeeChecklist::class)
        ->assertSeeHtml('Showing <span class="num">20</span> of <span class="num">25</span>')
        ->assertSee('keep typing to narrow.')
        ->assertSee('Showing the first 20 of 25 employees. Keep typing to narrow.');
    expect(substr_count($component->html(), 'type="checkbox"'))->toBe(EmployeeChecklist::PAGE_SIZE);
});

it('draws already-ticked task assignees first when they sort past the page, even after they left', function () {
    eclNumbered(25);
    $late = eclEmployee('Zed');
    $former = eclEmployee('Yuri', active: false);
    eclActor([ModuleCode::PROJECTS]);

    // A crew member past row 20 — or one who has since left — can be
    // unticked straight from the list as it opens.
    $component = Livewire::test(EmployeeChecklist::class, ['value' => [(string) $late->getKey(), (string) $former->getKey()]]);
    preg_match_all('/type="checkbox"[^>]*value="([^"]+)"/', $component->html(), $boxes);

    expect($boxes[1])->toHaveCount(EmployeeChecklist::PAGE_SIZE + 2)
        ->and(array_slice($boxes[1], 0, 2))->toEqualCanonicalizing([(string) $late->getKey(), (string) $former->getKey()]);
    $component->call('toggle', (string) $former->getKey())->assertSet('value', [(string) $late->getKey()]);

    // Typing shows the matches in order, nothing lifted.
    $component->set('search', 'person');
    preg_match_all('/type="checkbox"[^>]*value="([^"]+)"/', $component->html(), $typed);
    expect($typed[1])->toHaveCount(EmployeeChecklist::PAGE_SIZE)
        ->not->toContain((string) $late->getKey());
});

it('applies the active-staff rule through every active filter of a filtered checklist', function () {
    eclEmployee('Active');
    $ticked = eclEmployee('Ticked', active: false);
    eclEmployee('Former', active: false);
    eclActor([ModuleCode::PAYROLL]);

    // Filtered rows ∩ (active ∪ ticked), drawn and counted by the database.
    $component = Livewire::test(EmployeeChecklist::class, ['withFilters' => true, 'value' => [(string) $ticked->getKey()]]);
    $component->assertViewHas('matchingCount', 2)
        ->assertSee('Active Employee')
        ->assertSee('Ticked Employee')
        ->assertDontSee('Former Employee');

    $component->set('active', 'INACTIVE')
        ->assertViewHas('matchingCount', 1)
        ->assertViewHas('matchedSelectedCount', 1)
        ->assertSee('Ticked Employee')
        ->assertDontSee('Active Employee');

    $component->set('active', 'ACTIVE')
        ->assertViewHas('matchingCount', 1)
        ->assertViewHas('matchedSelectedCount', 0)
        ->assertSee('Active Employee')
        ->assertDontSee('Former Employee');
    expect($component->get('value'))->toBe([(string) $ticked->getKey()]);
});
