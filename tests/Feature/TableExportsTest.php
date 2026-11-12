<?php

declare(strict_types=1);

use App\Domain\Enums\AuditEvent;
use App\Domain\Enums\ModuleCode;
use App\Livewire\Edtr\EdtrPage;
use App\Models\AttendanceLog;
use App\Models\EmployeeDocument;
use App\Models\EmployeeProfile;
use App\Models\LeaveTravelRequest;
use App\Models\PayrollRun;
use App\Models\Project;
use App\Models\ProjectTask;
use App\Models\Role;
use App\Models\RoleModuleGrant;
use App\Models\User;
use App\Modules\Workforce\Application\Queries\EmployeeDocuments;
use App\Platform\Transfer\TableTransfers;
use App\Support\Access;
use App\Support\AuditService;
use App\Support\Csv;
use App\Support\ManilaClock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Livewire\Livewire;

/**
 * The export-only tables — audit trail, leave requests, payroll run lists,
 * accounts and roles, the organisation punch feed, My Tasks and a record's
 * documents — download exactly what their table shows to the actor looking
 * at it: the same scope (self-service stays pinned to the actor's own rows
 * even for all_data), the same filters, and nothing the table leaves out
 * (no leave reason, no credentials, no files). The Daily Time Record has no
 * CSV, and My Portal exposes no table exports.
 */

/** @param list<string> $modules */
function texpActor(array $modules, bool $allData, ?string $employeeId = null): User
{
    $role = new Role;
    $role->forceFill(['name' => 'Exports '.Str::random(6), 'all_data' => $allData, 'is_system' => false])->save();
    foreach ($modules as $module) {
        $grant = new RoleModuleGrant;
        $grant->forceFill(['role_id' => $role->getKey(), 'module' => $module])->save();
    }
    $user = User::factory()->create(['role_id' => $role->getKey(), 'employee_id' => $employeeId]);
    test()->actingAs($user);
    app()->forgetInstance(Access::class);

    return $user;
}

function texpEmployee(string $code, string $first): EmployeeProfile
{
    $employee = new EmployeeProfile;
    $employee->forceFill(['employee_code' => $code, 'first_name' => $first, 'last_name' => 'Cruz', 'employment_status' => 'Regular', 'date_hired' => '2024-01-01', 'is_active' => true])->save();

    return $employee;
}

function texpPunch(EmployeeProfile $employee, string $type, string $manila): void
{
    $punch = new AttendanceLog;
    $punch->forceFill([
        'employee_id' => (string) $employee->getKey(), 'site_id' => null, 'log_type' => $type, 'source' => 'WEB_GPS',
        'is_geofenced' => true, 'is_anomaly' => false, 'logged_at' => ManilaClock::instant($manila)->utc(),
    ])->save();
}

/** @return list<list<string>> every line of the download, header first */
function texpCsv(string $url): array
{
    $csv = test()->get($url)->assertOk()->streamedContent();
    expect(str_starts_with($csv, Csv::BOM))->toBeTrue();

    return array_map(static fn (string $line): array => array_map('strval', str_getcsv($line, ',', '"', '')), explode("\r\n", substr($csv, strlen(Csv::BOM))));
}

it('exports the audit trail only to an all-data AUDIT holder, as the table shows it', function () {
    $admin = texpActor([ModuleCode::AUDIT], allData: true);
    AuditService::record(app(Access::class), AuditEvent::SITE_CREATED, 'work_sites', 'x', 'Makati added');
    AuditService::record(app(Access::class), AuditEvent::HOLIDAY_CREATED, 'holidays', 'y', 'Rizal Day added');

    $lines = texpCsv('/audit/export?action='.rawurlencode('Worksite added'));
    expect($lines[0])->toBe(['Timestamp', 'Actor', 'Action', 'Entity', 'Details'])
        ->and(count($lines))->toBe(2)
        ->and($lines[1][1])->toBe($admin->name)
        ->and($lines[1][2])->toBe('Worksite added')
        ->and($lines[1][3])->toBe('Worksite')
        ->and($lines[1][4])->toBe('Makati added');

    // A link built from the stored code still narrows the same way.
    expect(texpCsv('/audit/export?action=SITE_CREATED&entity=work_sites'))->toBe($lines);

    texpActor([ModuleCode::AUDIT], allData: false);
    expect(texpCsv('/audit/export'))->toHaveCount(1);
});

it('exports leave requests without the reason only the review dialog shows', function () {
    $ana = texpEmployee('11', 'Ana');
    $request = new LeaveTravelRequest;
    $request->forceFill(['code' => '5', 'employee_id' => $ana->id, 'request_type' => 'SICK', 'day_type' => 'WHOLE_DAY', 'start_date' => '2026-08-10', 'end_date' => '2026-08-11', 'reason' => 'Private matter', 'status' => 'PENDING', 'created_at' => '2026-08-03 17:00:00.000'])->save();
    texpActor([ModuleCode::LEAVES], allData: true);

    $lines = texpCsv('/leaves/export');
    expect($lines[0])->toBe(['ID', 'Employee', 'Position', 'Date filed', 'Start date', 'End date', 'Description', 'Day type', 'Status', 'Pay', 'Cancelled dates'])
        ->and($lines[1][0])->toBe('5')
        // Filed at 17:00 UTC on Aug 3 is 01:00 on Aug 4 in Manila.
        ->and($lines[1][3])->toBe('2026-08-04')
        ->and($lines[1][4])->toBe('2026-08-10')
        ->and(implode(',', array_merge(...$lines)))->not->toContain('Private matter');
});

it('keeps each payroll run list to its own kind', function () {
    foreach ([['1', 'August A', 'REGULAR', '2026-08-01', '2026-08-15'], ['2', '13th month 2026', 'THIRTEENTH_MONTH', '2026-01-01', '2026-12-31']] as [$code, $name, $kind, $from, $to]) {
        $run = new PayrollRun;
        $run->forceFill(['code' => $code, 'name' => $name, 'kind' => $kind, 'period_start' => $from, 'period_end' => $to, 'status' => 'DRAFT'])->save();
    }
    texpActor([ModuleCode::PAYROLL], allData: true);

    expect(array_column(array_slice(texpCsv('/payroll/export'), 1), 1))->toBe(['August A'])
        ->and(array_column(array_slice(texpCsv('/payroll/thirteenth-month/export'), 1), 1))->toBe(['13th month 2026']);
});

it('exports accounts without credential material, and roles as the grant matrix', function () {
    $admin = texpActor([ModuleCode::USERS], allData: true);

    $accounts = texpCsv('/users/export');
    expect($accounts[0])->toBe(['Name', 'Email', 'Role', 'Linked employee', 'Active'])
        ->and(implode(',', array_merge(...$accounts)))->not->toContain((string) $admin->getAuthPassword());

    $roles = texpCsv('/users/roles/export');
    $mine = array_values(array_filter($roles, static fn (array $row): bool => str_starts_with($row[0], 'Exports ')))[0];
    $usersColumn = array_search('User Management', $roles[0], true);
    expect(array_slice($roles[0], 0, 2))->toBe(['Role', 'Scope'])
        ->and($mine[1])->toBe('All employees')
        ->and($usersColumn === false ? null : $mine[$usersColumn])->toBe('Yes');
});

it('exports the organisation feed on the DTR screen', function () {
    $own = texpEmployee('21', 'Mine');
    $other = texpEmployee('22', 'Theirs');
    texpPunch($own, 'TIME_IN', '2026-09-01T08:00:00');
    texpPunch($other, 'TIME_IN', '2026-09-01T08:05:00');
    texpActor([ModuleCode::EDTR], allData: true, employeeId: $own->id);

    $feed = texpCsv('/edtr/feed/export');
    expect($feed[0])->toBe(['ID', 'Employee', 'Timestamp', 'Site', 'Type', 'Source', 'Distance (m)'])
        ->and(array_column(array_slice($feed, 1), 0))->toBe(['22', '21']);
});

it('has no Daily Time Record CSV: the export route is gone and the tab offers none', function () {
    $employee = texpEmployee('35', 'Ana');
    texpActor([ModuleCode::EDTR], allData: true);

    expect(Route::has('edtr.dtr.export'))->toBeFalse();
    test()->get('/edtr/dtr/export?employee='.$employee->id.'&start=2026-09-01&end=2026-09-07')->assertNotFound();

    // The record header stands, with no export button beside the default shift.
    Livewire::test(EdtrPage::class)->set('tab', 'dtr')
        ->call('employeePicked', 'dtr-employee', $employee->id)
        ->set('start', '2026-09-01')->set('end', '2026-09-07')
        ->assertSee('Default shift')
        ->assertDontSeeHtml('/edtr/dtr/export');
});

it('exports only my own tasks, whatever my scope', function () {
    $own = texpEmployee('41', 'Mine');
    $other = texpEmployee('42', 'Theirs');
    $project = new Project;
    $project->forceFill(['code' => 'PRJ-900', 'name' => 'Shared', 'status' => 'ACTIVE', 'start_date' => '2026-01-01'])->save();
    foreach ([['TSK-901', $own], ['TSK-902', $other]] as [$code, $employee]) {
        $task = new ProjectTask;
        $task->forceFill(['code' => $code, 'project_id' => $project->id, 'title' => $code, 'status' => 'PENDING', 'priority' => 'NORMAL'])->save();
        $task->syncAssignees([(string) $employee->getKey()], null);
    }
    texpActor([ModuleCode::TASKS, ModuleCode::PROJECTS], allData: true, employeeId: $own->id);

    expect(array_column(array_slice(texpCsv('/my-tasks/export'), 1), 0))->toBe(['TSK-901']);
});

it('exports document metadata to management including their own record without exposing file paths', function () {
    $ana = texpEmployee('51', 'Ana');
    $ben = texpEmployee('52', 'Ben');
    EmployeeDocument::query()->create(['employee_id' => $ana->id, 'name' => 'Contract', 'category' => 'Contract', 'file_name' => 'contract.pdf', 'mime' => 'application/pdf', 'size' => 2048, 'path' => 'employee-documents/contract.pdf']);
    texpActor([ModuleCode::EMPLOYEES], allData: true);

    $lines = texpCsv('/employees/'.$ana->id.'/documents/export');
    expect($lines[0])->toBe(['ID', 'Document', 'File', 'Size', 'Category', 'Expires'])
        ->and($lines[1][1])->toBe('Contract')
        ->and(implode(',', $lines[1]))->not->toContain('employee-documents/');

    texpActor([ModuleCode::EMPLOYEES], allData: true, employeeId: $ana->id);
    expect(texpCsv('/employees/'.$ana->id.'/documents/export'))->toBe($lines);

    foreach ([[false, $ben->id], [false, $ana->id]] as [$allData, $employeeId]) {
        texpActor([ModuleCode::EMPLOYEES], allData: $allData, employeeId: $employeeId);
        test()->get('/employees/'.$ana->id.'/documents/export')
            ->assertForbidden()
            ->assertHeaderMissing('Content-Disposition')
            ->assertDontSee('contract.pdf')
            ->assertDontSee('employee-documents/');
    }
});

it('exports every matching document category using the same employee scope and legacy labels as the table', function () {
    $employee = texpEmployee('53', 'Filtered');
    $other = texpEmployee('54', 'Other');
    for ($index = 1; $index <= 103; $index++) {
        $document = new EmployeeDocument;
        $document->forceFill([
            'employee_id' => $index === 103 ? $other->id : $employee->id,
            'name' => 'Document '.$index, 'category' => $index === 102 ? 'Medical' : ($index % 2 === 0 ? 'CONTRACT' : 'Contract'),
            'file_name' => 'document-'.$index.'.pdf', 'mime' => 'application/pdf', 'size' => 2048,
            'path' => 'employee-documents/document-'.$index.'.pdf',
            'created_at' => CarbonImmutable::parse('2026-10-01T00:00:00Z')->addSeconds($index)->utc(),
        ])->save();
    }
    texpActor([ModuleCode::EMPLOYEES], allData: true);
    $query = app(EmployeeDocuments::class);
    $actor = app(Access::class);
    $firstPage = $query->page($actor, $employee->id, 1, 100, 'Contract');
    $lastPage = $query->page($actor, $employee->id, 2, 100, 'Contract');
    $expected = [...$firstPage->pluck('name')->all(), ...$lastPage->pluck('name')->all()];
    $url = route('employees.documents.export', ['employee' => $employee->id, 'category' => 'Contract']);
    $lines = texpCsv($url);
    $rows = array_slice($lines, 1);

    expect($firstPage->total())->toBe(101)->and($lastPage->count())->toBe(1)
        ->and(array_column($rows, 1))->toBe($expected)
        ->and(array_values(array_unique(array_column($rows, 4))))->toBe(['Contract'])
        ->and(implode(',', array_merge(...$rows)))->not->toContain('employee-documents/');
    expect(texpCsv(route('employees.documents.export', ['employee' => $employee->id, 'category' => 'Missing category'])))
        ->toBe([$lines[0]]);

    texpActor([ModuleCode::EMPLOYEES], allData: false, employeeId: $employee->id);
    $this->get($url)->assertForbidden()->assertHeaderMissing('Content-Disposition')->assertDontSee('document-');
});

it('has no My Portal table export routes or registered exporters', function () {
    $employee = texpEmployee('61', 'Mine');
    texpActor([ModuleCode::PORTAL, ModuleCode::LEAVES, ModuleCode::PAYROLL, ModuleCode::INVENTORY], allData: true, employeeId: $employee->id);

    foreach (['punches', 'credits', 'ledger', 'requests', 'payslips', 'inventory'] as $table) {
        expect(Route::has('portal.'.$table.'.export'))->toBeFalse()
            ->and(app(TableTransfers::class)->exports('portal.'.$table))->toBeFalse();
        $this->get('/portal/'.$table.'/export')->assertNotFound();
    }
});
