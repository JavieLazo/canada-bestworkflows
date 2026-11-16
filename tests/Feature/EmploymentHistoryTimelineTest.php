<?php

declare(strict_types=1);

use App\Domain\Enums\ModuleCode;
use App\Livewire\Employees\History;
use App\Models\EmployeeEmploymentHistory;
use App\Models\EmployeeProfile;
use App\Models\EmploymentStatusOption;
use App\Models\Position;
use App\Models\Role;
use App\Models\RoleModuleGrant;
use App\Models\User;
use App\Models\WorkSite;
use App\Modules\Workforce\Application\Commands\UpdateEmployee;
use App\Modules\Workforce\Application\EmployeeFields;
use App\Modules\Workforce\Application\EmploymentHistoryRecorder;
use App\Modules\Workforce\Application\Queries\EmploymentHistoryRecords;
use App\Modules\Workforce\Contracts\Views\EmploymentHistoryView;
use App\Modules\Workforce\Contracts\Views\EmploymentSubjectView;
use App\Support\Access;
use App\Support\ManilaClock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Livewire\Livewire;

/**
 * One employee's history reads as a timeline: a header naming them, their saved
 * edits newest first and their starting record last. WHO is shown is decided by
 * the owner's employee scope and never by the URL; the "Latest" mark comes from
 * the stored rows and never from the filters or the page; and the starting
 * record is a fact sheet, never a counted or exported change.
 */
function ehtlActor(bool $allData = true, ?EmployeeProfile $employee = null, string $module = ModuleCode::EMPLOYEES): User
{
    $role = Role::query()->create(['name' => 'Timeline viewer '.Str::random(8), 'all_data' => $allData, 'is_system' => false]);
    RoleModuleGrant::query()->create(['role_id' => $role->id, 'module' => $module]);
    $user = User::factory()->create(['role_id' => $role->id, 'employee_id' => $employee?->id]);
    test()->actingAs($user);
    app()->forgetInstance(Access::class);

    return $user;
}

function ehtlEmployee(string $name = 'Ana', bool $hired = true): EmployeeProfile
{
    $site = WorkSite::query()->firstOrCreate(['name' => 'Timeline site'], ['latitude' => 14.5, 'longitude' => 121, 'radius_meters' => 100]);
    Position::query()->firstOrCreate(['code' => 'TIMELINE-POS'], ['name' => 'Surveyor']);
    EmploymentStatusOption::query()->firstOrCreate(['code' => 'TIMELINE-STATUS'], ['name' => 'Regular']);
    $employee = EmployeeProfile::query()->create([
        'employee_code' => 'TL-'.$name, 'first_name' => $name, 'last_name' => 'Timeline',
        'position' => 'Surveyor', 'position_level' => 'Junior', 'employment_status' => 'Regular',
        'date_hired' => '2024-01-01', 'work_site_id' => $site->id,
        'monthly_rate' => 25000, 'daily_rate' => 1000, 'is_active' => true,
    ]);
    if ($hired) {
        app(EmploymentHistoryRecorder::class)->hired(app(Access::class), $employee->refresh());
    }

    return $employee;
}

/** @param array<string, mixed> $values */
function ehtlChange(EmployeeProfile $employee, array $values): void
{
    app(UpdateEmployee::class)->handle(app(Access::class), $employee->id, 'employment', $values + EmployeeFields::toForm($employee->refresh()));
}

/** The row the backfill migration writes for an employee already on file. */
function ehtlBaseline(EmployeeProfile $employee, string $effective, ?string $siteName = 'Timeline site'): EmployeeEmploymentHistory
{
    $row = new EmployeeEmploymentHistory;
    $row->forceFill([
        'employee_id' => $employee->id, 'event_type' => EmploymentHistoryRecorder::BASELINE, 'effective_date' => $effective,
        'changed_fields' => implode(',', EmploymentHistoryRecorder::FIELDS),
        'position' => $employee->position, 'position_level' => $employee->position_level,
        'employment_status' => $employee->employment_status, 'work_site_id' => $employee->work_site_id,
        'work_site_name' => $siteName, 'date_hired' => '2024-01-01', 'is_active' => true,
        'monthly_rate' => 25000, 'daily_rate' => 1000,
        'recorded_by' => null, 'recorded_by_name' => 'System backfill',
    ])->save();

    return $row;
}

function ehtlPath(string $html): DOMXPath
{
    $document = new DOMDocument;
    @$document->loadHTML('<?xml encoding="UTF-8">'.$html);

    return new DOMXPath($document);
}

function ehtlCount(DOMXPath $path, string $expression): int
{
    $nodes = $path->query($expression);
    assert($nodes !== false);

    return $nodes->length;
}

/** @return list<string> the text of every node the expression selects, attributes included */
function ehtlTexts(DOMXPath $path, string $expression): array
{
    $nodes = $path->query($expression);
    assert($nodes !== false);
    $texts = [];
    foreach ($nodes as $node) {
        // Elements and attributes only: these expressions never walk the namespace axis.
        if ($node instanceof DOMNode) {
            $texts[] = trim((string) preg_replace('/\s+/', ' ', (string) $node->textContent));
        }
    }

    return $texts;
}

const EHTL_CHANGE = '//*[@data-entry="change"]';
const EHTL_START = '//*[@data-entry="start"]';
const EHTL_SUBJECT = '//*[@data-subject]';
const EHTL_LATEST = '//*[@data-latest]';

/*
|--------------------------------------------------------------------------
| Who the timeline belongs to
|--------------------------------------------------------------------------
*/

it('shows the profile header only for an employee inside the viewer employees scope', function () {
    ehtlActor();
    $ana = ehtlEmployee();
    $ben = ehtlEmployee('Ben');

    $picked = ehtlPath(Livewire::test(History::class)->set('employeeId', $ana->id)->html());
    expect(ehtlCount($picked, EHTL_SUBJECT))->toBe(1)
        ->and(ehtlTexts($picked, EHTL_SUBJECT)[0])->toContain('Ana Timeline', 'Surveyor', 'Joined 01 Jan 2024', 'Regular')
        // Level, worksite and pay belong to the dated entries, never to the header.
        // One needle per negation: Pest passes a negated multi-needle check when ANY one is absent.
        ->not->toContain('Junior')->not->toContain('Timeline site')->not->toContain('25,000');
    expect(ehtlCount(ehtlPath(Livewire::test(History::class)->html()), EHTL_SUBJECT))->toBe(0);

    ehtlActor(false, $ana);
    $forged = Livewire::test(History::class)->set('employeeId', $ben->id)->assertDontSee('Ben Timeline');
    expect(ehtlCount(ehtlPath($forged->html()), EHTL_SUBJECT.'|'.EHTL_START.'|'.EHTL_CHANGE))->toBe(0);
});

it('renders an unknown employee id exactly like one outside the viewer scope', function () {
    ehtlActor();
    $ana = ehtlEmployee();
    $ben = ehtlEmployee('Ben');
    ehtlChange($ben, ['position_level' => 'Senior']);
    ehtlActor(false, $ana);

    $signature = static function (string $employeeId): array {
        $path = ehtlPath(Livewire::test(History::class)->set('employeeId', $employeeId)->html());

        return [
            ehtlCount($path, EHTL_SUBJECT.'|'.EHTL_START.'|'.EHTL_CHANGE),
            ehtlCount($path, '//*[contains(., "No employment changes found.")]') > 0,
        ];
    };

    expect($signature($ben->id))->toBe([0, true])
        ->and($signature((string) Str::ulid()))->toBe($signature($ben->id));
});

it('pins an own-rows viewer to their own timeline without an employee filter and never to a forged one', function () {
    ehtlActor();
    $ana = ehtlEmployee();
    $ben = ehtlEmployee('Ben');
    ehtlChange($ana, ['position_level' => 'Own level']);
    ehtlChange($ben, ['position_level' => 'Foreign level']);
    ehtlActor(false, $ana);

    $own = ehtlPath(Livewire::test(History::class)->assertSee('Own level')->assertDontSee('Foreign level')->html());
    expect(ehtlTexts($own, EHTL_SUBJECT)[0])->toContain('Ana Timeline')
        ->and(ehtlCount($own, EHTL_CHANGE))->toBe(1)
        ->and(ehtlCount($own, EHTL_START))->toBe(1);

    $query = app(EmploymentHistoryRecords::class);
    expect($query->subject(app(Access::class))?->id)->toBe($ana->id)
        ->and($query->subject(app(Access::class), $ben->id))->toBeNull();
});

it('returns header facts without rates, position level or statutory identifiers', function () {
    ehtlActor();
    $ana = ehtlEmployee();
    $subject = app(EmploymentHistoryRecords::class)->subject(app(Access::class), $ana->id);
    assert($subject instanceof EmploymentSubjectView);
    $keys = array_keys($subject->toArray());
    sort($keys);

    expect($keys)->toBe([
        'date_hired', 'employee_code', 'employee_id_no', 'employment_status', 'first_name',
        'id', 'is_active', 'last_name', 'photo_path', 'position', 'suffix',
    ])->and($subject->name)->toBe('Ana Timeline')
        ->and($subject->getAttribute('monthly_rate'))->toBeNull()
        ->and($subject->getAttribute('position_level'))->toBeNull()
        ->and($subject->getAttribute('work_site_id'))->toBeNull();
    // An all-data viewer has no "own" person: a blank id names nobody.
    expect(app(EmploymentHistoryRecords::class)->subject(app(Access::class)))->toBeNull();
});

it('returns no subject or starting record to unlinked, foreign and payroll-only actors when called directly', function () {
    ehtlActor();
    $ana = ehtlEmployee();
    $ben = ehtlEmployee('Ben');
    $query = app(EmploymentHistoryRecords::class);
    expect($query->start(app(Access::class), $ana->id))->toBeInstanceOf(EmploymentHistoryView::class);

    $refused = static fn (User $user): array => [
        $query->subject(Access::forActor($user), $ana->id),
        $query->start(Access::forActor($user), $ana->id),
    ];

    expect($refused(ehtlActor(false)))->toBe([null, null])
        ->and($query->subject(app(Access::class)))->toBeNull()
        ->and($refused(ehtlActor(false, $ben)))->toBe([null, null])
        ->and($refused(ehtlActor(module: ModuleCode::PAYROLL)))->toBe([null, null])
        ->and($refused(ehtlActor(false, $ana, ModuleCode::PAYROLL)))->toBe([null, null])
        ->and($query->subject(app(Access::class)))->toBeNull();
});

/*
|--------------------------------------------------------------------------
| The latest mark and the dates
|--------------------------------------------------------------------------
*/

it('marks only the newest stored row as the latest record under any filter or page', function () {
    ehtlActor();
    $this->travelTo(CarbonImmutable::parse('2026-10-01 09:00:00', ManilaClock::TZ));
    $ana = ehtlEmployee();
    for ($i = 1; $i <= 21; $i++) {
        $this->travel(1)->minutes();
        ehtlChange($ana, $i === 1 ? ['position_level' => 'Senior'] : ['monthly_rate' => (string) (25000 + $i)]);
    }
    $newest = EmployeeEmploymentHistory::query()->where('employee_id', $ana->id)->orderByDesc('created_at')->orderByDesc('id')->firstOrFail();

    $first = Livewire::test(History::class)->set('employeeId', $ana->id);
    $page = ehtlPath($first->html());
    $latestKeys = ehtlTexts($page, EHTL_LATEST.'/@*[name()="wire:key"]');
    expect($latestKeys)->toBe(['employment-history-'.$newest->id])
        ->and(ehtlCount($page, EHTL_CHANGE))->toBe(20)
        // More changes follow on the next page, so the starting record is not here yet.
        ->and(ehtlCount($page, EHTL_START))->toBe(0);

    $last = ehtlPath($first->call('gotoPage', 2)->html());
    expect(ehtlCount($last, EHTL_LATEST))->toBe(0)
        ->and(ehtlCount($last, EHTL_CHANGE))->toBe(1)
        ->and(ehtlCount($last, EHTL_START))->toBe(1);

    // The newest row changed the rate; filtered to the level, the one match is the OLDEST change.
    $filtered = ehtlPath(Livewire::test(History::class)->set('employeeId', $ana->id)->set('field', 'position_level')->html());
    expect(ehtlCount($filtered, EHTL_CHANGE))->toBe(1)
        ->and(ehtlCount($filtered, EHTL_LATEST))->toBe(0);

    $query = app(EmploymentHistoryRecords::class);
    $flags = $query->page(app(Access::class), $ana->id, perPage: 100)->getCollection()->map(static fn (EmploymentHistoryView $row): bool => $row->is_latest)->all();
    expect(array_keys($flags, true, true))->toBe([0])
        ->and($query->start(app(Access::class), $ana->id)?->is_latest)->toBeFalse();
});

it('dates every entry by when it was saved and never by a stored effective date', function () {
    ehtlActor();
    // Both instants fall on the PREVIOUS day in UTC, so a date read off the stored value is caught.
    $this->travelTo(CarbonImmutable::parse('2026-10-02 02:15:00', ManilaClock::TZ));
    $ana = ehtlEmployee(hired: false);
    ehtlBaseline($ana, '2019-05-05');
    $this->travelTo(CarbonImmutable::parse('2026-10-03 00:30:00', ManilaClock::TZ));
    ehtlChange($ana, ['position_level' => 'Senior']);
    EmployeeEmploymentHistory::query()->where('event_type', EmploymentHistoryRecorder::UPDATED)->update(['effective_date' => '2099-01-01']);

    $list = ehtlTexts(ehtlPath(Livewire::test(History::class)->set('employeeId', $ana->id)->html()), '//section[@aria-labelledby="history-list-heading"]')[0];
    expect($list)->toContain('03 Oct 2026, 12:30 AM', 'Tracking began 02 Oct 2026')
        ->not->toContain('05 May 2019')->not->toContain('2099')->not->toContain('01 Oct 2026')->not->toContain('effective');
});

/*
|--------------------------------------------------------------------------
| The starting record
|--------------------------------------------------------------------------
*/

it('closes an unfiltered timeline with the starting record and hides it under a field or date filter', function () {
    ehtlActor();
    $this->travelTo(CarbonImmutable::parse('2026-10-02 10:00:00', ManilaClock::TZ));
    $ana = ehtlEmployee();
    $this->travel(1)->hours();
    ehtlChange($ana, ['position_level' => 'Senior']);

    $open = Livewire::test(History::class)->set('employeeId', $ana->id);
    $page = ehtlPath($open->html());
    // Newest first: the change, then the record it started from.
    expect(ehtlTexts($page, '//*[@data-entry]/@data-entry'))->toBe(['change', 'start']);

    foreach ([['field', 'position_level'], ['from', '2026-10-02'], ['to', '2026-10-02']] as [$filter, $value]) {
        $narrowed = Livewire::test(History::class)->set('employeeId', $ana->id)->set($filter, $value)
            ->assertSee('The starting record is not shown while filters are on.');
        expect(ehtlCount(ehtlPath($narrowed->html()), EHTL_START))->toBe(0);
    }

    $none = Livewire::test(History::class)->set('employeeId', $ana->id)->set('from', '2020-01-01')->set('to', '2020-01-31')
        ->assertSee('No updates match these filters.');
    expect(ehtlCount(ehtlPath($none->html()), EHTL_START.'|'.EHTL_CHANGE))->toBe(0);
});

it('keeps the starting record out of the update count and the report rows', function () {
    ehtlActor();
    $ana = ehtlEmployee();
    ehtlChange($ana, ['position_level' => 'Senior']);
    $query = app(EmploymentHistoryRecords::class);

    expect($query->start(app(Access::class), $ana->id))->not->toBeNull()
        ->and($query->page(app(Access::class), $ana->id)->total())->toBe(1)
        ->and(array_map(static fn (EmploymentHistoryView $row): string => $row->event_type, iterator_to_array($query->rows(app(Access::class), $ana->id), false)))
        ->toBe([EmploymentHistoryRecorder::UPDATED]);
    $page = Livewire::test(History::class)->set('employeeId', $ana->id)->assertSeeHtml('employment-start-');
    expect(ehtlTexts(ehtlPath($page->html()), '//*[@role="status"]'))->toBe(['1 update recorded.']);
});

it('lists a starting record as facts and never as changes', function () {
    ehtlActor();
    $ana = ehtlEmployee();
    ehtlChange($ana, ['position_level' => 'Senior', 'monthly_rate' => '28000']);
    $page = ehtlPath(Livewire::test(History::class)->set('employeeId', $ana->id)->html());

    expect(ehtlTexts($page, EHTL_CHANGE.'//*[@data-field]/@data-field'))->toBe(['position_level', 'monthly_rate'])
        ->and(ehtlCount($page, EHTL_START.'//*[@data-field]'))->toBe(0)
        ->and(ehtlTexts($page, EHTL_START.'//*[@data-fact]/@data-fact'))->toBe(EmploymentHistoryRecorder::FIELDS)
        // What the record held at the start is told there, in the stored spelling.
        ->and(ehtlTexts($page, EHTL_START)[0])->toContain('Junior', 'Timeline site', '₱25,000.00', '₱1,000.00', '01 Jan 2024')
        ->and(ehtlTexts($page, EHTL_CHANGE)[0])->not->toContain('Timeline site')->not->toContain('1,000.00');
});

it('labels a backfilled baseline as a starting record and never as a hire', function () {
    ehtlActor();
    $ben = ehtlEmployee('Ben', hired: false);
    ehtlBaseline($ben, '2024-01-01');
    $baseline = Livewire::test(History::class)->set('employeeId', $ben->id)
        ->assertSee('No updates recorded since the starting record.');
    $page = ehtlPath($baseline->html());

    expect(ehtlTexts($page, EHTL_START.'/@data-event'))->toBe([EmploymentHistoryRecorder::BASELINE])
        ->and(ehtlTexts($page, EHTL_START)[0])->toContain('Tracking began', 'Starting Record', 'Details on file when tracking began', 'which may differ from the details at hire')
        ->not->toContain('Employee record created')->not->toContain('Added by')
        // Nothing has changed since, so it is the latest record and starts open.
        ->and(ehtlCount($page, EHTL_START.'[@data-latest]'))->toBe(1)
        ->and(ehtlCount($page, EHTL_START.'/details[@open]'))->toBe(1);

    $ana = ehtlEmployee();
    $hired = ehtlPath(Livewire::test(History::class)->set('employeeId', $ana->id)->html());
    expect(ehtlTexts($hired, EHTL_START.'/@data-event'))->toBe([EmploymentHistoryRecorder::HIRED])
        ->and(ehtlTexts($hired, EHTL_START)[0])->toContain('Record Created', 'Employee record created', 'Added by')
        ->not->toContain('Starting Record')->not->toContain('Tracking began');
});

it('returns no starting record when the oldest stored row is an update', function () {
    ehtlActor();
    $ana = ehtlEmployee(hired: false);
    ehtlChange($ana, ['position_level' => 'Senior']);

    expect(app(EmploymentHistoryRecords::class)->start(app(Access::class), $ana->id))->toBeNull()
        ->and(app(EmploymentHistoryRecords::class)->start(app(Access::class), ''))->toBeNull();
    $page = ehtlPath(Livewire::test(History::class)->set('employeeId', $ana->id)->html());
    expect(ehtlCount($page, EHTL_START))->toBe(0)
        ->and(ehtlCount($page, EHTL_CHANGE.'[@data-latest]'))->toBe(1);
});

/*
|--------------------------------------------------------------------------
| The list
|--------------------------------------------------------------------------
*/

it('says when a field filter hides other changed details of the same save', function () {
    ehtlActor();
    $ana = ehtlEmployee();
    ehtlChange($ana, ['position_level' => 'Senior', 'monthly_rate' => '28000', 'daily_rate' => '1100']);

    Livewire::test(History::class)->set('employeeId', $ana->id)->set('field', 'position_level')
        ->assertSee('2 other details changed in this update are hidden by the Changed field filter.');
    Livewire::test(History::class)->set('employeeId', $ana->id)->set('field', 'monthly_rate')->set('field', '')
        ->assertDontSee('hidden by the Changed field filter');
    $rows = app(EmploymentHistoryRecords::class)->page(app(Access::class), $ana->id, field: 'daily_rate')->getCollection();
    expect($rows->first()?->changed_count)->toBe(3)
        ->and($rows->first()?->changes)->toHaveCount(1);
});

it('keeps the all-employees list free of headers, latest marks and starting records', function () {
    ehtlActor();
    $ana = ehtlEmployee();
    $ben = ehtlEmployee('Ben');
    ehtlChange($ana, ['position_level' => 'Senior']);
    ehtlChange($ben, ['monthly_rate' => '26000']);
    $all = Livewire::test(History::class)
        ->assertSeeHtml(route('employees.history', ['employee' => $ana->id]))
        ->assertSeeHtml(route('employees.show', $ben->id));
    $page = ehtlPath($all->html());

    expect(ehtlCount($page, EHTL_CHANGE))->toBe(2)
        ->and(ehtlCount($page, EHTL_SUBJECT.'|'.EHTL_START.'|'.EHTL_LATEST))->toBe(0)
        ->and(ehtlCount($page, '//details[@open]'))->toBe(0)
        ->and(implode(' ', ehtlTexts($page, EHTL_CHANGE.'//summary')))->toContain('Ana Timeline', 'Ben Timeline', 'Position Level', 'Monthly Rate');
});

it('keeps folded comparisons in the server HTML with only the first single-employee entry open', function () {
    ehtlActor();
    $this->travelTo(CarbonImmutable::parse('2026-10-02 10:00:00', ManilaClock::TZ));
    $ana = ehtlEmployee();
    $this->travel(1)->hours();
    ehtlChange($ana, ['position_level' => 'Senior']);
    $this->travel(1)->hours();
    ehtlChange($ana, ['monthly_rate' => '28000', 'daily_rate' => '1100']);
    $page = ehtlPath(Livewire::test(History::class)->set('employeeId', $ana->id)->html());

    expect(ehtlCount($page, '//*[@data-entry]/details'))->toBe(3)
        // A re-render must not put the server's `open` back over the reader's own.
        ->and(ehtlCount($page, '//*[@data-entry]/details[@*[name()="wire:ignore.self"]]'))->toBe(3)
        ->and(ehtlTexts($page, '//*[@data-entry][details[@open]]/@data-entry'))->toBe(['change'])
        ->and(ehtlCount($page, '(//*[@data-entry])[1]/details[@open]'))->toBe(1)
        ->and(ehtlTexts($page, '//*[@data-field]/@data-field'))->toBe(['monthly_rate', 'daily_rate', 'position_level'])
        ->and(ehtlTexts($page, '//*[@data-field="position_level"]/*[@data-before]'))->toBe(['Previous value: Junior'])
        ->and(ehtlTexts($page, '//*[@data-field="position_level"]/*[@data-after]'))->toBe(['New value: Senior']);
});

/*
|--------------------------------------------------------------------------
| What the page says about what it shows
|--------------------------------------------------------------------------
*/

it('mentions a withheld starting record only for an employee who has one', function () {
    ehtlActor();
    $ana = ehtlEmployee();
    $ben = ehtlEmployee('Ben', hired: false);
    ehtlChange($ana, ['position_level' => 'Senior']);
    ehtlChange($ben, ['position_level' => 'Senior']);
    $status = static fn (EmployeeProfile $employee): array => ehtlTexts(ehtlPath(
        Livewire::test(History::class)->set('employeeId', $employee->id)->set('field', 'position_level')->html()
    ), '//*[@role="status"]');

    // Ben's oldest stored row is an update: there is no starting record to speak of.
    expect($status($ana))->toBe(['1 update matches the filters. The starting record is not shown while filters are on.'])
        ->and($status($ben))->toBe(['1 update matches the filters.']);
    Livewire::test(History::class)->set('employeeId', $ana->id)->set('field', 'monthly_rate')
        ->assertSee('The starting record is not shown while filters are on. Remove the Changed field and dates to see the whole timeline.');
    Livewire::test(History::class)->set('employeeId', $ben->id)->set('field', 'monthly_rate')
        ->assertSee('Remove the Changed field and dates to see the whole timeline.')
        ->assertDontSee('is not shown while filters are on');
});

it('announces every outcome through one status line that is always on the page', function () {
    ehtlActor();
    $ana = ehtlEmployee();
    ehtlChange($ana, ['position_level' => 'Senior']);
    /**
     * @param  array<string, string>  $filters
     * @return list<string>
     */
    $status = static function (array $filters): array {
        $page = Livewire::test(History::class);
        foreach ($filters as $name => $value) {
            $page->set($name, $value);
        }

        return ehtlTexts(ehtlPath($page->html()), '//*[@role="status"]');
    };

    // Exactly one line each time: results, nothing found, and a date that is not a date.
    expect($status([]))->toBe(['1 update recorded.'])
        ->and($status(['employeeId' => $ana->id]))->toBe(['1 update recorded.'])
        ->and($status(['field' => 'monthly_rate']))->toBe(['No employment changes found.'])
        ->and($status(['employeeId' => $ana->id, 'from' => '2020-01-01', 'to' => '2020-01-31']))->toBe(['No updates match these filters.'])
        ->and($status(['from' => '2026-02-30']))->toBe(['Check the filters above.']);
});

it('tells a single employee with no stored rows that nothing is recorded yet', function () {
    ehtlActor();
    $ana = ehtlEmployee(hired: false);
    $page = ehtlPath(Livewire::test(History::class)->set('employeeId', $ana->id)->html());

    expect(ehtlCount($page, EHTL_SUBJECT))->toBe(1)
        ->and(ehtlCount($page, '//*[@data-entry]'))->toBe(0)
        ->and(ehtlTexts($page, '//*[@role="status"]'))->toBe(['No employment history is recorded for this employee yet.']);
});

it('shows a starting worksite whose name was not stored as not recorded and never as the current site name', function () {
    ehtlActor();
    $ben = ehtlEmployee('Ben', hired: false);
    ehtlBaseline($ben, '2024-01-01', siteName: null);

    $start = app(EmploymentHistoryRecords::class)->start(app(Access::class), $ben->id);
    assert($start instanceof EmploymentHistoryView);
    expect(collect($start->facts)->firstWhere('field', 'work_site_id'))->toMatchArray(['value' => null, 'known' => false]);
    $page = ehtlPath(Livewire::test(History::class)->set('employeeId', $ben->id)->html());
    expect(ehtlTexts($page, EHTL_START.'//*[@data-fact="work_site_id"]'))->toBe(['Worksite Not recorded'])
        ->and(ehtlTexts($page, EHTL_START)[0])->not->toContain('Timeline site');
});

it('explains a value that was never captured only on the entry that shows one', function () {
    ehtlActor();
    $this->travelTo(CarbonImmutable::parse('2026-10-02 10:00:00', ManilaClock::TZ));
    $ana = ehtlEmployee(hired: false);
    ehtlChange($ana, ['position_level' => 'Senior']);
    $this->travel(1)->hours();
    // A row that kept no previous values, and not continuous with the row before it:
    // the daily rate moved too although only the monthly rate is declared.
    $legacy = new EmployeeEmploymentHistory;
    $legacy->forceFill([
        'employee_id' => $ana->id, 'event_type' => EmploymentHistoryRecorder::UPDATED, 'effective_date' => '2026-10-02',
        'changed_fields' => 'monthly_rate', 'position' => 'Surveyor', 'position_level' => 'Senior',
        'employment_status' => 'Regular', 'work_site_id' => $ana->work_site_id, 'work_site_name' => 'Timeline site',
        'date_hired' => '2024-01-01', 'is_active' => true, 'monthly_rate' => 30000, 'daily_rate' => 2000,
    ])->save();
    $page = ehtlPath(Livewire::test(History::class)->set('employeeId', $ana->id)->html());
    $entries = ehtlTexts($page, EHTL_CHANGE);

    expect(ehtlTexts($page, '//*[@data-field="monthly_rate"]/*[@data-before]'))->toBe(['Previous value: Not recorded'])
        ->and($entries[0])->toContain('means the value was not captured when this change was saved')
        ->and($entries[1])->toContain('Previous value: Junior')->not->toContain('was not captured');
});

it('marks an inactive employee in the header and says nothing for an active one', function () {
    ehtlActor();
    $ana = ehtlEmployee();
    $ben = ehtlEmployee('Ben');
    EmployeeProfile::query()->whereKey($ben->id)->update(['is_active' => false]);
    $header = static fn (EmployeeProfile $employee): string => ehtlTexts(ehtlPath(
        Livewire::test(History::class)->set('employeeId', $employee->id)->html()
    ), EHTL_SUBJECT)[0];

    expect($header($ben))->toContain('Ben Timeline', 'Inactive')
        ->and($header($ana))->not->toContain('Inactive');
});

it('keys each fold by whether the server opens it so a change of view starts it afresh', function () {
    ehtlActor();
    $this->travelTo(CarbonImmutable::parse('2026-10-02 10:00:00', ManilaClock::TZ));
    $ana = ehtlEmployee();
    $this->travel(1)->hours();
    ehtlChange($ana, ['position_level' => 'Senior']);
    $hire = EmployeeEmploymentHistory::query()->where('event_type', EmploymentHistoryRecorder::HIRED)->sole();
    $change = EmployeeEmploymentHistory::query()->where('event_type', EmploymentHistoryRecorder::UPDATED)->sole();
    $keys = static fn (string $html): array => ehtlTexts(ehtlPath($html), '//*[@data-entry]/details/@*[name()="wire:key"]');

    // The SAME change is open as the first of a timeline and folded in the list: its
    // fold's key differs, so Livewire replaces it and does not keep the other view's state.
    expect($keys(Livewire::test(History::class)->set('employeeId', $ana->id)->html()))
        ->toBe(['employment-fold-'.$change->id.'-open', 'employment-start-fold-'.$hire->id.'-shut'])
        ->and($keys(Livewire::test(History::class)->html()))->toBe(['employment-fold-'.$change->id.'-shut']);
});
