<?php

declare(strict_types=1);

use App\Domain\Enums\ModuleCode;
use App\Livewire\Employees\Show;
use App\Models\EmployeeProfile;
use App\Models\EmployeeTraining;
use App\Models\Role;
use App\Models\RoleModuleGrant;
use App\Models\User;
use App\Models\WorkSite;
use App\Modules\Workforce\Application\Queries\EmployeePdsWorkbook;
use App\Support\Access;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Personal Data Sheet downloads an Excel workbook only when requested from
 * an authorized Employee Records view. My Profile cannot invoke the export;
 * every download repeats the current module grant and employee row scope.
 * The browser still prints the 201 File and never renders a hidden PDS.
 */

/** @param list<string> $modules */
function pdsprintRole(array $modules, bool $allData): Role
{
    $role = new Role;
    $role->forceFill(['name' => 'Print '.implode('-', $modules).' '.Str::random(6), 'all_data' => $allData, 'is_system' => false])->save();
    foreach ($modules as $module) {
        RoleModuleGrant::query()->create(['role_id' => $role->id, 'module' => $module]);
    }

    return $role;
}

function pdsprintActing(Role $role, ?EmployeeProfile $employee = null): void
{
    $user = User::factory()->create();
    $user->forceFill(['role_id' => $role->id, 'employee_id' => $employee?->id])->save();

    test()->actingAs($user->refresh());
    app()->forgetInstance(Access::class);
}

function pdsprintHr(): void
{
    pdsprintActing(pdsprintRole([ModuleCode::EMPLOYEES], true));
}

function pdsprintEmployee(): EmployeeProfile
{
    $site = WorkSite::query()->create([
        'name' => 'Site '.Str::random(6), 'latitude' => 14.5995, 'longitude' => 120.9842, 'radius_meters' => 100, 'geofence_exempt' => true,
    ]);

    return EmployeeProfile::query()->create([
        'employee_code' => 'COP-'.Str::upper(Str::random(6)),
        'first_name' => 'Rosa', 'last_name' => 'Villanueva', 'position' => 'Accountant II',
        'employment_status' => 'Regular', 'date_hired' => '2024-01-03', 'monthly_rate' => 25000, 'work_site_id' => $site->id,
    ]);
}

/** The SQL a request makes, without relying on an engine's identifier quoting. */
function pdsprintQueries(Closure $run): string
{
    DB::flushQueryLog();
    DB::enableQueryLog();
    try {
        $run();

        return str_replace('`', '"', collect(DB::getQueryLog())->pluck('query')->implode("\n"));
    } finally {
        DB::disableQueryLog();
    }
}

/** These four lists are not needed for the profile tab or its 201 File. */
const PDSPRINT_PRINT_ONLY_TABLES = ['employee_eligibilities', 'employee_work_experiences', 'employee_trainings', 'employee_voluntary_works'];

it('offers the Excel Personal Data Sheet beside 201 File in Print', function () {
    $employee = pdsprintEmployee();
    pdsprintHr();

    $page = Livewire::test(Show::class, ['employee' => $employee->id])
        ->assertSee('Print')
        ->assertSee('201 File')
        ->assertSee('Personal Data Sheet (Excel)')
        ->assertSeeHtml('wire:click="printPds"')
        ->assertDontSeeHtml('sheet-pds')
        ->assertNoFileDownloaded();

    expect($page->html())
        ->toContain('x-on:record-print.window="$el.dataset.print = $event.detail.sheet; $nextTick(() => window.print())"')
        ->toContain('x-on:afterprint.window="delete $el.dataset.print"')
        ->toMatch('/x-on:click="\$dispatch\((\'|&#039;)record-print(\'|&#039;), \{ sheet: (\'|&#039;)201(\'|&#039;) \}\)"/');
});

it('reads the whole PDS lists only for the download and never embeds them on the page', function () {
    $employee = pdsprintEmployee();
    (new EmployeeTraining)->forceFill([
        'employee_id' => $employee->id, 'title' => 'PDS export only training',
        'date_from' => '2021-01-04', 'date_to' => '2021-01-05',
    ])->save();
    pdsprintHr();

    $page = null;
    $plain = pdsprintQueries(function () use (&$page, $employee): void {
        $page = Livewire::test(Show::class, ['employee' => $employee->id]);
    });
    assert($page instanceof Testable);
    $page->assertDontSee('PDS export only training')->assertDontSeeHtml('sheet-pds');
    foreach (PDSPRINT_PRINT_ONLY_TABLES as $table) {
        expect($plain)->not->toContain($table);
    }

    $chosen = pdsprintQueries(fn () => $page->call('printPds'));
    $page->assertFileDownloaded()
        ->assertNotDispatched('record-print')
        ->assertDontSee('PDS export only training')
        ->assertDontSeeHtml('sheet-pds');
    foreach (PDSPRINT_PRINT_ONLY_TABLES as $table) {
        expect($chosen)->toContain($table);
    }

    $after = pdsprintQueries(fn () => $page->set('tab', 'family'));
    foreach (PDSPRINT_PRINT_ONLY_TABLES as $table) {
        expect($after)->not->toContain($table);
    }
});

it('downloads workbook bytes with the Excel content type instead of asking the browser to print', function () {
    $employee = pdsprintEmployee();
    pdsprintHr();

    $page = Livewire::test(Show::class, ['employee' => $employee->id])
        ->call('printPds')
        ->assertFileDownloaded(contentType: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')
        ->assertNotDispatched('record-print')
        ->assertDontSeeHtml('sheet-pds');

    $name = (string) data_get($page->effects, 'download.name');
    $bytes = (string) base64_decode((string) data_get($page->effects, 'download.content'), true);
    expect($name)->toEndWith('.xlsx')
        ->and($bytes)->toStartWith("PK\x03\x04")
        ->and(strlen($bytes))->toBeGreaterThan(1000);
});

it('keeps the 201 File markup unchanged after the Excel download', function () {
    $employee = pdsprintEmployee();
    pdsprintHr();
    $page = Livewire::test(Show::class, ['employee' => $employee->id]);
    $sheet = static function (string $html): string {
        $start = (int) strpos($html, '<section class="sheet-201');

        return (string) preg_replace('/<!--\[if (END)?BLOCK\]><!\[endif\]-->/', '', substr($html, $start, (int) strpos($html, '</section>', $start) - $start));
    };
    $before = $sheet($page->html());
    $after = $sheet($page->call('printPds')->html());

    expect($before)->toContain('class="sheet-201 record-print"')
        ->and($after)->toBe($before);
});

it('omits My Profile exporting and refuses a forged print action for every linked role', function (bool $allData) {
    $employee = pdsprintEmployee();
    pdsprintActing(pdsprintRole([ModuleCode::PROFILE, ModuleCode::EMPLOYEES], $allData), $employee);
    $page = Livewire::test(Show::class, ['employee' => $employee->id, 'own' => true])
        ->assertDontSeeHtml('wire:click="printPds"')
        ->assertDontSeeHtml('sheet-201')
        ->assertDontSeeHtml('sheet-pds');

    $queries = pdsprintQueries(fn () => $page->call('printPds')->assertForbidden());
    $page->assertNoFileDownloaded();
    foreach (PDSPRINT_PRINT_ONLY_TABLES as $table) {
        expect($queries)->not->toContain($table);
    }
})->with([false, true]);

it('allows the own Employee Records download and refuses a foreign or unlinked record', function () {
    $mine = pdsprintEmployee();
    $theirs = pdsprintEmployee();
    $role = pdsprintRole([ModuleCode::EMPLOYEES], false);
    pdsprintActing($role, $mine);

    Livewire::test(Show::class, ['employee' => $mine->id])
        ->call('printPds')->assertFileDownloaded();
    Livewire::test(Show::class, ['employee' => $theirs->id])
        ->assertSee('Employee Not Found')
        ->call('printPds')->assertNoFileDownloaded()->assertForbidden();

    pdsprintActing($role);
    Livewire::test(Show::class, ['employee' => $mine->id])
        ->assertSee('Employee Not Found')
        ->call('printPds')->assertNoFileDownloaded()->assertForbidden();
});

it('requires Employee Records permission when the export query is called directly', function (string $module, bool $allData, bool $linked) {
    $employee = pdsprintEmployee();
    pdsprintActing(pdsprintRole([$module], $allData), $linked ? $employee : null);

    try {
        app(EmployeePdsWorkbook::class)->download(app(Access::class), $employee->id);
        test()->fail('An unauthorized direct PDS export was accepted.');
    } catch (HttpException $exception) {
        expect($exception->getStatusCode())->toBe(403);
    }
})->with([
    'Profile-only employee' => [ModuleCode::PROFILE, false, true],
    'Payroll-only manager' => [ModuleCode::PAYROLL, true, false],
    'Unlinked Employee Records viewer' => [ModuleCode::EMPLOYEES, false, false],
]);

it('rechecks the grant when a mounted record requests a workbook', function () {
    $employee = pdsprintEmployee();
    $role = pdsprintRole([ModuleCode::EMPLOYEES], false);
    pdsprintActing($role, $employee);
    $page = Livewire::test(Show::class, ['employee' => $employee->id]);

    RoleModuleGrant::query()->where('role_id', $role->id)->delete();
    $user = auth()->user();
    assert($user instanceof User);
    $this->actingAs($user->refresh());
    app()->forgetInstance(Access::class);

    $page->call('printPds')->assertNoFileDownloaded()->assertForbidden();
});
