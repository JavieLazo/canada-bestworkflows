<?php

declare(strict_types=1);

use App\Domain\Enums\ModuleCode;
use App\Livewire\Records\SitesPage;
use App\Livewire\Records\Support\SitesExport;
use App\Livewire\Roster\RosterPage;
use App\Models\AuditLog;
use App\Models\EmployeeProfile;
use App\Models\Project;
use App\Models\Role;
use App\Models\RoleModuleGrant;
use App\Models\RosterDayOverride;
use App\Models\RosterLocation;
use App\Models\User;
use App\Models\WorkSite;
use App\Modules\Organization\Application\Commands\DeleteSite;
use App\Modules\Organization\Application\Commands\SetSiteStatus;
use App\Modules\Organization\Contracts\OrganizationReferences;
use App\Modules\Timekeeping\Application\Commands\SaveRosterDate;
use App\Modules\Timekeeping\Application\Commands\SaveRosterLocations;
use App\Modules\Timekeeping\Infrastructure\ShiftRoster;
use App\Modules\Workforce\Application\EmployeeRules;
use App\Modules\WorkManagement\Application\Commands\CreateProject;
use App\Modules\WorkManagement\Application\Commands\UpdateProject;
use App\Modules\WorkManagement\Application\ProjectDraft;
use App\Support\Access;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Livewire\Livewire;

/**
 * A work site history names is retired, not deleted. INACTIVE withholds the
 * site from NEW assignments only: every record and existing assignment
 * keeps it, deactivating changes no schedule, and only all_data + RECORDS
 * may flip the flag.
 */
const SITESTAT_INACTIVE = 'That work site is inactive, so it cannot be chosen for a new assignment. Choose an active site, or make it active again in Site Settings.';

/** @param  list<string>  $modules */
function sitestatActor(array $modules, bool $allData = true): Access
{
    $role = new Role;
    $role->forceFill(['name' => 'Sites '.Str::random(8), 'all_data' => $allData, 'is_system' => false])->save();
    foreach ($modules as $module) {
        $grant = new RoleModuleGrant;
        $grant->forceFill(['role_id' => $role->id, 'module' => $module])->save();
    }
    $user = User::factory()->create(['role_id' => $role->id]);
    test()->actingAs($user);
    app()->forgetInstance(Access::class);

    return Access::forActor($user);
}

function sitestatSite(string $name, bool $active = true): WorkSite
{
    $site = WorkSite::query()->create(['name' => $name, 'latitude' => 14.5995, 'longitude' => 120.9842, 'radius_meters' => 150, 'geofence_exempt' => false]);
    if (! $active) {
        $site->is_active = false;
        $site->save();
    }

    return $site;
}

function sitestatEmployee(?WorkSite $site = null): EmployeeProfile
{
    return EmployeeProfile::query()->create(['employee_code' => 'ST-'.Str::random(8), 'first_name' => 'Sita', 'last_name' => 'Reyes',
        'employment_status' => 'Regular', 'date_hired' => '2024-01-01', 'is_active' => true, 'work_site_id' => $site?->id]);
}

beforeEach(function () {
    $this->travelTo(new DateTimeImmutable('2026-10-05T06:00:00+08:00'));
});

/* |---| The flag |---| */

it('keeps every existing site active and adds the column retry-safely', function () {
    $site = sitestatSite('Makati Head Office');
    $migration = require base_path('database/migrations/2026_09_30_0009_add_is_active_to_work_sites_table.php');
    try {
        $migration->down();
        $migration->down();
        expect(Schema::hasColumn('work_sites', 'is_active'))->toBeFalse();
        $migration->up();
        $migration->up();
        expect(Schema::hasColumn('work_sites', 'is_active'))->toBeTrue()
            ->and($site->fresh()?->is_active)->toBeTrue();
    } finally {
        $migration->up();
    }
});

it('lets only all_data RECORDS flip a site, audits each change once, and changes nothing else', function () {
    $site = sitestatSite('Quezon Annex');
    $employee = sitestatEmployee($site);
    RosterLocation::query()->create(['employee_id' => $employee->id, 'weekday' => 3, 'kind' => 'ONSITE', 'site_id' => $site->id]);
    RosterDayOverride::query()->create(['employee_id' => $employee->id, 'date' => '2026-10-09', 'location_kind' => 'ONSITE', 'site_id' => $site->id]);
    $project = new Project;
    $project->forceFill(['code' => 'PRJ-ST01', 'name' => 'Annex fit-out', 'status' => 'ACTIVE', 'start_date' => '2026-01-01', 'site_id' => $site->id])->save();

    foreach ([sitestatActor([ModuleCode::RECORDS], allData: false), sitestatActor([ModuleCode::ROSTER])] as $denied) {
        expect(fn () => app(SetSiteStatus::class)->handle($denied, $site->id, false))->toThrow(RuntimeException::class, 'Your role cannot manage organization-wide data.');
    }
    expect($site->fresh()?->is_active)->toBeTrue()->and(AuditLog::query()->count())->toBe(0);

    $records = sitestatActor([ModuleCode::RECORDS]);
    expect(app(SetSiteStatus::class)->handle($records, $site->id, false))->toBe('Quezon Annex is now inactive. Existing assignments keep it.')
        ->and(app(SetSiteStatus::class)->handle($records, $site->id, false))->toBe('Quezon Annex is already inactive.')
        ->and($site->fresh()?->is_active)->toBeFalse()
        ->and(AuditLog::query()->where('action', 'SITE_DEACTIVATED')->sole()->details)->toBe('Quezon Annex made inactive · existing assignments kept');

    // Nothing that named the site moved.
    expect($employee->fresh()?->work_site_id)->toBe($site->id)
        ->and(RosterLocation::query()->sole()->site_id)->toBe($site->id)
        ->and(RosterDayOverride::query()->sole()->status)->toBe('ACTIVE')
        ->and($project->fresh()?->site_id)->toBe($site->id)
        ->and(app(ShiftRoster::class)->locationForDate($employee->id, '2026-10-07')?->siteId)->toBe($site->id)
        ->and(app(ShiftRoster::class)->locationForDate($employee->id, '2026-10-09')?->siteId)->toBe($site->id);

    expect(app(SetSiteStatus::class)->handle($records, $site->id, true))->toBe('Quezon Annex is active again.')
        ->and(AuditLog::query()->where('action', 'SITE_REACTIVATED')->count())->toBe(1)
        ->and(fn () => app(SetSiteStatus::class)->handle($records, 'missing', false))->toThrow(RuntimeException::class, 'That work site no longer exists.');
});

/* |---| New assignments |---| */

it('answers assignable for an active site, and for an inactive one only where it is already named', function () {
    $active = sitestatSite('Makati Head Office');
    $retired = sitestatSite('Old Warehouse', active: false);
    $references = app(OrganizationReferences::class);

    expect($references->siteAssignable($active->id))->toBeTrue()
        ->and($references->siteAssignable($retired->id))->toBeFalse()
        ->and($references->siteAssignable($retired->id, $retired->id))->toBeTrue()
        ->and($references->siteAssignable($retired->id, $active->id))->toBeFalse()
        ->and($references->siteAssignable('missing', 'missing'))->toBeFalse()
        ->and($references->siteExists($retired->id))->toBeTrue();
});

it('refuses an inactive site for a changed weekday or date, and keeps it where the roster already names it', function () {
    $active = sitestatSite('Makati Head Office');
    $retired = sitestatSite('Old Warehouse');
    $employee = sitestatEmployee($active);
    $actor = sitestatActor([ModuleCode::ROSTER, ModuleCode::RECORDS]);
    app(SaveRosterLocations::class)->handle($actor, $employee->id, [3 => $retired->id]);
    app(SaveRosterDate::class)->handle($actor, $employee->id, '2026-10-09', 'DEFAULT', $retired->id);
    app(SetSiteStatus::class)->handle($actor, $retired->id, false);
    $rows = [RosterLocation::query()->count(), RosterDayOverride::query()->count()];

    expect(fn () => app(SaveRosterLocations::class)->handle($actor, $employee->id, [4 => $retired->id]))->toThrow(RuntimeException::class, SITESTAT_INACTIVE)
        ->and(fn () => app(SaveRosterDate::class)->handle($actor, $employee->id, '2026-10-12', 'DEFAULT', $retired->id))->toThrow(RuntimeException::class, SITESTAT_INACTIVE);
    expect([RosterLocation::query()->count(), RosterDayOverride::query()->count()])->toBe($rows);

    // Wednesday keeps the retired site while Thursday changes; the date keeps
    // its place while its shift changes.
    app(SaveRosterLocations::class)->handle($actor, $employee->id, [3 => $retired->id, 4 => 'WFH']);
    app(SaveRosterDate::class)->handle($actor, $employee->id, '2026-10-09', 'REST', $retired->id);
    expect(app(ShiftRoster::class)->locationForDate($employee->id, '2026-10-07')?->siteId)->toBe($retired->id)
        ->and(app(ShiftRoster::class)->locationForDate($employee->id, '2026-10-08')?->kind)->toBe('WFH')
        ->and(app(ShiftRoster::class)->locationForDate($employee->id, '2026-10-09')?->siteId)->toBe($retired->id);
});

it('refuses an inactive Assigned Worksite on a changed record and keeps the one a record already has', function () {
    $active = sitestatSite('Makati Head Office');
    $retired = sitestatSite('Old Warehouse', active: false);
    $other = sitestatSite('Closed Annex', active: false);
    $employee = sitestatEmployee($retired);

    expect(EmployeeRules::updating($employee, ['work_site_id' => $retired->id]))->toBeNull()
        ->and(EmployeeRules::updating($employee, ['work_site_id' => $active->id]))->toBeNull()
        ->and(EmployeeRules::updating($employee, ['work_site_id' => $other->id]))->toBe(SITESTAT_INACTIVE)
        ->and(EmployeeRules::updating(sitestatEmployee($active), ['work_site_id' => $retired->id]))->toBe(SITESTAT_INACTIVE);
});

it('refuses an inactive site on a new project and keeps the one a project already names', function () {
    $active = sitestatSite('Makati Head Office');
    $retired = sitestatSite('Old Warehouse', active: false);
    $actor = sitestatActor([ModuleCode::PROJECTS]);

    expect(fn () => app(CreateProject::class)->handle($actor, new ProjectDraft('Warehouse move', '', $retired->id, null, '2026-10-05', '', 'NONE')))
        ->toThrow(RuntimeException::class, SITESTAT_INACTIVE);
    expect(Project::query()->count())->toBe(0);

    $project = new Project;
    $project->forceFill(['code' => 'PRJ-ST02', 'name' => 'Warehouse audit', 'status' => 'PLANNING', 'start_date' => '2026-01-01', 'site_id' => $retired->id])->save();
    app(UpdateProject::class)->handle($actor, $project->id, new ProjectDraft('Warehouse audit 2', '', $retired->id, 'PLANNING', '2026-01-01', '', 'NONE'));
    expect($project->fresh()?->name)->toBe('Warehouse audit 2')->and($project->fresh()?->site_id)->toBe($retired->id);

    $moved = new Project;
    $moved->forceFill(['code' => 'PRJ-ST03', 'name' => 'Head office audit', 'status' => 'PLANNING', 'start_date' => '2026-01-01', 'site_id' => $active->id])->save();
    expect(fn () => app(UpdateProject::class)->handle($actor, $moved->id, new ProjectDraft('Head office audit', '', $retired->id, 'PLANNING', '2026-01-01', '', 'NONE')))
        ->toThrow(RuntimeException::class, SITESTAT_INACTIVE);
    expect($moved->fresh()?->site_id)->toBe($active->id);
});

/* |---| Screens |---| */

it('shows the status, filters by it and flips it from Site Settings, and points a refused delete at Make inactive', function () {
    $used = sitestatSite('Quezon Annex');
    // Not the name the Add dialog shows as its placeholder.
    sitestatSite('Pasig Depot');
    $employee = sitestatEmployee();
    RosterLocation::query()->create(['employee_id' => $employee->id, 'weekday' => 3, 'kind' => 'ONSITE', 'site_id' => $used->id]);
    $actor = sitestatActor([ModuleCode::RECORDS]);

    expect(fn () => app(DeleteSite::class)->handle($actor, $used->id))
        ->toThrow(RuntimeException::class, 'This work site is in employee schedule history and must remain. Make it inactive instead.');

    Livewire::test(SitesPage::class)
        ->assertSee('Make inactive')
        ->call('confirmStatus', $used->id)
        ->assertSet('statusOpen', true)
        ->assertSee('Make Quezon Annex inactive?')
        ->call('statusConfirmed')
        ->assertSet('statusOpen', false)
        ->assertSet('statusId', null)
        ->assertSee('Make active')
        ->set('status', 'INACTIVE')
        ->assertSee('Quezon Annex')
        ->assertDontSee('Pasig Depot')
        ->set('status', 'ACTIVE')
        ->assertDontSee('Quezon Annex');
    expect($used->fresh()?->is_active)->toBeFalse();

    $export = (new SitesExport)->export($actor, ['status' => 'INACTIVE']);
    $csv = [...($export->rows)()];
    expect($export->headers)->toBe(['ID', 'Site', 'Latitude', 'Longitude', 'Radius (m)', 'Geofence', 'Punches', 'Status'])
        ->and($csv)->toHaveCount(1)
        ->and($csv[0][1])->toBe('Quezon Annex')
        ->and($csv[0][7])->toBe('Inactive');
});

it('writes nothing when Site Settings flips a site without all_data', function () {
    $site = sitestatSite('Quezon Annex');
    sitestatActor([ModuleCode::RECORDS], allData: false);

    Livewire::test(SitesPage::class)->call('confirmStatus', $site->id)->call('statusConfirmed');

    expect($site->fresh()?->is_active)->toBeTrue()->and(AuditLog::query()->count())->toBe(0);
});

it('offers an inactive site in the roster dialogs only where the employee already has it', function () {
    $active = sitestatSite('Makati Head Office');
    $retired = sitestatSite('Old Warehouse');
    $employee = sitestatEmployee($active);
    $actor = sitestatActor([ModuleCode::ROSTER, ModuleCode::RECORDS]);
    app(SaveRosterLocations::class)->handle($actor, $employee->id, [3 => $retired->id]);
    app(SetSiteStatus::class)->handle($actor, $retired->id, false);

    $html = Livewire::test(RosterPage::class)->call('openLocations', $employee->id)->html();
    // Once: Wednesday's own select. The other six selects do not offer it.
    expect(substr_count($html, 'Old Warehouse (inactive)'))->toBe(1);

    Livewire::test(RosterPage::class)->call('openDateSchedule', $employee->id)
        ->assertDontSee('Old Warehouse (inactive)');
});
