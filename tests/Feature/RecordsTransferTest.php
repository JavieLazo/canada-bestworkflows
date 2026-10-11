<?php

declare(strict_types=1);

use App\Domain\Enums\AuditEvent;
use App\Domain\Enums\ModuleCode;
use App\Livewire\Kit\CsvImport;
use App\Livewire\Records\DepartmentsPage;
use App\Livewire\Records\HolidaysPage;
use App\Livewire\Records\IncentiveTypesPage;
use App\Livewire\Records\PositionsPage;
use App\Livewire\Records\ReferenceListPage;
use App\Livewire\Records\ShiftTypesPage;
use App\Livewire\Records\SitesPage;
use App\Models\AuditLog;
use App\Models\Holiday;
use App\Models\PayrollIncentiveType;
use App\Models\Role;
use App\Models\RoleModuleGrant;
use App\Models\ShiftType;
use App\Models\User;
use App\Models\WorkSite;
use App\Modules\Organization\Application\Commands\SaveHoliday;
use App\Modules\Organization\Application\Commands\SaveSite;
use App\Modules\Organization\Application\HolidayDraft;
use App\Modules\Organization\Application\SiteDraft;
use App\Modules\Payroll\Application\Commands\ImportIncentiveTypes;
use App\Modules\Payroll\Application\Commands\SaveIncentiveType;
use App\Modules\Timekeeping\Application\Commands\SaveShiftType;
use App\Modules\Timekeeping\Application\ShiftTypeDraft;
use App\Platform\Transfer\ImportReport;
use App\Platform\Transfer\ImportRunner;
use App\Platform\Transfer\TableTransfers;
use App\Support\Access;
use App\Support\Csv;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Livewire\Livewire;

/**
 * The Records tables beyond the plain lists — sites, holidays, shift types,
 * incentive types — export what their table shows and import back through
 * their own save command: an untouched export changes nothing, a known ID
 * updates, a blank one adds, and any bad row keeps the whole file out.
 * Writers see Import; readers only Export. Of the plain lists, Leave
 * Settings and Payroll Deductions carry the same two buttons, and THE THREE
 * GENERAL RECORDS LISTS — Position, Document, Employment — HAVE NO CSV AT
 * ALL: no button, no export route, no key an import could be mounted under.
 */

/** @param list<string> $modules */
function rxferActor(array $modules, bool $allData): User
{
    $role = new Role;
    $role->forceFill(['name' => 'Records transfer '.Str::random(6), 'all_data' => $allData, 'is_system' => false])->save();
    foreach ($modules as $module) {
        $grant = new RoleModuleGrant;
        $grant->forceFill(['role_id' => $role->getKey(), 'module' => $module])->save();
    }
    $user = User::factory()->create(['role_id' => $role->getKey()]);
    test()->actingAs($user);
    app()->forgetInstance(Access::class);

    return $user;
}

/**
 * @param  list<string>  $headers
 * @param  list<list<string|int|float>>  $rows
 */
function rxferImport(string $table, array $headers, array $rows, bool $commit = true): ImportReport
{
    $import = app(TableTransfers::class)->import($table);
    $runner = app(ImportRunner::class);
    $bytes = Csv::build($headers, $rows);

    return $commit ? $runner->commit(app(Access::class), $import, '', $bytes) : $runner->preview(app(Access::class), $import, '', $bytes);
}

/** @return list<list<string>> the export's rows below its header */
function rxferRows(string $url): array
{
    $csv = test()->get($url)->assertOk()->streamedContent();
    $lines = array_map(static fn (string $line): array => str_getcsv($line, ',', '"', ''), explode("\r\n", substr($csv, strlen(Csv::BOM))));

    return array_slice($lines, 1);
}

/* |---| Sites |---| */

it('exports sites with their id and plain coordinates, and imports them back unchanged', function () {
    rxferActor([ModuleCode::RECORDS], allData: true);
    app(SaveSite::class)->handle(app(Access::class), null, new SiteDraft('Makati HQ', '14.5547', '121.0244', '150', false));
    app(SaveSite::class)->handle(app(Access::class), null, new SiteDraft('Field', '', '', '100', true));
    $site = WorkSite::query()->where('name', 'Makati HQ')->firstOrFail();
    $audits = AuditLog::query()->count();

    $rows = rxferRows('/records/sites/export?geofence=ENFORCED');

    // Punches and Status are export-only: the import reads neither.
    expect($rows)->toBe([[(string) $site->getKey(), 'Makati HQ', '14.5547', '121.0244', '150', 'Enforced', '0', 'Active']]);

    $csv = test()->get('/records/sites/export')->streamedContent();
    $report = app(ImportRunner::class)->commit(app(Access::class), app(TableTransfers::class)->import('records.sites'), '', $csv);
    expect($report->unchanged)->toBe(2)->and(AuditLog::query()->count())->toBe($audits);
});

it('renames a site by its id, adds a blank-id row, and keeps the whole file out for one bad row', function () {
    rxferActor([ModuleCode::RECORDS], allData: true);
    app(SaveSite::class)->handle(app(Access::class), null, new SiteDraft('Makati HQ', '14.5547', '121.0244', '150', false));
    $site = WorkSite::query()->firstOrFail();
    $headers = ['ID', 'Site', 'Latitude', 'Longitude', 'Radius (m)', 'Geofence', 'Punches'];

    $refused = rxferImport('records.sites', $headers, [
        [(string) $site->getKey(), 'Makati Main', '14.5547', '121.0244', '200', 'Enforced', '9'],
        ['', 'Cebu', '', '', '100', 'Enforced', ''],
    ]);
    expect($refused->saved)->toBeFalse()
        ->and($refused->errors)->toBe([['row' => 3, 'message' => 'A site the geofence enforces needs both coordinates.']])
        ->and(WorkSite::query()->count())->toBe(1)
        ->and($site->fresh()?->name)->toBe('Makati HQ');

    $saved = rxferImport('records.sites', $headers, [
        [(string) $site->getKey(), 'Makati Main', '14.5547', '121.0244', '200', 'Enforced', '9'],
        ['', 'Cebu', '10.3157', '123.8854', '1,000', 'Exempt', ''],
    ]);
    expect($saved->saved)->toBeTrue()
        ->and([$saved->created, $saved->updated])->toBe([1, 1])
        ->and($site->fresh()?->name)->toBe('Makati Main')
        ->and($site->fresh()?->radius_meters)->toBe(200)
        ->and(WorkSite::query()->where('name', 'Cebu')->value('geofence_exempt'))->toBeTruthy()
        ->and(AuditLog::query()->where('action', AuditEvent::SITE_UPDATED->value)->count())->toBe(1);
});

/* |---| Holidays |---| */

it('exports the calendar with labels and ISO dates, and imports new and edited holidays', function () {
    rxferActor([ModuleCode::RECORDS], allData: true);
    app(SaveHoliday::class)->handle(app(Access::class), null, new HolidayDraft('Rizal Day', '2026-12-30', 'ANNUAL', 'REGULAR', '200'));
    $code = (string) Holiday::query()->value('code');

    expect(rxferRows('/records/holidays/export'))->toBe([[$code, 'Rizal Day', '2026-12-30', 'Every year', 'Regular Holiday', '200']]);

    $headers = ['ID', 'Name', 'Date', 'Schedule', 'Type', 'Percent'];
    $report = rxferImport('records.holidays', $headers, [
        [$code, 'Rizal Day', '12/30/2026', 'Every year', 'Regular Holiday', '200%'],
        ['', 'Company Anniversary', '2026-10-15', 'One time', 'Special Non-Working Holiday', '130'],
    ]);

    expect([$report->created, $report->updated, $report->unchanged])->toBe([1, 0, 1])
        ->and(Holiday::query()->where('name', 'Company Anniversary')->value('type'))->toBe('SPECIAL_NON_WORKING')
        ->and(AuditLog::query()->where('action', AuditEvent::HOLIDAY_CREATED->value)->count())->toBe(2);
});

it('names a missing type, an unknown id and a repeated holiday, and saves none of the file', function () {
    rxferActor([ModuleCode::RECORDS], allData: true);
    app(SaveHoliday::class)->handle(app(Access::class), null, new HolidayDraft('Rizal Day', '2026-12-30', 'ANNUAL', 'REGULAR', '200'));
    $headers = ['ID', 'Name', 'Date', 'Schedule', 'Type', 'Percent'];

    $report = rxferImport('records.holidays', $headers, [
        ['', 'Labor Day', '2026-05-01', 'Every year', '', '200'],
        ['77', 'Bonifacio Day', '2026-11-30', 'Every year', 'Regular Holiday', '200'],
        ['', 'Rizal Day', '2026-12-30', 'Every year', 'Regular Holiday', '200'],
        ['', 'Valid Day', '2026-06-12', 'Every year', 'Regular Holiday', '200'],
    ]);

    expect($report->errors)->toBe([
        ['row' => 2, 'message' => 'Type is required.'],
        ['row' => 3, 'message' => 'No holiday has ID 77. Leave ID blank to add a new holiday.'],
        ['row' => 4, 'message' => '"Rizal Day" is already on the calendar for 2026-12-30.'],
    ])->and(Holiday::query()->count())->toBe(1);
});

/* |---| Shift types |---| */

it('spells a pattern out a value per column and imports it back unchanged', function () {
    rxferActor([ModuleCode::RECORDS], allData: true);
    app(SaveShiftType::class)->handle(app(Access::class), null, new ShiftTypeDraft('Day shift', 'FIXED', [1, 2, 3, 4, 5], '08:00', '17:00', '15', '8', true, '12:00', '13:00', '60', true));
    app(SaveShiftType::class)->handle(app(Access::class), null, new ShiftTypeDraft('Flexi', 'FLEXIBLE', [1, 2, 3, 4, 5, 6], '08:00', '17:00', '0', '7.5', false, '12:00', '13:00', '0', false));
    $day = ShiftType::query()->where('name', 'Day shift')->firstOrFail();
    $audits = AuditLog::query()->count();

    $rows = rxferRows('/records/shifts/export?sort=name');
    expect($rows[0])->toBe([(string) $day->code, 'Day shift', 'Fixed', 'Mon, Tue, Wed, Thu, Fri', '08:00', '17:00', '', '12:00', '13:00', '60', 'Yes', '15', ''])
        ->and($rows[1][2])->toBe('Flexible')
        ->and($rows[1][6])->toBe('7.5');

    $csv = test()->get('/records/shifts/export')->streamedContent();
    $report = app(ImportRunner::class)->commit(app(Access::class), app(TableTransfers::class)->import('records.shifts'), '', $csv);
    expect($report->unchanged)->toBe(2)->and(AuditLog::query()->count())->toBe($audits);
});

it('adds a pattern from day ranges and 12-hour times, and refuses a list that is not days', function () {
    rxferActor([ModuleCode::RECORDS], allData: true);
    $headers = ['ID', 'Name', 'Mode', 'Work days', 'Start', 'End', 'Required hours', 'Break start', 'Break end', 'Break minutes', 'Clock out for break', 'Grace (min)', 'Effective from'];

    $refused = rxferImport('records.shifts', $headers, [
        ['', 'Night', 'Fixed', 'Mon - Fri', '10:00 PM', '7:00 AM', '', '', '', '0', 'No', '10', ''],
        ['', 'Odd', 'Fixed', 'Funday', '08:00', '17:00', '', '', '', '0', 'No', '10', ''],
    ]);
    expect($refused->errors)->toBe([['row' => 3, 'message' => 'Work days: Funday is not a list of days, like "Mon, Tue, Wed" or "Mon–Fri".']])
        ->and(ShiftType::query()->count())->toBe(0);

    $saved = rxferImport('records.shifts', $headers, [['', 'Night', 'Fixed', 'Mon - Fri', '10:00 PM', '7:00 AM', '', '', '', '0', 'No', '10', '']]);

    // A file from before the column existed is refused whole, by name.
    $old = rxferImport('records.shifts', array_slice($headers, 0, 12), [['', 'Dawn', 'Fixed', 'Mon - Fri', '05:00', '13:00', '', '', '', '0', 'No', '10']]);
    expect($old->errors[0]['message'])->toBe('Missing column: Effective from. Start from the template or an export of this table.');
    $night = ShiftType::query()->where('name', 'Night')->firstOrFail();
    expect($saved->created)->toBe(1)
        ->and($night->work_days)->toBe([1, 2, 3, 4, 5])
        ->and([$night->start_time, $night->end_time])->toBe(['22:00:00', '07:00:00']);
});

/* |---| Incentive types |---| */

it('lets an all-data payroll officer import incentive types by their tax label', function () {
    rxferActor([ModuleCode::PAYROLL], allData: true);
    app(SaveIncentiveType::class)->handle(app(Access::class), null, 'Rice subsidy', null, false);
    $code = (string) PayrollIncentiveType::query()->value('code');

    expect(rxferRows('/records/incentive-types/export'))->toBe([[$code, 'Rice subsidy', 'Tax-exempt', '', 'Typed per payroll', '', 'One-time', '', '0', 'None', '', '', '', 'No']]);

    // The rule's columns left blank keep what the row holds; filled, they set it.
    $report = rxferImport('records.incentive-types', ImportIncentiveTypes::COLUMNS, [
        [$code, 'Rice subsidy', 'tax-exempt', 'Monthly rice', '', '', '', '', '', '', '', '', '', ''],
        ['', 'Performance bonus', 'Taxable', '', 'Fixed amount', '1,500', 'Monthly 1st cut-off', '', '4', 'None', '', '', 'Memo 7', 'No'],
    ]);

    expect([$report->created, $report->updated, $report->errors])->toBe([1, 1, []])
        ->and(PayrollIncentiveType::query()->where('name', 'Performance bonus')->value('is_taxable'))->toBeTruthy()
        ->and(PayrollIncentiveType::query()->where('name', 'Performance bonus')->sole()->only(['amount_type', 'frequency', 'min_service_months', 'approval_reference']))->toEqual(['amount_type' => 'FIXED', 'frequency' => 'FIRST', 'min_service_months' => 4, 'approval_reference' => 'Memo 7'])
        ->and(PayrollIncentiveType::query()->where('name', 'Performance bonus')->sole()->amount)->toBe(1500.0)
        ->and(PayrollIncentiveType::query()->where('code', $code)->value('description'))->toBe('Monthly rice')
        ->and(PayrollIncentiveType::query()->where('code', $code)->value('amount_type'))->toBe('TYPED');
});

/* |---| Who sees the buttons |---| */

it('shows Export to every reader and Import only to the table\'s writers', function (string $component) {
    rxferActor([ModuleCode::RECORDS], allData: false);
    Livewire::test($component)->assertSee('Export CSV')->assertDontSee('Import CSV');

    rxferActor([ModuleCode::RECORDS], allData: true);
    Livewire::test($component)->assertSee('Export CSV')->assertSee('Import CSV');
})->with([
    'sites' => [SitesPage::class],
    'holidays' => [HolidaysPage::class],
    'shift types' => [ShiftTypesPage::class],
    'incentive types' => [IncentiveTypesPage::class],
]);

it('keeps Export for every reader and Import for the writers on the plain lists that carry a CSV', function (string $slug) {
    rxferActor([ModuleCode::RECORDS], allData: false);
    Livewire::test(ReferenceListPage::class, ['slug' => $slug])->assertSee('Export CSV')->assertDontSee('Import CSV');

    rxferActor([ModuleCode::RECORDS], allData: true);
    Livewire::test(ReferenceListPage::class, ['slug' => $slug])
        ->assertSeeHtml('href="'.e(route("records.{$slug}.export", ['sort' => 'name', 'dir' => 'asc'])).'"')
        ->assertSee('Import CSV');

    expect(app(TableTransfers::class)->exports("records.{$slug}"))->toBeTrue()
        ->and(app(TableTransfers::class)->imports("records.{$slug}"))->toBeTrue();
})->with(['leave-types', 'payroll-deductions']);

it('has no CSV on a General Records list: no button, no export route, no transfer key', function (string $slug) {
    rxferActor([ModuleCode::RECORDS], allData: true);

    // The list stands with its Add button, and nothing on the page links to an export.
    Livewire::test(ReferenceListPage::class, ['slug' => $slug])
        ->assertSee('Add '.ReferenceListPage::LISTS[$slug]['noun'])
        ->assertDontSee('Export CSV')
        ->assertDontSee('Import CSV')
        ->assertDontSeeHtml("/records/{$slug}/export");

    expect(Route::has("records.{$slug}.export"))->toBeFalse()
        ->and(app(TableTransfers::class)->exports("records.{$slug}"))->toBeFalse()
        ->and(app(TableTransfers::class)->imports("records.{$slug}"))->toBeFalse();
    test()->get("/records/{$slug}/export")->assertNotFound();
    test()->get("/records/{$slug}/export?template=1")->assertNotFound();

    // A dialog mounted by hand finds no import to run either.
    Livewire::test(CsvImport::class, ['table' => "records.{$slug}"])->assertNotFound();
})->with(['documents', 'employment']);

it('has no CSV on the Position or Departments & Units page: no button, no export route, no transfer key', function (string $component, string $slug) {
    rxferActor([ModuleCode::RECORDS], allData: true);

    Livewire::test($component)
        ->assertDontSee('Export CSV')
        ->assertDontSee('Import CSV')
        ->assertDontSeeHtml("/records/{$slug}/export");

    expect(Route::has("records.{$slug}.export"))->toBeFalse()
        ->and(app(TableTransfers::class)->exports("records.{$slug}"))->toBeFalse()
        ->and(app(TableTransfers::class)->imports("records.{$slug}"))->toBeFalse();
    test()->get("/records/{$slug}/export")->assertNotFound();
    Livewire::test(CsvImport::class, ['table' => "records.{$slug}"])->assertNotFound();
})->with([
    'positions' => [PositionsPage::class, 'positions'],
    'departments' => [DepartmentsPage::class, 'departments'],
]);

it('keeps the Export link in step with the table after every re-render', function () {
    rxferActor([ModuleCode::RECORDS], allData: true);

    Livewire::test(HolidaysPage::class)
        ->assertSeeHtml('href="'.e(route('records.holidays.export', ['type' => 'ALL', 'sort' => 'date', 'dir' => 'asc'])).'"')
        ->set('search', 'rizal')
        ->set('type', 'REGULAR')
        ->call('sortBy', 'name')
        ->assertSeeHtml('href="'.e(route('records.holidays.export', ['q' => 'rizal', 'type' => 'REGULAR', 'sort' => 'name', 'dir' => 'asc'])).'"');
});
