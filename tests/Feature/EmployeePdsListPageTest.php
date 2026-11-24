<?php

declare(strict_types=1);

use App\Domain\Enums\ModuleCode;
use App\Livewire\Employees\PdsList;
use App\Livewire\Employees\Show;
use App\Models\EmployeeEducation;
use App\Models\EmployeeProfile;
use App\Models\Role;
use App\Models\RoleModuleGrant;
use App\Models\User;
use App\Models\WorkSite;
use App\Modules\Workforce\Application\Commands\SavePdsRow;
use App\Modules\Workforce\Application\PdsLists;
use App\Support\Access;
use Illuminate\Support\Str;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

/**
 * The record's five Personal Data Sheet list cards (`employees.pds-list`)
 * are drawn with the title, subtitle, Add button, columns, row menus,
 * empty-level rows and footer pinned below. Management changes records
 * through SavePdsRow / DeletePdsRow, while My Profile edits submit
 * proposals. A foreign Payroll viewer gets no mutation controls. The stored College and
 * Graduate lines show as their level's row until a school of that level
 * exists, and are never written.
 */

/** @param list<string> $modules */
function pdslpageRole(array $modules, bool $allData): Role
{
    $role = new Role;
    $role->forceFill(['name' => 'List page '.implode('-', $modules).' '.Str::random(6), 'all_data' => $allData, 'is_system' => false])->save();
    foreach ($modules as $module) {
        RoleModuleGrant::query()->create(['role_id' => $role->id, 'module' => $module]);
    }

    return $role;
}

/** Signs in a fresh account of `$role`, linked to `$employee` when given. */
function pdslpageActing(Role $role, ?EmployeeProfile $employee = null): User
{
    $user = User::factory()->create();
    $user->forceFill(['role_id' => $role->id, 'employee_id' => $employee?->id])->save();

    test()->actingAs($user->refresh());
    app()->forgetInstance(Access::class);

    return $user;
}

function pdslpageHr(): User
{
    return pdslpageActing(pdslpageRole([ModuleCode::EMPLOYEES], true));
}

/** @param array<string, mixed> $legacy columns set by hand, as an older release stored them */
function pdslpageEmployee(array $legacy = []): EmployeeProfile
{
    $site = WorkSite::query()->create([
        'name' => 'Site '.Str::random(6), 'latitude' => 14.5995, 'longitude' => 120.9842, 'radius_meters' => 100, 'geofence_exempt' => true,
    ]);

    $employee = EmployeeProfile::query()->create([
        'employee_code' => 'COP-'.Str::upper(Str::random(6)),
        'first_name' => 'Rosa', 'last_name' => 'Villanueva', 'position' => 'Accountant II', 'position_level' => 'Senior',
        'employment_status' => 'Regular', 'date_hired' => '2024-01-03', 'monthly_rate' => 25000, 'work_site_id' => $site->id,
    ]);
    $employee->forceFill($legacy)->save();

    return $employee->refresh();
}

/** @return array<string, string> a valid dialog draft for one list */
function pdslpageRow(string $list): array
{
    return match ($list) {
        'education' => ['level' => 'COLLEGE', 'school' => 'Colegio de la Ciudad de Tayabas', 'course' => 'BS Accountancy', 'attended_from' => '2008', 'attended_to' => '2012', 'units_earned' => '', 'year_graduated' => '2012', 'honors' => 'Cum laude'],
        'eligibility' => ['name' => 'Career Service Professional', 'rating' => '85.40', 'exam_date' => '2013-08-04', 'exam_place' => 'Lucena City', 'license_no' => '', 'license_valid_until' => ''],
        'experience' => ['position_title' => 'Junior Accountant', 'company' => 'ABC Trading Corporation', 'date_from' => '2013-01-07', 'date_to' => '2016-05-31', 'monthly_salary' => '18000.50', 'pay_grade' => '', 'appointment_status' => 'Contractual', 'government_service' => 'N'],
        'training' => ['title' => 'Basic Tax Compliance for Payroll Officers', 'date_from' => '2023-03-06', 'date_to' => '2023-03-08', 'hours' => '24', 'ld_type' => 'Technical', 'sponsor' => 'Bureau of Internal Revenue'],
        'voluntary' => ['organization' => 'Philippine Red Cross, Tayabas Chapter', 'organization_address' => 'Tayabas City, Quezon', 'date_from' => '2015-06-01', 'date_to' => '', 'hours' => '120', 'position' => 'Volunteer'],
        default => throw new LogicException("No sample row for [{$list}]."),
    };
}

/**
 * Stores one row through the command as an HR account that is not the one
 * signed in, returning the row's id.
 *
 * @param  array<string, string>  $changes
 */
function pdslpageAdd(EmployeeProfile $employee, string $list, array $changes = []): string
{
    $hr = User::factory()->create();
    $hr->forceFill(['role_id' => pdslpageRole([ModuleCode::EMPLOYEES], true)->id])->save();

    app(SavePdsRow::class)->handle(Access::forActor($hr->refresh()), $employee->id, $list, null, $changes + pdslpageRow($list));

    $model = PdsLists::spec($list)['model'];

    return (string) $model::query()->where('employee_id', $employee->id)->latest('created_at')->latest('id')->value('id');
}

function pdslpageCount(string $list, EmployeeProfile $employee): int
{
    $model = PdsLists::spec($list)['model'];

    return $model::query()->where('employee_id', $employee->id)->count();
}

function pdslpageCard(EmployeeProfile $employee, string $list): mixed
{
    return Livewire::test(PdsList::class, ['employeeId' => $employee->id, 'list' => $list]);
}

it('draws each list card with its title, subtitle, Add button and columns pinned below', function (string $list, string $title, string $subtitle, string $add, array $headers, string $actions, string $date) {
    $employee = pdslpageEmployee();
    pdslpageAdd($employee, $list);
    pdslpageHr();

    $card = pdslpageCard($employee, $list);
    $html = $card->html();

    $card->assertSeeHtml('<h2 class="text-base font-bold">'.$title.'</h2>')
        ->assertSeeHtml('<p class="text-sm text-muted-foreground">'.$subtitle.'</p>')
        ->assertSeeHtml('<span>'.$add.'</span>')
        ->assertSeeHtmlInOrder($headers)
        ->assertSeeHtml('aria-label="Actions for '.$actions.'"')
        ->assertSee($date)
        ->assertDontSee('Export CSV')
        ->assertDontSeeHtml('csv-import');

    // One <section> card; exactly the listed columns, no header beyond
    // them.
    expect($html)->toMatch('/^<section [^>]*class="hris-card overflow-hidden">/')
        ->and(preg_match('#<table class="hris-table">\s*<thead>(.*?)</thead>#s', $html, $head))->toBe(1)
        ->and(preg_match_all('/<th[ >]/', $head[1] ?? ''))->toBe(count($headers))
        ->and($html)->not->toMatch('/\d{4}-\d{2}-\d{2}( 00:00:00)?</');
})->with([
    'education' => ['education', 'Educational Background', 'Schools attended by Rosa Villanueva, from elementary to graduate studies.', 'Add School',
        ['<th>Level</th>', '<th>Name of School</th>', '<th>Degree / Course</th>', '<th>From</th>', '<th>To</th>', '<th>Units Earned</th>', '<th>Year Graduated</th>', '<th>Honors</th>', '<th></th>'],
        'Colegio de la Ciudad de Tayabas', '2008'],
    'eligibility' => ['eligibility', 'Civil Service Eligibility', 'Career service, board and bar eligibilities and licences held by Rosa Villanueva.', 'Add Eligibility',
        ['<th>Eligibility</th>', '<th class="text-right">Rating</th>', '<th>Date of Exam</th>', '<th>Place of Exam</th>', '<th>License No.</th>', '<th>Valid Until</th>', '<th class="w-10"><span class="sr-only">Actions</span></th>'],
        'Career Service Professional', '04 Aug 2013'],
    'experience' => ['experience', 'Work Experience', 'Most recent first. The first row comes from Employee Details.', 'Add Previous Job',
        ['<th>Position Title</th>', '<th>Company / Agency</th>', '<th>From</th>', '<th>To</th>', '<th class="text-right">Monthly Salary</th>', '<th>Pay Grade</th>', '<th>Appointment Status</th>', '<th>Gov&#039;t Service</th>', '<th class="w-10"><span class="sr-only">Actions</span></th>'],
        'Junior Accountant, ABC Trading Corporation', '07 Jan 2013'],
    'training' => ['training', 'Learning and Development', 'Learning and development interventions and training programs attended, latest first.', 'Add Training',
        ['<th class="min-w-[14rem]">Title</th>', '<th>From</th>', '<th>To</th>', '<th class="text-right">Hours</th>', '<th>Type of L&amp;D</th>', '<th>Conducted / Sponsored By</th>', '<th></th>'],
        'Basic Tax Compliance for Payroll Officers', '06 Mar 2023'],
    'voluntary' => ['voluntary', 'Voluntary Work', 'Voluntary work or involvement in civic, non-government, people and voluntary organizations.', 'Add Voluntary Work',
        ['<th>Organization and Address</th>', '<th>From</th>', '<th>To</th>', '<th class="text-right">Hours</th>', '<th>Position / Nature of Work</th>', '<th></th>'],
        'Philippine Red Cross, Tayabas Chapter', '01 Jun 2015'],
]);

it('keeps every hidden dialog field bindable before opening and after clearing its draft', function (string $list) {
    $employee = pdslpageEmployee();
    pdslpageHr();
    $blank = PdsLists::blankRow($list);
    $draft = pdslpageRow($list);

    // Alpine date and money controls bind when the hidden dialog first renders.
    // A missing property leaves the visible input disconnected from Livewire.
    $card = pdslpageCard($employee, $list)
        ->assertSet('dialogOpen', false)
        ->assertSet('form', $blank)
        ->call('openAdd')
        ->set('form', $draft)
        ->set('dialogOpen', false)
        ->assertSet('form', $blank);

    expect(pdslpageCount($list, $employee))->toBe(0);

    $card->call('openAdd')
        ->set('form', $draft)
        ->call('save', true)
        ->assertSet('formErrors', [])
        ->assertSet('dialogOpen', true)
        ->assertSet('form', PdsLists::blankRow($list, $draft['level'] ?? null))
        ->set('form', $draft)
        ->call('save')
        ->assertSet('formErrors', [])
        ->assertSet('dialogOpen', false)
        ->assertSet('form', $blank);

    expect(pdslpageCount($list, $employee))->toBe(2);
})->with(PdsLists::KEYS);

it('adds a school through the dialog and keeps the level for Save & add another', function () {
    $employee = pdslpageEmployee();
    pdslpageHr();

    $card = pdslpageCard($employee, 'education')->call('openAdd', 'GRADUATE');

    // The dialog's own heading is the large one, as on the holiday dialog.
    expect(preg_match('/<div [^>]*id="pds-dialog-heading-education"[^>]*>/', $card->html(), $heading))->toBe(1)
        ->and($heading[0] ?? '')->toContain(' text-base ');

    $card->assertSet('dialogOpen', true)
        ->assertSet('editingId', null)
        ->assertSet('form.level', 'GRADUATE')
        ->assertSee('A level with no school prints as N/A on the PDS.')
        ->assertSeeHtml('aria-label="Name of School"')
        ->assertSee('Write in full, as on the diploma or transcript.')
        ->assertSeeHtml('placeholder="yyyy"')
        ->assertSee('Save & Add Another')
        ->set('form.school', 'University of the Philippines Los Baños')
        ->set('form.course', 'Master in Business Administration')
        ->set('form.attended_from', '2021')
        ->set('form.units_earned', '24 units')
        ->call('save', true)
        ->assertSet('dialogOpen', true)
        ->assertSet('editingId', null)
        ->assertSet('form.level', 'GRADUATE')
        ->assertSet('form.school', '')
        ->set('form.school', 'Ateneo Graduate School of Business')
        ->call('save')
        ->assertSet('dialogOpen', false)
        ->assertSee('University of the Philippines Los Baños')
        ->assertSee('Ateneo Graduate School of Business');

    $schools = EmployeeEducation::query()->where('employee_id', $employee->id)->orderBy('school')->get();

    expect($schools->pluck('school')->all())->toBe(['Ateneo Graduate School of Business', 'University of the Philippines Los Baños'])
        ->and($schools->pluck('level')->unique()->all())->toBe(['GRADUATE'])
        ->and($employee->refresh()->graduate_school)->toBeNull();
});

it('edits a row from its menu and from a click on an education row', function () {
    $employee = pdslpageEmployee();
    $school = pdslpageAdd($employee, 'education');
    $eligibility = pdslpageAdd($employee, 'eligibility');
    $job = pdslpageAdd($employee, 'experience');
    pdslpageHr();

    pdslpageCard($employee, 'education')
        ->assertSeeHtml('aria-label="Edit Colegio de la Ciudad de Tayabas"')
        ->assertSeeHtml('x-on:click="if (! $event.target.closest(\'button, [data-flux-menu]\')) $wire.startEdit(\''.$school.'\')"')
        ->assertSeeHtml('x-on:keydown.enter.self="$wire.startEdit(\''.$school.'\')"')
        ->assertSeeHtml('wire:click="startEdit(\''.$school.'\')"')
        ->call('startEdit', $school)
        ->assertSet('editingId', $school)
        ->assertSet('dialogOpen', true)
        ->assertSet('form.school', 'Colegio de la Ciudad de Tayabas')
        ->assertSet('form.attended_from', '2008')
        ->assertSet('form.year_graduated', '2012')
        ->assertSet('form.units_earned', '')
        ->assertSee('Edit School')
        ->set('form.school', 'Colegio de Tayabas')
        ->call('save')
        ->assertSet('dialogOpen', false)
        ->assertSet('editingId', null);

    expect(EmployeeEducation::query()->whereKey($school)->value('school'))->toBe('Colegio de Tayabas')
        ->and(pdslpageCount('education', $employee))->toBe(1);

    // Only Education rows are clickable; the other lists edit from the menu.
    pdslpageCard($employee, 'eligibility')
        ->assertDontSeeHtml('x-on:keydown.enter.self="$wire.startEdit(')
        ->call('startEdit', $eligibility)
        ->assertSet('form.exam_date', '2013-08-04')
        ->assertSet('form.rating', '85.40')
        ->assertSet('form.license_valid_until', '')
        ->assertSee('Edit Eligibility')
        ->set('form.license_no', '0012345')
        ->call('save');

    pdslpageCard($employee, 'experience')
        ->call('startEdit', $job)
        ->assertSet('form.monthly_salary', '18000.5')
        ->assertSet('form.government_service', 'N')
        ->assertSet('form.date_to', '2016-05-31')
        ->assertSee('Edit Previous Job');

    expect(pdslpageCount('eligibility', $employee))->toBe(1);
});

it('deletes a row only after the confirm', function () {
    $employee = pdslpageEmployee();
    $id = pdslpageAdd($employee, 'training');
    pdslpageHr();

    $card = pdslpageCard($employee, 'training')
        ->assertSeeHtml('wire:click="confirmDelete(\''.$id.'\')"')
        ->call('confirmDelete', $id)
        ->assertSet('confirmOpen', true)
        ->assertSet('deletingId', $id)
        ->assertSee('Delete Basic Tax Compliance for Payroll Officers?')
        ->assertSee("It is removed from Rosa Villanueva's record for good. This cannot be undone.", false);

    expect(pdslpageCount('training', $employee))->toBe(1);

    // Closing the confirm forgets the queued row.
    $card->set('confirmOpen', false)
        ->assertSet('deletingId', null)
        ->call('deleteConfirmed');

    expect(pdslpageCount('training', $employee))->toBe(1);

    $card->call('confirmDelete', $id)
        ->call('deleteConfirmed')
        ->assertSet('confirmOpen', false)
        ->assertSet('deletingId', null)
        ->assertSee('None recorded · prints as N/A');

    expect(pdslpageCount('training', $employee))->toBe(0);
});

it('names the employee by first and last name with the suffix, never the middle name, in the subtitle and the confirm', function () {
    $employee = pdslpageEmployee(['middle_name' => 'Perez', 'suffix' => 'Jr.']);
    $id = pdslpageAdd($employee, 'training');
    pdslpageHr();

    pdslpageCard($employee, 'education')
        ->assertSee('Schools attended by Rosa Villanueva Jr., from elementary to graduate studies.')
        ->assertDontSee('Perez');

    pdslpageCard($employee, 'training')
        ->call('confirmDelete', $id)
        ->assertSee("It is removed from Rosa Villanueva Jr.'s record for good. This cannot be undone.", false)
        ->assertDontSee('Perez');
});

it('shows every empty education level as None recorded · prints as N/A and opens Add school on it', function () {
    $employee = pdslpageEmployee();
    pdslpageHr();

    $card = pdslpageCard($employee, 'education');
    $html = $card->html();

    expect(substr_count($html, '<td colspan="7">None recorded · prints as N/A</td>'))->toBe(5);

    $card->assertSeeHtmlInOrder(['Elementary', 'Secondary', 'Vocational', 'College', 'Graduate'])
        ->assertSeeHtml('aria-label="Add a vocational school"')
        ->assertSeeHtml('aria-label="Add an elementary school"')
        ->assertSeeHtml('aria-label="Actions for Vocational schools"')
        ->assertSeeHtml('x-on:click="if (! $event.target.closest(\'button, [data-flux-menu]\')) $wire.openAdd(\'VOCATIONAL\')"')
        ->assertSeeHtml('wire:click="openAdd(\'VOCATIONAL\')"')
        ->assertSeeHtml('<span class="num">5</span> levels')
        ->call('openAdd', 'VOCATIONAL')
        ->assertSet('form.level', 'VOCATIONAL')
        ->assertSet('dialogOpen', true)
        // An unknown level starts the draft on the first level.
        ->call('openAdd', 'PAYROLL')
        ->assertSet('form.level', 'ELEMENTARY');
});

it('shows the legacy College row as Entered before with only Add school in its menu', function () {
    $employee = pdslpageEmployee(['college' => 'Colegio de la Ciudad de Tayabas', 'college_course' => 'BS Accountancy', 'college_year_graduated' => '2012']);
    pdslpageHr();

    $card = pdslpageCard($employee, 'education')
        ->assertSeeHtmlInOrder(['College', 'Colegio de la Ciudad de Tayabas', 'Entered before', 'BS Accountancy', '2012'])
        ->assertSeeHtml('aria-label="Actions for College schools"')
        ->assertSeeHtml('wire:click="openAdd(\'COLLEGE\')"')
        ->assertDontSeeHtml('startEdit(')
        ->assertDontSeeHtml('confirmDelete(');

    expect(substr_count($card->html(), 'None recorded · prints as N/A'))->toBe(4);

    $card->call('openAdd', 'COLLEGE')
        ->assertSee('Entered before: Colegio de la Ciudad de Tayabas · BS Accountancy · 2012 — shown until a school of this level is added.')
        // Nothing is copied from the legacy line.
        ->assertSet('form.school', '')
        ->set('form.school', 'Southern Luzon State University')
        ->call('save')
        ->assertDontSee('Entered before')
        ->assertSee('Southern Luzon State University');

    $employee->refresh();

    expect($employee->college)->toBe('Colegio de la Ciudad de Tayabas')
        ->and($employee->college_course)->toBe('BS Accountancy')
        ->and($employee->college_year_graduated)->toBe('2012');
});

it('draws the current job first with Edit in Employee Details, which opens Employee Details on Profile', function () {
    config(['copems.company_name' => 'Fixture Company']);
    $employee = pdslpageEmployee();
    pdslpageAdd($employee, 'experience');
    pdslpageHr();

    pdslpageCard($employee, 'experience')
        ->assertSeeHtmlInOrder(['Accountant II', 'Fixture Company', 'From Employee Details', '03 Jan 2024', 'Present', '$25,000.00', 'Senior', 'Regular', 'N', 'Junior Accountant', 'ABC Trading Corporation'])
        ->assertSeeHtml('aria-label="Actions for Accountant II, Fixture Company"')
        ->assertSeeHtml('wire:click="$parent.startEdit(\'employment\')"')
        ->assertSee('Edit in Employee Details')
        ->assertSeeHtml('<span class="num">2</span> jobs');

    Livewire::test(Show::class, ['employee' => $employee->id])
        ->set('tab', 'experience')
        ->call('startEdit', 'employment')
        ->assertSet('tab', 'profile')
        ->assertSet('editingSection', 'employment');
});

it('hides Add, the row menus and the row clicks from a viewer who cannot edit, and refuses their direct calls with no effect', function (string $who) {
    $employee = pdslpageEmployee();
    $school = pdslpageAdd($employee, 'education');
    $training = pdslpageAdd($employee, 'training');

    match ($who) {
        'payroll all-data' => pdslpageActing(pdslpageRole([ModuleCode::PAYROLL], true)),
        'own record' => pdslpageActing(pdslpageRole([ModuleCode::EMPLOYEES], false), $employee),
        default => throw new LogicException("No viewer [{$who}]."),
    };

    pdslpageCard($employee, 'education')
        ->assertSee('Colegio de la Ciudad de Tayabas')
        ->assertSee('None recorded · prints as N/A')
        ->assertDontSeeHtml('wire:click="openAdd"')
        ->assertDontSeeHtml('<span>Add School</span>')
        ->assertDontSeeHtml('x-on:keydown.enter.self="$wire.startEdit(')
        ->assertDontSeeHtml('Actions for')
        ->assertDontSeeHtml('$wire.startEdit')
        ->assertDontSeeHtml('$wire.openAdd');

    pdslpageCard($employee, 'training')->assertSee('Basic Tax Compliance for Payroll Officers')->assertDontSeeHtml('Actions for');

    pdslpageCard($employee, 'education')->call('openAdd', 'COLLEGE')->assertForbidden();
    pdslpageCard($employee, 'education')->call('startEdit', $school)->assertForbidden();
    pdslpageCard($employee, 'education')->set('form', pdslpageRow('education'))->call('save')->assertForbidden();
    pdslpageCard($employee, 'training')->call('confirmDelete', $training)->assertForbidden();
    pdslpageCard($employee, 'training')->call('deleteConfirmed')->assertForbidden();

    expect(pdslpageCount('education', $employee))->toBe(1)
        ->and(pdslpageCount('training', $employee))->toBe(1)
        ->and(EmployeeEducation::query()->whereKey($school)->value('school'))->toBe('Colegio de la Ciudad de Tayabas');
})->with(['payroll all-data', 'own record']);

it('refuses a tampered list name and a locked id change', function () {
    $employee = pdslpageEmployee();
    pdslpageHr();

    pdslpageCard($employee, 'payroll')->assertNotFound();

    foreach (['employeeId' => 'x', 'list' => 'payroll', 'editingId' => (string) Str::ulid(), 'deletingId' => (string) Str::ulid()] as $property => $value) {
        expect(fn () => pdslpageCard($employee, 'training')->set($property, $value))
            ->toThrow(CannotUpdateLockedPropertyException::class);
    }
});

it('names the footer levels while each level has one row, and rows otherwise', function () {
    $employee = pdslpageEmployee(['graduate_school' => 'Ateneo Graduate School of Business']);

    foreach (['ELEMENTARY', 'SECONDARY', 'VOCATIONAL', 'COLLEGE'] as $level) {
        pdslpageAdd($employee, 'education', ['level' => $level, 'school' => ucfirst(strtolower($level)).' School']);
    }
    pdslpageHr();

    // Four schools and the legacy Graduate line: one row per level.
    pdslpageCard($employee, 'education')->assertSeeHtml('<span class="num">5</span> levels');

    pdslpageAdd($employee, 'education', ['level' => 'COLLEGE', 'school' => 'Second College']);

    pdslpageCard($employee, 'education')
        ->assertSeeHtml('<span class="num">6</span> rows')
        ->assertDontSeeHtml('levels');
});

it('shows the field errors under the dialog fields and writes nothing', function () {
    $employee = pdslpageEmployee();
    pdslpageHr();

    pdslpageCard($employee, 'eligibility')
        ->call('openAdd')
        ->set('form.name', '')
        ->set('form.exam_date', '2013-08-04')
        ->set('form.license_valid_until', '2012-01-01')
        ->call('save')
        ->assertSet('dialogOpen', true)
        ->assertSet('formErrors.name', 'Eligibility is required.')
        ->assertSet('formErrors.license_valid_until', 'Valid until cannot be before the date of exam.')
        ->assertSee('Eligibility is required.')
        ->assertSee('Valid until cannot be before the date of exam.')
        ->assertSeeHtml('aria-invalid="true"');

    pdslpageCard($employee, 'training')
        ->call('openAdd')
        ->set('form.title', 'Data Privacy Act Awareness Seminar')
        ->set('form.date_from', '2024-05-02')
        ->set('form.date_to', '2024-05-01')
        ->call('save')
        ->assertSet('formErrors.date_to', 'To cannot be before From.');

    expect(pdslpageCount('eligibility', $employee))->toBe(0)
        ->and(pdslpageCount('training', $employee))->toBe(0);
});

it('pages a list of 25 trainings 20 at a time on its own page name', function () {
    $employee = pdslpageEmployee();
    $rows = [];
    for ($i = 0; $i < 25; $i++) {
        $rows[] = ['id' => (string) Str::ulid(), 'employee_id' => $employee->id, 'created_at' => now()->utc()->format('Y-m-d H:i:s.v')]
            + PdsLists::columns('training', ['title' => 'Seminar '.($i + 1)] + pdslpageRow('training'));
    }
    PdsLists::spec('training')['model']::query()->insert($rows);
    pdslpageHr();

    pdslpageCard($employee, 'training')
        ->assertSeeHtml('Showing <span class="num">1</span>&ndash;<span class="num">20</span> of')
        ->assertSeeHtml('<span class="num">25</span> trainings')
        ->assertSeeHtml('wire:click="nextPage(\'trainingPage\')"')
        ->call('nextPage', 'trainingPage')
        ->assertSeeHtml('Showing <span class="num">21</span>&ndash;<span class="num">25</span> of')
        ->set('perPage', 50)
        ->assertSeeHtml('Showing <span class="num">1</span>&ndash;<span class="num">25</span> of');
});
