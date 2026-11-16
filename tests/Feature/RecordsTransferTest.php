<?php

declare(strict_types=1);

use App\Domain\Enums\AuditEvent;
use App\Domain\Enums\ModuleCode;
use App\Livewire\Kit\CsvImport;
use App\Livewire\Records\DepartmentsPage;
use App\Livewire\Records\HolidaysPage;
use App\Livewire\Records\IncentiveTypesPage;
use App\Livewire\Records\LeaveCreditSettingsPage;
use App\Livewire\Records\PositionsPage;
use App\Livewire\Records\ReferenceListPage;
use App\Livewire\Records\ShiftTypesPage;
use App\Livewire\Records\SitesPage;
use App\Models\AuditLog;
use App\Models\Holiday;
use App\Models\PayrollIncentiveType;
use App\Models\Role;
use App\Models\RoleModuleGrant;
use App\Models\User;
use App\Modules\Organization\Application\Commands\SaveHoliday;
use App\Modules\Organization\Application\HolidayDraft;
use App\Modules\Payroll\Application\Commands\ImportIncentiveTypes;
use App\Modules\Payroll\Application\Commands\SaveIncentiveType;
use App\Platform\Transfer\ImportReport;
use App\Platform\Transfer\ImportRunner;
use App\Platform\Transfer\TableTransfers;
use App\Support\Access;
use App\Support\Csv;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Livewire\Livewire;

/**
 * The Records tables beyond the plain lists — holidays, incentive types —
 * export what their table shows and import back through their own save
 * command: an untouched export changes nothing, a known ID updates, a
 * blank one adds, and any bad row keeps the whole file out. Writers see
 * Import; readers only Export. Of the plain lists, Payroll Deductions
 * carries the same two buttons, while THE LEAVE TYPES, SHIFTING SETTINGS,
 * SITE SETTINGS AND THE THREE GENERAL RECORDS LISTS — Position, Document,
 * Employment — HAVE NO CSV AT ALL: no button, no export route, no key an
 * import could be mounted under.
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

/* |---| Incentive types |---| */

it('lets an all-data payroll officer import incentive types by their tax label', function () {
    rxferActor([ModuleCode::PAYROLL], allData: true);
    app(SaveIncentiveType::class)->handle(app(Access::class), null, 'Rice subsidy', null, false);
    $code = (string) PayrollIncentiveType::query()->value('code');

    expect(rxferRows('/records/payroll-settings/incentive-types/export'))->toBe([[$code, 'Rice subsidy', 'Tax-exempt', '', 'Typed per payroll', '', 'One-time', '', '0', 'None', '', '', '', 'No']]);

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

it('shows Export to every reader and Import only to the table\'s writers', function (string $component, string $module) {
    rxferActor([$module], allData: false);
    Livewire::test($component)->assertSee('Export CSV')->assertDontSee('Import CSV');

    rxferActor([$module], allData: true);
    Livewire::test($component)->assertSee('Export CSV')->assertSee('Import CSV');
})->with([
    'holidays' => [HolidaysPage::class, ModuleCode::RECORDS],
    // A Payroll Setting: its writers hold PAYROLL (PayrollSettingsAccess).
    'incentive types' => [IncentiveTypesPage::class, ModuleCode::PAYROLL],
]);

it('keeps Export for every reader and Import for the writers on the plain lists that carry a CSV', function (string $slug, string $module) {
    rxferActor([$module], allData: false);
    Livewire::test(ReferenceListPage::class, ['slug' => $slug])->assertSee('Export CSV')->assertDontSee('Import CSV');

    rxferActor([$module], allData: true);
    Livewire::test(ReferenceListPage::class, ['slug' => $slug])
        ->assertSeeHtml('href="'.e(route("records.{$slug}.export", ['sort' => 'name', 'dir' => 'asc'])).'"')
        ->assertSee('Import CSV');

    expect(app(TableTransfers::class)->exports("records.{$slug}"))->toBeTrue()
        ->and(app(TableTransfers::class)->imports("records.{$slug}"))->toBeTrue();
})->with([
    // A Payroll Setting: its writers hold PAYROLL (PayrollSettingsAccess).
    'payroll deductions' => ['payroll-deductions', ModuleCode::PAYROLL],
]);

it('has no CSV for the leave types: no button on Leave Settings, no export route, no transfer key', function () {
    rxferActor([ModuleCode::RECORDS], allData: true);

    // The Leave types card stands with its Add button, and nothing on the page links to an export.
    Livewire::test(LeaveCreditSettingsPage::class)
        ->assertSee('Add Leave Type')
        ->assertDontSee('Export CSV')
        ->assertDontSee('Import CSV')
        ->assertDontSeeHtml('/records/leave-types/export');

    expect(Route::has('records.leave-types.export'))->toBeFalse()
        ->and(app(TableTransfers::class)->exports('records.leave-types'))->toBeFalse()
        ->and(app(TableTransfers::class)->imports('records.leave-types'))->toBeFalse();
    test()->get('/records/leave-types/export')->assertNotFound();
    test()->get('/records/leave-types/export?template=1')->assertNotFound();

    // A dialog mounted by hand finds no import to run either.
    Livewire::test(CsvImport::class, ['table' => 'records.leave-types'])->assertNotFound();
});

it('has no CSV on a General Records list: no button, no export route, no transfer key', function (string $slug) {
    rxferActor([ModuleCode::RECORDS], allData: true);

    // The list stands with its Add button, and nothing on the page links to an export.
    Livewire::test(ReferenceListPage::class, ['slug' => $slug])
        ->assertSee('Add '.ucwords(ReferenceListPage::LISTS[$slug]['noun']))
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

it('has no CSV on the Position, Departments & Units, Shifting Settings or Site Settings page: no button, no export route, no transfer key', function (string $component, string $slug) {
    rxferActor([ModuleCode::RECORDS], allData: true);

    Livewire::test($component)
        ->assertDontSee('Export CSV')
        ->assertDontSee('Import CSV')
        ->assertDontSeeHtml("/records/{$slug}/export");

    expect(Route::has("records.{$slug}.export"))->toBeFalse()
        ->and(app(TableTransfers::class)->exports("records.{$slug}"))->toBeFalse()
        ->and(app(TableTransfers::class)->imports("records.{$slug}"))->toBeFalse();
    test()->get("/records/{$slug}/export")->assertNotFound();
    test()->get("/records/{$slug}/export?template=1")->assertNotFound();
    Livewire::test(CsvImport::class, ['table' => "records.{$slug}"])->assertNotFound();
})->with([
    'positions' => [PositionsPage::class, 'positions'],
    'departments' => [DepartmentsPage::class, 'departments'],
    'shifting settings' => [ShiftTypesPage::class, 'shifts'],
    'site settings' => [SitesPage::class, 'sites'],
]);

it('keeps the Add Site button on Site Settings for a Records manager', function () {
    rxferActor([ModuleCode::RECORDS], allData: true);

    Livewire::test(SitesPage::class)->assertSee('Add Site');
});

it('keeps the Add shift type button on Shifting Settings for a Records manager', function () {
    rxferActor([ModuleCode::RECORDS], allData: true);

    Livewire::test(ShiftTypesPage::class)->assertSee('Add Shift Type');
});

it('keeps the Export link in step with the table after every re-render', function () {
    rxferActor([ModuleCode::RECORDS], allData: true);

    Livewire::test(HolidaysPage::class)
        ->assertSeeHtml('href="'.e(route('records.holidays.export', ['type' => 'ALL', 'sort' => 'date', 'dir' => 'asc'])).'"')
        ->set('search', 'rizal')
        ->set('type', 'REGULAR')
        ->call('sortBy', 'name')
        ->assertSeeHtml('href="'.e(route('records.holidays.export', ['q' => 'rizal', 'type' => 'REGULAR', 'sort' => 'name', 'dir' => 'asc'])).'"');
});
