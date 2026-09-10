<?php

declare(strict_types=1);

use App\Livewire\Concerns\TableDefaults;
use App\Livewire\Concerns\WithSorting;
use App\Livewire\Concerns\WithTableFilters;
use App\Livewire\Kit\DateText;
use App\Livewire\Kit\EmployeeChecklist;
use App\Livewire\Kit\EmployeePicker;
use App\Livewire\Kit\TimeText;
use App\Models\EmployeeProfile;
use App\Models\Role;
use App\Models\RoleModuleGrant;
use App\Models\User;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Str;
use Livewire\Component;
use Livewire\Livewire;

/**
 * Smoke tests for the shared widget kit: the WithSorting / WithTableFilters
 * traits (the server-side port of useSort / usePagination), the date/time
 * parsing behind <x-kit.date-field> and <x-kit.time-field>, the scoped
 * kit.employee-picker, and a compile pass over every x-kit.* blade component.
 */
final class KitProbe extends Component
{
    use WithSorting;
    use WithTableFilters;

    /** @var array<string, mixed> */
    public array $out = [];

    public function mount(): void
    {
        $this->sortKey = 'name';
    }

    /** @return list<string> */
    protected function sortableKeys(): array
    {
        return ['name', 'code'];
    }

    public function runPaginateCollection(int $count): void
    {
        $paginator = $this->paginateClamped(collect(range(1, $count)));

        $this->out = [
            'page' => $paginator->currentPage(),
            'last' => $paginator->lastPage(),
            'total' => $paginator->total(),
            'items' => array_values($paginator->items()),
        ];
    }

    public function runPaginateQuery(): void
    {
        $paginator = $this->paginateClamped(Role::query()->orderBy('name'));

        $this->out = [
            'page' => $paginator->currentPage(),
            'last' => $paginator->lastPage(),
            'total' => $paginator->total(),
            'count' => count($paginator->items()),
        ];
    }

    public function runSort(): void
    {
        $this->out = [
            'names' => $this->applySort(Role::query(), ['name' => 'name'])->pluck('name')->all(),
        ];
    }

    public function render(): string
    {
        return '<div></div>';
    }
}

function kitRole(array $modules, bool $allData): Role
{
    $role = new Role;
    $role->forceFill(['name' => 'Kit '.Str::random(6), 'all_data' => $allData, 'is_system' => false])->save();

    foreach ($modules as $module) {
        $grant = new RoleModuleGrant;
        $grant->forceFill(['role_id' => $role->getKey(), 'module' => $module])->save();
    }

    return $role;
}

function kitActor(array $modules, bool $allData, ?string $employeeId = null): User
{
    $user = User::factory()->create([
        'role_id' => kitRole($modules, $allData)->getKey(),
        'employee_id' => $employeeId,
    ]);

    test()->actingAs($user);

    return $user;
}

function kitEmployee(string $code, string $first, string $last, ?string $position = null): EmployeeProfile
{
    $employee = new EmployeeProfile;
    $employee->forceFill([
        'employee_code' => $code,
        'first_name' => $first,
        'last_name' => $last,
        'position' => $position,
        'employment_status' => 'Regular',
        'date_hired' => '2024-01-01',
        'is_active' => true,
    ])->save();

    return $employee;
}

/*
|--------------------------------------------------------------------------
| WithSorting — the useSort port
|--------------------------------------------------------------------------
*/

it('flips direction on the same key and starts a new key ascending', function () {
    Livewire::test(KitProbe::class)
        ->assertSet('sortKey', 'name')
        ->assertSet('sortDir', 'asc')
        ->call('sortBy', 'name')
        ->assertSet('sortDir', 'desc')
        ->call('sortBy', 'name')
        ->assertSet('sortDir', 'asc')
        ->call('sortBy', 'code')
        ->assertSet('sortKey', 'code')
        ->assertSet('sortDir', 'asc');
});

it('drops a sort key outside the allow-list', function () {
    Livewire::test(KitProbe::class)
        ->call('sortBy', 'salary; drop table users')
        ->assertSet('sortKey', 'name')
        ->assertSet('sortDir', 'asc');
});

it('applies the active sort to a query in both directions', function () {
    foreach (['Bravo', 'Alpha', 'Charlie'] as $name) {
        (new Role)->forceFill(['name' => $name, 'all_data' => false, 'is_system' => false])->save();
    }

    Livewire::test(KitProbe::class)
        ->call('runSort')
        ->assertSet('out.names', ['Alpha', 'Bravo', 'Charlie'])
        ->call('sortBy', 'name') // same key: flips to desc
        ->call('runSort')
        ->assertSet('out.names', ['Charlie', 'Bravo', 'Alpha']);
});

/*
|--------------------------------------------------------------------------
| WithTableFilters — the usePagination port
|--------------------------------------------------------------------------
*/

it('collapses a tampered page size back to the default and resets the page', function () {
    Livewire::test(KitProbe::class)
        ->call('gotoPage', 3)
        ->set('perPage', 999)
        ->assertSet('perPage', TableDefaults::DEFAULT_PAGE_SIZE)
        ->assertSet('paginators.page', 1)
        ->set('perPage', 50)
        ->assertSet('perPage', 50);
});

it('paginates a collection and clamps an overflowing page to the LAST page', function () {
    Livewire::test(KitProbe::class)
        ->call('runPaginateCollection', 45)
        ->assertSet('out.page', 1)
        ->assertSet('out.last', 3)
        ->assertSet('out.total', 45)
        ->assertSet('out.items', range(1, 20))
        // Page 9 does not exist for 45 rows at 20 a page: land on page 3 with
        // the last five rows, not on an empty table and not back at page 1.
        ->call('gotoPage', 9)
        ->call('runPaginateCollection', 45)
        ->assertSet('out.page', 3)
        ->assertSet('out.items', range(41, 45));
});

it('clamps an overflowing page for a query the same way', function () {
    foreach (range(1, 25) as $i) {
        (new Role)->forceFill([
            'name' => sprintf('Role %02d', $i),
            'all_data' => false,
            'is_system' => false,
        ])->save();
    }

    Livewire::test(KitProbe::class)
        ->call('gotoPage', 7)
        ->call('runPaginateQuery')
        ->assertSet('out.page', 2)
        ->assertSet('out.last', 2)
        ->assertSet('out.total', 25)
        ->assertSet('out.count', 5);
});

/*
|--------------------------------------------------------------------------
| DateText — the x-kit.date-field parsing rules
|--------------------------------------------------------------------------
*/

it('parses every accepted date spelling to the storage key', function (string $typed, string $key) {
    expect(DateText::toKey($typed))->toBe($key);
})->with([
    'worded' => ['20 Aug 2026', '2026-08-20'],
    'full month word' => ['20 August 2026', '2026-08-20'],
    'slashed' => ['20/08/2026', '2026-08-20'],
    'dotted' => ['20.08.2026', '2026-08-20'],
    'dashed dmy' => ['20-08-2026', '2026-08-20'],
    'iso' => ['2026-08-20', '2026-08-20'],
    'iso single digits' => ['2026-8-5', '2026-08-05'],
    'empty' => ['', ''],
    'half-finished' => ['20 Au', ''],
    'not a month' => ['20 Xyz 2026', ''],
    'rolled-over day' => ['31/04/2026', ''],
    'impossible day' => ['32/01/2026', ''],
    'leap day on a leap year' => ['29/02/2028', '2028-02-29'],
    'leap day off a leap year' => ['29/02/2026', ''],
]);

it('renders the storage key as the canonical display spelling', function () {
    expect(DateText::toDisplay('2026-08-20'))->toBe('20 Aug 2026')
        ->and(DateText::toDisplay(''))->toBe('')
        ->and(DateText::toDisplay('garbage'))->toBe('');
});

/*
|--------------------------------------------------------------------------
| TimeText — the x-kit.time-field parsing rules
|--------------------------------------------------------------------------
*/

it('parses the typed clock plus AM/PM into the 24-hour key', function (string $clock, string $period, string $key) {
    expect(TimeText::toKey($clock, $period))->toBe($key);
})->with([
    'morning' => ['8:30', 'AM', '08:30'],
    'afternoon' => ['8:30', 'PM', '20:30'],
    'noon' => ['12:00', 'PM', '12:00'],
    'midnight' => ['12:00', 'AM', '00:00'],
    'bare digits' => ['830', 'AM', '08:30'],
    'four digits' => ['1230', 'PM', '12:30'],
    'bare hour' => ['8', 'AM', '08:00'],
    'zero-padded' => ['08:05', 'AM', '08:05'],
    'empty' => ['', 'AM', ''],
    'hour out of the 12-hour clock' => ['13:00', 'PM', ''],
    'minutes past 59' => ['8:75', 'AM', ''],
    'not a time' => ['8:x0', 'AM', ''],
]);

it('splits a stored key into typed-clock parts', function () {
    expect(TimeText::parts('17:30'))->toBe(['clock' => '5:30', 'period' => 'PM'])
        ->and(TimeText::parts('00:15'))->toBe(['clock' => '12:15', 'period' => 'AM'])
        ->and(TimeText::parts('12:00'))->toBe(['clock' => '12:00', 'period' => 'PM'])
        ->and(TimeText::parts(''))->toBeNull()
        ->and(TimeText::parts('25:00'))->toBeNull();
});

/*
|--------------------------------------------------------------------------
| kit.employee-picker — scoped server search
|--------------------------------------------------------------------------
*/

it('lists the whole directory for an all-data directory role and narrows by every search term', function () {
    $garcia = kitEmployee('BWF-0001', 'Ana', 'Garcia', 'Driver');
    kitEmployee('BWF-0002', 'Ben', 'Reyes', 'Clerk');
    kitActor(['EMPLOYEES'], allData: true);

    Livewire::test(EmployeePicker::class, ['name' => 'assignee'])
        ->assertSee('Ana Garcia')
        ->assertSee('Ben Reyes')
        ->set('search', 'bwf garcia')
        ->assertSee('Ana Garcia')
        ->assertDontSee('Ben Reyes')
        ->call('choose', $garcia->getKey())
        ->assertSet('value', (string) $garcia->getKey())
        ->assertSet('search', '')
        ->assertDispatched('kit-employee-picked', name: 'assignee', id: (string) $garcia->getKey());
});

it('shows an employee-less account with no directory module NO employees at all', function () {
    $garcia = kitEmployee('BWF-0001', 'Ana', 'Garcia');
    kitActor(['PORTAL'], allData: false, employeeId: null);

    Livewire::test(EmployeePicker::class)
        ->assertDontSee('Ana Garcia')
        ->assertSee('No employee matches that search.')
        // A pick smuggled in from the client is dropped, not selected.
        ->call('choose', $garcia->getKey())
        ->assertSet('value', '')
        ->assertNotDispatched('kit-employee-picked');
});

it('shows a linked account without directory modules only its own row', function () {
    $own = kitEmployee('BWF-0001', 'Ana', 'Garcia');
    kitEmployee('BWF-0002', 'Ben', 'Reyes');
    kitActor(['PORTAL'], allData: false, employeeId: (string) $own->getKey());

    Livewire::test(EmployeePicker::class)
        ->assertSee('Ana Garcia')
        ->assertDontSee('Ben Reyes');
});

it('refuses a client write to the locked selection', function () {
    kitActor(['EMPLOYEES'], allData: true);

    expect(fn () => Livewire::test(EmployeePicker::class)->set('value', 'smuggled'))
        ->toThrow(Exception::class);
});

/*
|--------------------------------------------------------------------------
| kit.employee-checklist — the multi-select counterpart
|--------------------------------------------------------------------------
*/

it('renders the scoped directory as a checklist and narrows by every search term', function () {
    kitEmployee('BWF-0001', 'Ana', 'Garcia', 'Driver');
    kitEmployee('BWF-0002', 'Ben', 'Reyes', 'Clerk');
    kitActor(['PROJECTS'], allData: true);

    Livewire::test(EmployeeChecklist::class, ['name' => 'task-assignees'])
        ->assertSee('Ana Garcia')
        ->assertSee('Ben Reyes')
        ->assertSee('Driver')
        ->assertSee('No one selected')
        ->assertSeeHtml('type="checkbox"')
        ->set('search', 'bwf reyes')
        ->assertSee('Ben Reyes')
        ->assertDontSee('Ana Garcia');
});

it('is discoverable by its kit name', function () {
    kitEmployee('BWF-0001', 'Ana', 'Garcia');
    kitActor(['PROJECTS'], allData: true);

    $html = Blade::render('<livewire:kit.employee-checklist name="task-assignees" aria-label="Assignees" />');

    expect($html)->toContain('Ana Garcia')->toContain('aria-label="Assignees"');
});

it('toggles an id on and then off again, keeping the summary in step', function () {
    $garcia = kitEmployee('BWF-0001', 'Ana', 'Garcia');
    $reyes = kitEmployee('BWF-0002', 'Ben', 'Reyes');
    kitActor(['PROJECTS'], allData: true);

    Livewire::test(EmployeeChecklist::class)
        ->call('toggle', $garcia->getKey())
        ->assertSet('value', [(string) $garcia->getKey()])
        ->assertSee('1 selected — Ana Garcia')
        ->call('toggle', $reyes->getKey())
        ->assertSet('value', [(string) $garcia->getKey(), (string) $reyes->getKey()])
        ->assertSee('2 selected — Ana Garcia, Ben Reyes')
        ->call('toggle', $garcia->getKey())
        ->assertSet('value', [(string) $reyes->getKey()])
        ->assertSee('1 selected — Ben Reyes');
});

it('ignores an id outside the actor directory and an inactive employee', function () {
    $own = kitEmployee('BWF-0001', 'Ana', 'Garcia');
    $other = kitEmployee('BWF-0002', 'Ben', 'Reyes');
    $retired = kitEmployee('BWF-0003', 'Cid', 'Santos');
    $retired->forceFill(['is_active' => false])->save();
    kitActor(['PORTAL'], allData: false, employeeId: (string) $own->getKey());

    Livewire::test(EmployeeChecklist::class)
        ->assertSee('Ana Garcia')
        ->assertDontSee('Ben Reyes')
        ->call('toggle', $other->getKey())
        ->assertSet('value', [])
        ->call('toggle', 'smuggled')
        ->assertSet('value', [])
        ->assertSee('No one selected');

    kitActor(['PROJECTS'], allData: true);

    Livewire::test(EmployeeChecklist::class)
        ->assertDontSee('Cid Santos')
        ->call('toggle', $retired->getKey())
        ->assertSet('value', []);
});

it('keeps a selected employee selected while the search hides them', function () {
    $garcia = kitEmployee('BWF-0001', 'Ana', 'Garcia');
    kitEmployee('BWF-0002', 'Ben', 'Reyes');
    kitActor(['PROJECTS'], allData: true);

    Livewire::test(EmployeeChecklist::class, ['value' => [(string) $garcia->getKey()]])
        ->assertSee('1 selected — Ana Garcia')
        ->set('search', 'reyes')
        ->assertDontSeeHtml('ecl-option-'.$garcia->getKey())
        ->assertSee('Ben Reyes')
        ->assertSet('value', [(string) $garcia->getKey()])
        ->assertSee('1 selected — Ana Garcia')
        ->set('search', '')
        ->assertSeeHtml('checked');
});

it('names a selected employee only through the actor scope', function () {
    $own = kitEmployee('BWF-0001', 'Ana', 'Garcia');
    $other = kitEmployee('BWF-0002', 'Ben', 'Reyes');
    kitActor(['PORTAL'], allData: false, employeeId: (string) $own->getKey());

    // A smuggled id in the entangled array renders no name the actor may not read.
    Livewire::test(EmployeeChecklist::class, ['value' => [(string) $other->getKey(), (string) $own->getKey()]])
        ->assertDontSee('Ben Reyes')
        ->assertSee('1 selected — Ana Garcia');
});

/*
|--------------------------------------------------------------------------
| The blade widgets compile and render
|--------------------------------------------------------------------------
*/

it('renders every x-kit blade component', function () {
    $sortHeader = Blade::render(
        '<table><thead><tr><x-kit.sort-header field="name" label="Employee" /></tr></thead></table>'
    );
    expect($sortHeader)->toContain('sortBy')->toContain('aria-sort')->toContain('Employee');

    $paginator = new LengthAwarePaginator(range(1, 20), 45, 20, 2, ['path' => '/']);
    $pagination = Blade::render('<x-kit.pagination :paginator="$paginator" noun="punches" />', ['paginator' => $paginator]);
    expect($pagination)
        ->toContain('Showing')
        ->toContain('punches')
        ->toContain('2 / 3')
        ->toContain('Previous page')
        ->toContain('Next page');

    $empty = new LengthAwarePaginator([], 0, 20, 1, ['path' => '/']);
    expect(Blade::render('<x-kit.pagination :paginator="$paginator" noun="punches" />', ['paginator' => $empty]))
        ->toContain('No punches');

    $filter = Blade::render(
        '<x-kit.filter-select label="Status filter" wire:model.live="status" :options="[\'ALL\' => \'All statuses\', \'ACTIVE\' => \'Active\']" />'
    );
    expect($filter)->toContain('All statuses')->toContain('Clear status filter')->toContain("entangle('status').live");

    $rowActions = Blade::render(
        '<x-kit.row-actions label="Driver"><flux:menu.item icon="pencil">Edit</flux:menu.item></x-kit.row-actions>'
    );
    expect($rowActions)->toContain('Actions for Driver')->toContain('Edit');

    $dateField = Blade::render('<x-kit.date-field wire:model="form.date_hired" aria-label="Date hired" />');
    expect($dateField)->toContain("entangle('form.date_hired')")->toContain('dd Mmm yyyy')->toContain('type="date"');

    $timeField = Blade::render('<x-kit.time-field wire:model="form.time_in" aria-label="Time in" />');
    expect($timeField)->toContain("entangle('form.time_in')")->toContain('AM')->toContain('PM');

    $money = Blade::render('<x-kit.money-input wire:model="form.monthly_rate" aria-label="Monthly rate" />');
    expect($money)->toContain("entangle('form.monthly_rate')")->toContain('0.00')->toContain('text-right');

    $emptyRow = Blade::render('<table><tbody><tr><x-kit.empty-state :colspan="7" title="No holiday matches those filters." /></tr></tbody></table>');
    expect($emptyRow)->toContain('colspan="7"')->toContain('No holiday matches those filters.');

    $emptyBlock = Blade::render('<x-kit.empty-state icon="inbox" title="Nothing to catch up on." hint="Notifications land here." />');
    expect($emptyBlock)->toContain('Nothing to catch up on.')->toContain('Notifications land here.');
});
