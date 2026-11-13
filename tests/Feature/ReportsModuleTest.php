<?php

declare(strict_types=1);

use App\Domain\Enums\ModuleCode;
use App\Livewire\Reports\Home;
use App\Livewire\Reports\Index;
use App\Livewire\Reports\Support\ReportCatalog;
use App\Livewire\Reports\Support\ReportsExport;
use App\Models\AuditLog;
use App\Models\EmployeeProfile;
use App\Models\Role;
use App\Models\RoleModuleGrant;
use App\Models\User;
use App\Platform\Audit\AuditReport;
use App\Platform\Reports\ReportCriteria;
use App\Platform\Reports\ReportDocument;
use App\Support\Access;
use App\Support\ManilaClock;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** Reports navigation never confers source access; print and CSV share validated, scoped reads. */

/** @param list<string> $modules */
function reportsModuleActor(array $modules, bool $all = false): User
{
    $role = Role::create(['name' => 'Reports '.Str::random(8), 'all_data' => $all, 'is_system' => false]);
    foreach ($modules as $module) {
        RoleModuleGrant::create(['role_id' => $role->id, 'module' => $module]);
    }

    return User::factory()->create(['role_id' => $role->id]);
}

it('gates the reports page and export on the new grant', function () {
    $this->get('/reports')->assertRedirect(route('login'));
    $this->get('/reports/export')->assertRedirect(route('login'));
    $this->actingAs(reportsModuleActor([ModuleCode::PROFILE], true));
    $this->get('/reports')->assertRedirect('/profile');
    $this->get('/reports/export')->assertRedirect('/profile');
});

it('shows nine report choices without giving a Reports-only actor source access', function () {
    $user = reportsModuleActor([ModuleCode::REPORTS], true);
    $this->actingAs($user);
    $home = Livewire::test(Home::class)->assertSee('Needs Payroll access')->assertDontSee('reports/payroll-register', false);
    foreach (ReportCatalog::all() as $report) {
        $home->assertSee($report['title']);
    }
    expect(ReportCatalog::all())->toHaveCount(9);
    Livewire::test(Index::class)->assertSee('source-module grant')->call('generate')->assertForbidden();
    $this->get('/reports/export?type=employee-list&from=2026-09-01&to=2026-09-30')->assertForbidden();
});

it('generates printable employee records and exports those same scoped cells', function () {
    config(['copems.company_name' => 'Fixture Company']);
    $user = reportsModuleActor([ModuleCode::REPORTS, ModuleCode::EMPLOYEES], true);
    $employee = EmployeeProfile::create(['employee_code' => '42', 'employee_id_no' => 'EMP-2026-007', 'first_name' => 'Report', 'last_name' => 'Employee', 'employment_status' => 'Regular', 'date_hired' => '2026-01-01', 'is_active' => true]);
    $this->actingAs($user);
    Livewire::test(Index::class)
        ->assertSet('type', 'employee-list')
        ->call('generate')->assertSee('EMP-2026-007')->assertSee('window.print()', false)
        ->assertSee('Fixture Company')->assertSee('bestworkflows-wordmark.png', false)
        ->assertSee('record-print', false)->assertSee('reports/export', false)
        ->set('employeeIds', [$employee->id])->assertSet('generated', [])
        ->call('generate')->assertSee('EMP-2026-007')
        ->call('clearEmployees')->assertSet('employeeIds', [])->assertSet('generated', []);

    $query = ['type' => 'employee-list', 'from' => '2026-09-01', 'to' => '2026-09-30'];
    $actor = Access::forActor($user);
    $document = ReportCatalog::generate($actor, ReportCriteria::from($query));
    $export = (new ReportsExport)->export($actor, $query);
    expect($export->headers)->toBe($document->columns)
        ->and(($export->rows)())->toBe($document->rows);
    $this->get('/reports/export?'.http_build_query($query))->assertOk()->assertDownload();
});

it('keeps multiple selected employees in the preview and CSV and clears stale previews on selection changes', function () {
    $user = reportsModuleActor([ModuleCode::REPORTS, ModuleCode::EMPLOYEES], true);
    $employees = [];
    foreach ([['MUL-001', 'Engineer', true], ['MUL-002', 'Foreman', false], ['MUL-003', 'Accountant', true]] as [$code, $position, $active]) {
        $employees[] = EmployeeProfile::create([
            'employee_code' => $code, 'employee_id_no' => 'EMP-'.$code, 'first_name' => 'Report', 'last_name' => $code,
            'position' => $position, 'employment_status' => 'Regular', 'date_hired' => '2026-01-01', 'is_active' => $active,
        ]);
    }
    $ids = [$employees[0]->id, $employees[1]->id];
    $this->actingAs($user);
    $screen = Livewire::test(Index::class)
        ->assertSee('data-kit-employee-checklist="report-employees"', false)
        ->set('employeeIds', $ids)->call('generate')->assertHasNoErrors()
        ->assertSet('generated.employees', implode(',', $ids))
        ->assertViewHas('document', fn (ReportDocument $document): bool => array_column($document->rows, 0) === ['EMP-MUL-001', 'EMP-MUL-002']);

    $query = $screen->get('generated');
    $export = (new ReportsExport)->export(Access::forActor($user), $query);
    expect(collect(($export->rows)())->pluck(0)->all())->toBe(['EMP-MUL-001', 'EMP-MUL-002']);
    $this->get('/reports/export?'.http_build_query($query))->assertOk()->assertDownload();

    $screen->set('employeeIds', [$employees[1]->id])->assertSet('generated', [])
        ->assertViewHas('document', null)
        ->call('generate')->assertHasNoErrors()
        ->assertViewHas('document', fn (ReportDocument $document): bool => array_column($document->rows, 0) === ['EMP-MUL-002'])
        ->call('clearEmployees')->assertSet('employeeIds', [])->assertSet('generated', []);
});

it('prints attendance in separate tables for selected employees with their positions', function () {
    $this->travelTo(new DateTimeImmutable('2026-10-02T12:00:00+08:00'));
    $this->actingAs(reportsModuleActor([ModuleCode::REPORTS, ModuleCode::EDTR, ModuleCode::EMPLOYEES], true));
    $ids = [];
    foreach ([['DTR-001', 'Engineer'], ['DTR-002', 'Foreman']] as [$code, $position]) {
        $ids[] = EmployeeProfile::create([
            'employee_code' => $code, 'first_name' => 'Report', 'last_name' => $code,
            'position' => $position, 'employment_status' => 'Regular', 'date_hired' => '2026-01-01', 'is_active' => true,
        ])->id;
    }
    Livewire::test(Index::class)->call('selectReport', 'attendance-reports')
        ->set('view', 'details')
        ->set('employeeIds', $ids)->set('from', '2026-09-28')->set('to', '2026-09-29')
        ->call('generate')->assertHasNoErrors()
        ->assertViewHas('document', fn (ReportDocument $document): bool => count($document->sections) === 2)
        ->assertSeeInOrder(['Position', 'Engineer', 'Position', 'Foreman'])
        ->assertViewHas('sheets', true)
        ->assertDontSee('Export CSV')
        ->call('selectReport', 'employee-list')->assertSet('employeeIds', [])->assertSet('generated', []);
});

it('rejects malformed employee export filters rather than broadening the selection', function (string $filter) {
    $this->actingAs(reportsModuleActor([ModuleCode::REPORTS, ModuleCode::EMPLOYEES], true));
    $this->getJson('/reports/export?'.http_build_query([
        'type' => 'employee-list', $filter => ['selected-employee'],
    ]))->assertUnprocessable()->assertJsonValidationErrors($filter);
})->with(['employees', 'employeeIds', 'employee', 'employeeId']);

it('continues to export all permitted employees when the selection is intentionally empty', function () {
    $this->actingAs(reportsModuleActor([ModuleCode::REPORTS, ModuleCode::EMPLOYEES], true));
    $this->get('/reports/export?type=employee-list&employees=')->assertOk()->assertDownload();
});

it('renders an empty report and rejects invalid and oversized periods', function () {
    $this->actingAs(reportsModuleActor([ModuleCode::REPORTS, ModuleCode::LEAVES, ModuleCode::EMPLOYEES], true));
    Livewire::test(Index::class)->call('selectReport', 'leave-reports')->call('generate')->assertSee('No records match this report.')
        ->set('from', '2026-09-30')->set('to', '2026-09-01')->call('generate')->assertHasErrors('to')
        ->set('from', '2024-01-01')->set('to', '2026-01-01')->call('generate')->assertHasErrors('to')
        ->call('selectReport', 'employee-list')->call('generate')->assertHasNoErrors();
    expect(fn () => ReportDocument::guardSize(5001))->toThrow(ValidationException::class);
    expect(fn () => ReportCriteria::from(['type' => 'audit-logs', 'from' => 'invalid', 'to' => '2026-09-30']))->toThrow(ValidationException::class);
});

it('does not restore attendance CSV through the Reports export endpoint', function () {
    $actor = Access::forActor(reportsModuleActor([ModuleCode::REPORTS, ModuleCode::EDTR], true));
    expect(fn () => (new ReportsExport)->export($actor, ['type' => 'attendance-reports', 'from' => '2026-09-01', 'to' => '2026-09-30']))
        ->toThrow(HttpException::class);
});

it('rejects report generation after its source grant is revoked', function () {
    $user = reportsModuleActor([ModuleCode::REPORTS, ModuleCode::EMPLOYEES], true);
    $this->actingAs($user);
    $screen = Livewire::test(Index::class);
    RoleModuleGrant::query()->where('role_id', $user->role_id)->where('module', ModuleCode::EMPLOYEES)->delete();
    $this->actingAs($user->fresh());
    app()->forgetInstance(Access::class);
    $screen->call('generate')->assertForbidden();
});

it('reports complete audit periods with Manila date boundaries beyond the latest 500 window', function () {
    $actor = Access::forActor(reportsModuleActor([ModuleCode::REPORTS, ModuleCode::AUDIT], true));
    foreach ([['2026-08-31T23:59:59', 'Before'], ['2026-09-01T00:00:00', 'First'], ['2026-09-01T23:59:59', 'Last'], ['2026-09-02T00:00:00', 'After']] as [$at, $label]) {
        (new AuditLog)->forceFill(['action' => 'REPORT_FIXTURE', 'actor_name' => 'Test', 'details' => $label, 'created_at' => ManilaClock::instant($at)->utc()])->save();
    }
    for ($i = 0; $i < 501; $i++) {
        (new AuditLog)->forceFill(['action' => 'REPORT_FIXTURE', 'actor_name' => 'Test', 'details' => 'In period', 'created_at' => ManilaClock::instant('2026-09-01T12:00:00')->utc()])->save();
    }
    $criteria = ReportCriteria::from(['type' => 'audit-logs', 'from' => '2026-09-01', 'to' => '2026-09-01']);
    $report = app(AuditReport::class)->generate($actor, $criteria);
    expect($report->rows)->toHaveCount(503)
        ->and($report->rows[0][5])->toBe('Last')
        ->and($report->rows[502][5])->toBe('First')
        ->and($report->rows[502][0])->toBe('2026-09-01 00:00:00.000');
});

it('requires both Reports and all-data Audit for direct audit generation', function (array $modules, bool $all) {
    $actor = Access::forActor(reportsModuleActor(array_values($modules), $all));
    $criteria = ReportCriteria::from(['type' => 'audit-logs', 'from' => '2026-09-01', 'to' => '2026-09-30']);
    expect(fn () => app(AuditReport::class)->generate($actor, $criteria))->toThrow(HttpException::class);
})->with([
    'Reports alone' => [[ModuleCode::REPORTS], true],
    'Audit alone' => [[ModuleCode::AUDIT], true],
    'restricted Audit' => [[ModuleCode::REPORTS, ModuleCode::AUDIT], false],
]);

it('uses inclusive Manila calendar presets and invalidates the previous report coverage', function () {
    $this->travelTo(new DateTimeImmutable('2026-09-30T16:30:00Z'));
    $this->actingAs(reportsModuleActor([ModuleCode::REPORTS, ModuleCode::LEAVES], true));
    Livewire::test(Index::class)
        ->assertSet('from', '2026-10-01')->assertSet('to', '2026-10-01')
        ->call('generate')->assertHasNoErrors()
        ->call('setPeriod', 'previous-month')->assertSet('from', '2026-09-01')->assertSet('to', '2026-09-30')
        ->assertSet('generated', [])->assertViewHas('document', null)
        ->call('setPeriod', 'last-seven')->assertSet('from', '2026-09-25')->assertSet('to', '2026-10-01')
        ->call('setPeriod', 'last-thirty')->assertSet('from', '2026-09-02')->assertSet('to', '2026-10-01')
        ->call('setPeriod', 'month')->assertSet('from', '2026-10-01')->assertSet('to', '2026-10-01');
});

it('handles leap years and year boundaries in the previous month shortcut', function (string $today, string $from, string $to) {
    $this->travelTo(new DateTimeImmutable($today));
    $this->actingAs(reportsModuleActor([ModuleCode::REPORTS, ModuleCode::LEAVES], true));
    Livewire::test(Index::class)->call('setPeriod', 'previous-month')
        ->assertSet('from', $from)->assertSet('to', $to);
})->with([
    'leap February' => ['2024-03-31T12:00:00+08:00', '2024-02-01', '2024-02-29'],
    'previous year' => ['2026-01-31T12:00:00+08:00', '2025-12-01', '2025-12-31'],
]);

it('keeps a generated report when its employee chooser opens or its report is reselected', function () {
    $this->actingAs(reportsModuleActor([ModuleCode::REPORTS, ModuleCode::EMPLOYEES], true));
    $screen = Livewire::test(Index::class)->call('generate');
    $criteria = $screen->get('generated');
    $screen->call('chooseEmployees')->assertSet('generated', $criteria)
        ->call('selectReport', 'employee-list')->assertSet('generated', $criteria);
});

it('rejects unrecognized date shortcuts without changing valid filter dates', function () {
    $this->actingAs(reportsModuleActor([ModuleCode::REPORTS, ModuleCode::LEAVES], true));
    $screen = new Index;
    $screen->type = 'leave-reports';
    $screen->from = '2026-09-01';
    $screen->to = '2026-09-30';
    expect(fn () => $screen->setPeriod('unexpected'))->toThrow(HttpException::class)
        ->and($screen->from)->toBe('2026-09-01')->and($screen->to)->toBe('2026-09-30');
});

it('reauthorizes report shortcuts and the employee chooser after source access is revoked', function (string $action, array $arguments) {
    $user = reportsModuleActor([ModuleCode::REPORTS, ModuleCode::LEAVES], true);
    $this->actingAs($user);
    $screen = Livewire::test(Index::class);
    RoleModuleGrant::query()->where('role_id', $user->role_id)->where('module', ModuleCode::LEAVES)->delete();
    $this->actingAs($user->fresh());
    app()->forgetInstance(Access::class);
    $screen->call($action, ...$arguments)->assertForbidden();
})->with([
    'employee chooser' => ['chooseEmployees', []],
    'period shortcut' => ['setPeriod', ['month']],
]);
