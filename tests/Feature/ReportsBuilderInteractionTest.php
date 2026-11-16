<?php

declare(strict_types=1);

use App\Domain\Enums\ModuleCode;
use App\Livewire\Reports\Index;
use App\Models\EmployeeProfile;
use App\Models\Role;
use App\Models\RoleModuleGrant;
use App\Models\User;
use App\Platform\Reports\ReportCriteria;
use App\Platform\Reports\ReportDocument;
use App\Support\Access;
use App\Support\Csv;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;
use Livewire\Livewire;

/** Report controls keep previews, pagination, printed facts and authenticated CSV downloads on the same scoped criteria. */

/** @param list<string> $modules */
function reportsBuilderActor(array $modules = [ModuleCode::REPORTS, ModuleCode::EMPLOYEES, ModuleCode::EDTR]): User
{
    $role = Role::create(['name' => 'Report builder '.Str::random(8), 'all_data' => true, 'is_system' => false]);
    foreach ($modules as $module) {
        RoleModuleGrant::create(['role_id' => $role->id, 'module' => $module]);
    }
    $user = User::factory()->create(['role_id' => $role->id]);
    test()->actingAs($user);
    app()->forgetInstance(Access::class);

    return $user;
}

/** @param array<string, mixed> $attributes */
function reportsBuilderEmployee(string $code, array $attributes = []): EmployeeProfile
{
    return EmployeeProfile::create(array_replace([
        'employee_code' => $code, 'employee_id_no' => 'ID-'.$code,
        'first_name' => 'Builder', 'last_name' => $code, 'position' => 'Engineer',
        'employment_status' => 'Regular', 'date_hired' => '2024-01-01', 'is_active' => true,
        'sex' => 'Female', 'civil_status' => 'Single', 'birth_date' => '1990-01-01',
    ], $attributes));
}

/** @return list<list<string>> */
function reportsBuilderCsv(string $csv): array
{
    return array_map(
        static fn (string $line): array => array_map('strval', str_getcsv($line, ',', '"', '')),
        explode("\r\n", substr($csv, strlen(Csv::BOM))),
    );
}

it('keeps the assigned employee ID in directory previews print output and CSV after resets', function () {
    reportsBuilderActor();
    reportsBuilderEmployee('42', ['employee_id_no' => 'HR-2026-042', 'last_name' => 'Assigned']);
    reportsBuilderEmployee('43', ['employee_id_no' => null, 'last_name' => 'Missing']);
    $screen = Livewire::test(Index::class)->call('generate')->assertHasNoErrors()
        ->assertSee('HR-2026-042')->assertDontSee('Employee code')
        ->assertViewHas('document', fn (ReportDocument $report): bool => $report->columns[0] === 'Employee ID'
            && array_column($report->rows, 0) === ['HR-2026-042', '—']);
    expect($screen->get('columns'))->not->toContain('employee_code');
    $csv = reportsBuilderCsv($this->get(route('reports.export', $screen->get('generated')))->assertOk()->streamedContent());
    expect(array_column($csv, 0))->toBe(['Employee ID', 'HR-2026-042', '—']);

    $screen->set('columns', ['employee_code', 'employee_id', 'employee'])->call('generate')->assertHasNoErrors()
        ->assertViewHas('document', fn (ReportDocument $report): bool => $report->columns === ['ID', 'Employee ID', 'Employee']
            && $report->rows === [['42', 'HR-2026-042', 'Builder Assigned'], ['43', '—', 'Builder Missing']])
        ->call('resetFilters')->call('applyPreset', 'directory')->call('generate')->assertHasNoErrors()
        ->assertViewHas('document', fn (ReportDocument $report): bool => $report->columns[0] === 'Employee ID'
            && ! in_array('employee_code', $report->columnKeys, true));
});

it('applies employee starting points and clears the previous output before showing changed criteria', function () {
    reportsBuilderActor();
    $employee = reportsBuilderEmployee('Preset');
    $screen = Livewire::test(Index::class)
        ->assertSet('type', 'employee-list')
        ->set('employeeIds', [$employee->id])->set('filters.active', 'ACTIVE')
        ->call('generate')->assertHasNoErrors()
        ->call('applyPreset', 'profile')->assertSet('generated', [])->assertViewHas('document', null)
        ->assertSet('employeeIds', [$employee->id])->assertSet('filters.active', 'ALL');
    expect($screen->get('columns'))->toContain('sex', 'civil_status', 'age', 'tenure', 'education');
    $screen->call('generate')->assertHasNoErrors()
        ->assertViewHas('document', fn (ReportDocument $report): bool => in_array('Age (years)', $report->columns, true))
        ->call('applyPreset', 'missing')->assertSet('filters.missing', 'ANY')
        ->assertSet('generated', [])->assertViewHas('document', null);
    expect($screen->get('columns'))->toContain('missing', 'email', 'mobile');
    $screen->call('applyPreset', 'directory')->assertSet('filters.missing', 'ALL');
    expect($screen->get('columns'))->toContain('employee', 'department', 'unit', 'position', 'worksite');
});

it('invalidates changed employee filters and columns before generating only the matching scoped facts', function () {
    reportsBuilderActor();
    reportsBuilderEmployee('Active');
    reportsBuilderEmployee('Inactive', ['is_active' => false]);
    $screen = Livewire::test(Index::class)->set('columns', ['employee_code', 'employee', 'active'])
        ->call('generate')->assertHasNoErrors()
        ->assertViewHas('document', fn (ReportDocument $report): bool => count($report->rows) === 2)
        ->set('filters.active', 'INACTIVE')->assertSet('generated', [])->assertViewHas('document', null)
        ->call('generate')->assertHasNoErrors()
        ->assertViewHas('document', fn (ReportDocument $report): bool => $report->rows === [['Inactive', 'Builder Inactive', 'Inactive']])
        ->set('columns', ['employee', 'active'])->assertSet('generated', [])->assertViewHas('document', null)
        ->call('generate')->assertHasNoErrors()
        ->assertViewHas('document', fn (ReportDocument $report): bool => $report->columns === ['Employee', 'Active Status']);
    $screen->set('columns', [])->call('generate')->assertHasErrors('columns')->assertSet('generated', [])->assertViewHas('document', null);
});

it('regenerates a sorted report and exports its full generated query through the HTTP middleware', function () {
    reportsBuilderActor();
    reportsBuilderEmployee('Alpha');
    reportsBuilderEmployee('Zebra');
    reportsBuilderEmployee('Outside', ['is_active' => false]);
    $screen = Livewire::test(Index::class)
        ->set('filters.search', '   ')
        ->set('filters.active', 'ACTIVE')->set('columns', ['employee_code', 'employee', 'active'])
        ->call('generate')->assertHasNoErrors()
        ->call('sortBy', 'employee')->assertSet('sortDir', 'desc')->assertHasNoErrors()
        ->assertSet('generated.sort', 'employee')->assertSet('generated.direction', 'desc')
        ->assertViewHas('document', fn (ReportDocument $report): bool => array_column($report->rows, 0) === ['Zebra', 'Alpha']);
    $query = $screen->get('generated');
    expect($query)->not->toHaveKeys(['f_search', 'f_ageMin', 'employees']);
    $response = $this->get(route('reports.export', $query))->assertOk()->assertDownload();
    expect(reportsBuilderCsv($response->streamedContent()))->toBe([
        ['ID', 'Employee', 'Active Status'],
        ['Zebra', 'Builder Zebra', 'Active'],
        ['Alpha', 'Builder Alpha', 'Active'],
    ]);
    $screen->call('sortBy', 'unrecognized')->assertSet('generated', $query)->assertSet('sortKey', 'employee');
});

it('keeps generated coverage across page and page-size changes while exporting every matching row', function () {
    reportsBuilderActor();
    for ($i = 1; $i <= 21; $i++) {
        reportsBuilderEmployee('Page-'.str_pad((string) $i, 2, '0', STR_PAD_LEFT));
    }
    $screen = Livewire::test(Index::class)->set('columns', ['employee_code', 'employee'])
        ->call('generate')->assertHasNoErrors()
        ->assertViewHas('previewRows', fn (LengthAwarePaginator $page): bool => $page->total() === 21 && $page->count() === 20);
    $generated = $screen->get('generated');
    $screen->call('nextPage')->assertSet('generated', $generated)
        ->assertViewHas('previewRows', fn (LengthAwarePaginator $page): bool => $page->currentPage() === 2 && $page->count() === 1)
        ->set('perPage', 50)->assertSet('generated', $generated)
        ->assertViewHas('previewRows', fn (LengthAwarePaginator $page): bool => $page->currentPage() === 1 && $page->count() === 21)
        ->set('perPage', 999)->assertSet('perPage', 20)->assertSet('generated', $generated);
    $csv = $this->get(route('reports.export', $generated))->assertOk()->streamedContent();
    expect(reportsBuilderCsv($csv))->toHaveCount(22);
});

it('resets report-specific controls when selecting another report and restores its starting filters', function () {
    reportsBuilderActor();
    $employee = reportsBuilderEmployee('Reset');
    $screen = Livewire::test(Index::class);
    $screen->set('employeeIds', [$employee->id])
        ->set('filters.active', 'INACTIVE')->set('columns', ['employee', 'age'])->call('generate')
        ->call('selectReport', 'attendance-reports')->assertSet('employeeIds', [])->assertSet('deductionCodes', [])
        ->assertSet('columns', [])->assertSet('filters', ['search' => '', 'issue' => 'all', 'minimum' => '0', 'limit' => 'all'])
        ->assertSet('sortKey', 'late_minutes')->assertSet('sortDir', 'desc')->assertSet('view', 'summary')
        ->assertSet('generated', [])->assertViewHas('document', null);
    $screen->set('employeeIds', [$employee->id])->call('applyPreset', 'undertime')
        ->assertSet('filters.issue', 'undertime')->assertSet('sortKey', 'undertime_minutes')->assertSet('filters.minimum', '1')
        ->call('resetFilters')->assertSet('employeeIds', [])->assertSet('filters.issue', 'all')->assertSet('filters.minimum', '0')
        ->assertSet('sortKey', 'late_minutes')->call('selectReport', 'employee-list')->assertSet('sortKey', 'employee');
    expect($screen->get('filters'))->toHaveKeys(['active', 'department', 'sex', 'ageMin', 'missing'])
        ->not->toHaveKey('issue');
    expect($screen->get('columns'))->toContain('employee', 'department', 'position');
});

it('switches from attendance rankings to complete individual sheets without hidden ranking filters', function () {
    $this->travelTo(new DateTimeImmutable('2026-10-02T12:00:00+08:00'));
    reportsBuilderActor();
    $employee = reportsBuilderEmployee('Attendance');
    $screen = Livewire::test(Index::class)->call('selectReport', 'attendance-reports')
        ->set('employeeIds', [$employee->id])->set('from', '2026-09-28')->set('to', '2026-09-29')
        ->call('applyPreset', 'absences')->assertSet('view', 'summary')->assertSet('sortKey', 'absences')
        ->assertSee('Search selected employees')->call('generate')->assertHasNoErrors()
        ->assertViewHas('document', fn (ReportDocument $report): bool => $report->sections === [] && count($report->rows) === 1)
        ->set('filters.search', 'Nobody matches this')->set('filters.minimum', '999')->set('filters.limit', '10')
        ->set('view', 'details')->assertSet('generated', [])->assertViewHas('document', null)
        ->assertDontSee('Search selected employees')->assertDontSee('Rank attendance by')
        ->call('generate')->assertHasNoErrors()
        ->assertViewHas('document', fn (ReportDocument $report): bool => count($report->sections) === 1 && count($report->rows) === 2)
        ->assertDontSee('Export CSV');
    $criteria = ReportCriteria::from($screen->get('generated'));
    expect($criteria->view)->toBe('details')->and($criteria->filters)->toBe([])
        ->and($criteria->sort)->toBe('')->and($criteria->direction)->toBe('asc');
    $screen->call('applyPreset', 'frequent')->assertSet('view', 'summary')->assertSet('sortKey', 'late_days')
        ->assertSet('filters.issue', 'late')->assertSet('filters.minimum', '1')->assertSet('generated', []);
});

it('reauthorizes report builder actions after the source grant is revoked', function (string $action, array $arguments) {
    $user = reportsBuilderActor();
    $screen = Livewire::test(Index::class);
    RoleModuleGrant::query()->where('role_id', $user->role_id)->where('module', ModuleCode::EMPLOYEES)->delete();
    $this->actingAs($user->fresh());
    app()->forgetInstance(Access::class);
    $screen->call($action, ...$arguments)->assertForbidden();
})->with([
    'reset filters' => ['resetFilters', []],
    'employee preset' => ['applyPreset', ['profile']],
    'result sorting' => ['sortBy', ['employee']],
]);

it('refuses unknown builder presets before replacing valid filters', function () {
    reportsBuilderActor();
    Livewire::test(Index::class)->set('filters.active', 'INACTIVE')
        ->call('applyPreset', 'unknown-preset')->assertStatus(422);
});

it('builds workforce analysis with the same cohort filters and exports the complete group summary', function () {
    reportsBuilderActor();
    reportsBuilderEmployee('Analysis-A');
    reportsBuilderEmployee('Analysis-B', ['sex' => 'Male']);
    reportsBuilderEmployee('Analysis-Outside', ['is_active' => false]);
    $screen = Livewire::test(Index::class)->call('applyPreset', 'demographics')
        ->assertSet('view', 'analysis')->assertSet('analysisFilters.analysis', 'demographics')
        ->set('filters.active', 'ACTIVE')->set('analysisFilters.groupBy', 'sex')
        ->set('analysisFilters.crossTab', 'none')->set('columns', [])
        ->set('from', '2026-01-01')->set('to', '2026-09-30')
        ->call('generate')->assertHasNoErrors()
        ->assertSee('Supporting analysis')->assertSee('Export Group Summary CSV')
        ->assertViewHas('document', fn (ReportDocument $report): bool => count($report->rows) === 2 && $report->sections !== [] && $report->charts !== []);
    $criteria = ReportCriteria::from($screen->get('generated'));
    expect($criteria->from)->toBe('2026-01-01')->and($criteria->columns)->toBe([])
        ->and($criteria->filters['active'])->toBe('ACTIVE')->and($criteria->sort)->toBe('');
    $csv = reportsBuilderCsv($this->get(route('reports.export', $screen->get('generated')))->assertOk()->streamedContent());
    expect($csv)->toHaveCount(3);
    $screen->set('analysisFilters.groupBy', 'department')->assertSet('generated', [])->assertViewHas('document', null);
});

it('keeps ranking filters out of the attendance analysis cohort and returns to rankings explicitly', function () {
    $this->travelTo(new DateTimeImmutable('2026-10-02T12:00:00+08:00'));
    reportsBuilderActor();
    $employee = reportsBuilderEmployee('Analysis-attendance');
    $screen = Livewire::test(Index::class)->call('selectReport', 'attendance-reports')
        ->set('employeeIds', [$employee->id])->set('from', '2026-09-28')->set('to', '2026-09-29')
        ->call('applyPreset', 'late')->set('filters.search', 'Nobody matches')->set('filters.minimum', '999')
        ->call('applyPreset', 'attendance-analysis')->assertSet('view', 'analysis')
        ->call('generate')->assertHasNoErrors()->assertDontSee('Export CSV')
        ->assertViewHas('document', fn (ReportDocument $report): bool => $report->sections !== []);
    $criteria = ReportCriteria::from($screen->get('generated'));
    expect($criteria->filters)->toBe(['groupBy' => 'department', 'compare' => 'none', 'threshold' => '3'])
        ->and($criteria->sort)->toBe('')->and($criteria->columns)->toBe([]);
    $screen->call('applyPreset', 'frequent')->assertSet('view', 'summary')->assertSet('generated', [])
        ->assertSee('Rank attendance by')->assertDontSee('Attendance comparison period');
});

it('renders an empty workforce analysis without inventing chart values or failing generation', function () {
    reportsBuilderActor();
    reportsBuilderEmployee('Not-selected');
    Livewire::test(Index::class)->call('applyPreset', 'workforce')
        ->set('filters.search', 'Nobody-matches-this-cohort')->call('generate')->assertHasNoErrors()
        ->assertSee('No qualifying data for this chart.')
        ->assertViewHas('document', fn (ReportDocument $report): bool => $report->rows === [] && $report->charts[0]['points'] === []);
});
