<?php

declare(strict_types=1);

use App\Domain\Enums\ModuleCode;
use App\Livewire\Reports\Home;
use App\Livewire\Reports\Index;
use App\Models\EmployeeProfile;
use App\Models\Role;
use App\Models\RoleModuleGrant;
use App\Models\User;
use App\Platform\Reports\ReportDocument;
use App\Support\Access;
use Illuminate\Support\Str;
use Livewire\Livewire;

/**
 * The Reports home names every report and draws a graph only from a source the role holds; each
 * report has its own address; a changed filter drops the document and has the page ask for the new
 * one; a report prints as the company's portrait sheet with none of the old screen clutter.
 */

/** @param list<string> $modules */
function rpageActor(array $modules, bool $all = true): User
{
    $role = new Role;
    $role->forceFill(['name' => 'Report pages '.Str::random(8), 'all_data' => $all, 'is_system' => false])->save();
    foreach ($modules as $module) {
        $grant = new RoleModuleGrant;
        $grant->forceFill(['role_id' => $role->getKey(), 'module' => $module])->save();
    }
    $user = User::factory()->create(['role_id' => $role->getKey()]);
    test()->actingAs($user);
    app()->forgetInstance(Access::class);

    return $user;
}

function rpageEmployee(string $code): EmployeeProfile
{
    $employee = new EmployeeProfile;
    $employee->forceFill([
        'employee_code' => $code, 'employee_id_no' => 'EMP-'.$code, 'first_name' => 'Page', 'last_name' => $code,
        'employment_status' => 'Regular', 'date_hired' => '2024-01-01', 'is_active' => true,
    ])->save();

    return $employee;
}

/*
|--------------------------------------------------------------------------
| The home
|--------------------------------------------------------------------------
*/

it('links the reports a role can open and names the rest without a link', function () {
    rpageActor([ModuleCode::REPORTS, ModuleCode::EMPLOYEES]);
    Livewire::test(Home::class)
        ->assertSee('reports/employee-list', false)->assertSee('Workforce Analysis')
        ->assertSee('Needs Payroll access')->assertDontSee('reports/payroll-register', false)
        ->assertSee('Needs full Audit Trail access')->assertDontSee('reports/audit-logs', false);
});

it('draws a home graph only from a source the role holds', function () {
    rpageEmployee('A');
    rpageActor([ModuleCode::REPORTS, ModuleCode::EMPLOYEES]);
    Livewire::test(Home::class)
        ->assertSee('Employees by Department')
        ->assertDontSee('Net Pay by Payroll')->assertDontSee('Approved Leave This Month')
        ->assertViewHas('charts', fn (array $charts): bool => count($charts) === 1 && $charts[0]['kind'] === 'bars');

    rpageActor([ModuleCode::REPORTS]);
    Livewire::test(Home::class)->assertViewHas('charts', [])->assertDontSee('Employees by Department');
});

/*
|--------------------------------------------------------------------------
| A report's own page
|--------------------------------------------------------------------------
*/

it('gives each report its own address and refuses one that does not exist', function () {
    rpageActor([ModuleCode::REPORTS, ModuleCode::LEAVES]);
    $this->get('/reports/leave-reports')->assertOk()->assertSee('Leave Requests')->assertSee('Leave and business travel in the period.');
    $this->get('/reports/not-a-report')->assertNotFound();
    // A report whose source the role lacks still opens, and says what is missing.
    $this->get('/reports/payroll-register')->assertOk()->assertSee('source-module grant');
});

it('opens the view its address names and ignores one the report does not have', function () {
    rpageActor([ModuleCode::REPORTS, ModuleCode::EMPLOYEES]);
    Livewire::withQueryParams(['view' => 'analysis']);
    Livewire::test(Index::class, ['report' => 'employee-list'])
        ->assertSet('type', 'employee-list')->assertSet('view', 'analysis')->assertSee('Workforce Analysis');
    Livewire::withQueryParams(['view' => 'details']);
    Livewire::test(Index::class, ['report' => 'employee-list'])
        ->assertSet('view', 'summary');
});

it('keeps the period and the chosen employees when moving between views and drops a malformed one', function () {
    $employee = rpageEmployee('A');
    rpageActor([ModuleCode::REPORTS, ModuleCode::EMPLOYEES, ModuleCode::EDTR]);
    Livewire::withQueryParams(['view' => 'details', 'from' => '2026-09-28', 'to' => '2026-09-29', 'employees' => $employee->id]);
    Livewire::test(Index::class, ['report' => 'attendance-reports'])
        ->assertSet('view', 'details')->assertSet('from', '2026-09-28')->assertSet('to', '2026-09-29')
        ->assertSet('employeeIds', [$employee->id])
        ->assertSee('from=2026-09-28', false)->assertSee('employees='.$employee->id, false);

    Livewire::withQueryParams(['from' => '2026-09-30', 'to' => '2026-09-01', 'employees' => 'not an id']);
    Livewire::test(Index::class, ['report' => 'attendance-reports'])
        ->assertSet('employeeIds', [])->assertNotSet('from', '2026-09-30');
});

it('asks for the new document when a filter changes and for none it cannot produce', function () {
    rpageEmployee('A');
    rpageActor([ModuleCode::REPORTS, ModuleCode::EMPLOYEES, ModuleCode::EDTR]);
    $screen = Livewire::test(Index::class, ['report' => 'employee-list'])->call('generate')->assertHasNoErrors();
    $screen->set('filters.active', 'ACTIVE')->assertSet('generated', [])->assertViewHas('document', null);
    expect(json_encode($screen->effects ?? []))->toContain('$wire.generate()');

    // Attendance covers chosen employees only: with none there is nothing to ask for.
    $attendance = Livewire::test(Index::class, ['report' => 'attendance-reports'])
        ->assertSee('Choose Employees to Compare')->set('from', '2026-09-01');
    expect(json_encode($attendance->effects ?? []))->not->toContain('$wire.generate()');
});

it('shows a wide report one column set at a time and falls back from an unknown set', function () {
    rpageEmployee('A');
    rpageActor([ModuleCode::REPORTS, ModuleCode::EMPLOYEES]);
    Livewire::test(Index::class, ['report' => 'employee-list'])->call('generate')
        ->set('columnsView', 'not-a-set')->assertSet('columnsView', 'summary')
        // Choosing columns to look at changes nothing about the document or its coverage.
        ->assertViewHas('document', fn (?ReportDocument $document): bool => $document !== null);
});

/*
|--------------------------------------------------------------------------
| Paper
|--------------------------------------------------------------------------
*/

it('prints a report as a portrait company sheet without the old screen clutter', function () {
    config(['copems.company_name' => 'Fixture Company']);
    rpageEmployee('A');
    rpageActor([ModuleCode::REPORTS, ModuleCode::EMPLOYEES]);
    $html = Livewire::test(Index::class, ['report' => 'employee-list'])->call('generate')->html();
    expect(substr_count($html, 'class="reports-sheet hris-always-light"'))->toBe(1)
        ->and($html)->toContain('record-print')->toContain('bestworkflows-wordmark.png')->toContain('Fixture Company')
        ->toContain('Employee List')->toContain('EMP-A')
        ->not->toContain('Internal report')
        ->not->toContain('Management Information System')
        ->not->toContain('End of report');
});

it('offers each part and column of the sheet to leave off the paper', function () {
    rpageEmployee('A');
    rpageActor([ModuleCode::REPORTS, ModuleCode::EMPLOYEES]);
    Livewire::test(Index::class, ['report' => 'employee-list'])->call('generate')
        ->assertViewHas('printOptions', fn (array $options): bool => array_column($options['parts'], 'key') === ['totals']
            && $options['tables'][0]['key'] === 'main'
            && $options['tables'][0]['columns'] === ['Employee ID', 'Employee', 'Department', 'Unit', 'Position', 'Employment Status', 'Active Status', 'Worksite'])
        ->assertSee('Print Options')->assertSee('data-print-part="totals"', false)->assertSee('data-print-table="main"', false)
        ->assertSee("columns['main'][7]", false);

    // An analysis offers its graphs, its summary and each supporting table; nothing is offered before a document exists.
    Livewire::withQueryParams(['view' => 'analysis']);
    $analysis = Livewire::test(Index::class, ['report' => 'employee-list']);
    $analysis->assertDontSee('Print Options')->assertViewHas('printOptions', ['parts' => [], 'tables' => []]);
    $analysis->call('generate')
        ->assertViewHas('printOptions', fn (array $options): bool => in_array('charts', array_column($options['parts'], 'key'), true)
            && in_array('main', array_column($options['parts'], 'key'), true)
            && in_array('section-0', array_column($options['parts'], 'key'), true));
});
