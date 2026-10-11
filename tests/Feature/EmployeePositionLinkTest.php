<?php

declare(strict_types=1);

use App\Domain\Enums\ModuleCode;
use App\Livewire\Employees\EmployeeFields;
use App\Models\AuditLog;
use App\Models\EmployeeEmploymentHistory;
use App\Models\EmployeeProfile;
use App\Models\EmploymentStatusOption;
use App\Models\OrgNode;
use App\Models\Position;
use App\Models\Role;
use App\Models\RoleModuleGrant;
use App\Models\User;
use App\Models\WorkSite;
use App\Modules\Organization\Contracts\OrganizationReferences;
use App\Modules\Workforce\Application\Commands\CreateEmployee;
use App\Modules\Workforce\Application\Commands\UpdateEmployee;
use App\Modules\Workforce\Application\EmployeePosition;
use App\Modules\Workforce\Application\Queries\EmployeeRecords;
use App\Support\Access;
use Database\Seeders\EmployeeSeeder;
use Database\Seeders\ReferenceListSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * An employee record is linked to the Position list through ONE writer,
 * EmployeePosition, behind UpdateEmployee's Employee Details save and
 * CreateEmployee: the form sends a position id, blank or KEEP; the record
 * stores `position_id` and copies the position's CURRENT name into
 * `position`, re-read under the structure mutex — so the department and
 * unit a page shows are only narrowing drafts and decide nothing. A title
 * typed before the list existed is kept with KEEP (keep-current) and cannot
 * be chosen anew; blank clears; an unknown id is refused with nothing
 * written. Every view the record, the list and My Profile read carries the
 * position's department and unit, read in one batch.
 */
/** @param list<string> $modules */
function emplinkRole(array $modules, bool $allData): Role
{
    $role = Role::query()->create(['name' => 'Link '.implode('-', $modules).($allData ? ' all ' : ' ').Str::random(6), 'all_data' => $allData, 'is_system' => false]);

    foreach ($modules as $module) {
        RoleModuleGrant::query()->create(['role_id' => $role->id, 'module' => $module]);
    }

    return $role;
}

/** @param list<string> $modules */
function emplinkActor(array $modules, bool $allData, ?EmployeeProfile $employee = null): Access
{
    $user = User::factory()->create();
    $user->forceFill(['role_id' => emplinkRole($modules, $allData)->id, 'employee_id' => $employee?->id])->save();
    test()->actingAs($user->refresh());
    app()->forgetInstance(Access::class);

    return app(Access::class);
}

function emplinkNode(string $code, string $kind, string $name, ?string $parent = null, int $order = 0): OrgNode
{
    $node = new OrgNode;
    $node->forceFill(['code' => $code, 'kind' => $kind, 'name' => $name, 'parent_id' => $parent, 'sort_order' => $order])->save();

    return $node;
}

function emplinkPosition(string $code, string $name, ?string $node = null): Position
{
    $position = new Position;
    $position->forceFill(['code' => $code, 'name' => $name, 'org_node_id' => $node, 'sort_order' => 0])->save();

    return $position;
}

/** @return array{site: WorkSite, engineering: OrgNode, software: OrgNode, director: Position, developer: Position, driver: Position} */
function emplinkWorld(): array
{
    EmploymentStatusOption::query()->create(['code' => '1', 'name' => 'Regular']);
    EmploymentStatusOption::query()->create(['code' => '2', 'name' => 'Probationary']);
    $site = WorkSite::query()->create(['name' => 'Head Office', 'latitude' => 14.5995, 'longitude' => 120.9842, 'radius_meters' => 100, 'geofence_exempt' => false]);
    $engineering = emplinkNode('1', OrgNode::KIND_DEPARTMENT, 'Engineering Department');
    $software = emplinkNode('2', OrgNode::KIND_UNIT, 'Software Development', $engineering->id);

    return [
        'site' => $site,
        'engineering' => $engineering,
        'software' => $software,
        'director' => emplinkPosition('1', 'Engineering Director', $engineering->id),
        'developer' => emplinkPosition('2', 'Senior Developer', $software->id),
        'driver' => emplinkPosition('3', 'Driver'),
    ];
}

function emplinkEmployee(WorkSite $site, ?string $position = null, string $code = '10'): EmployeeProfile
{
    return EmployeeProfile::query()->create([
        'employee_code' => $code, 'first_name' => 'Ramon', 'last_name' => 'Aquino', 'position' => $position,
        'position_level' => 'Junior', 'employment_status' => 'Regular', 'date_hired' => '2024-01-15',
        'work_site_id' => $site->id, 'monthly_rate' => 20000,
    ]);
}

/** @param array<string, mixed> $changes */
function emplinkSave(Access $actor, EmployeeProfile $employee, array $changes): ?string
{
    return app(UpdateEmployee::class)->handle($actor, $employee->id, 'employment', $changes + EmployeeFields::toForm($employee->refresh()));
}

/** @return array<string, mixed> */
function emplinkHireForm(WorkSite $site, string $positionId, string $email = 'juan.link@company.com'): array
{
    return [
        'first_name' => 'Juan', 'last_name' => 'Dela Cruz', 'phone' => '0917 123 4567', 'email' => $email,
        'birth_date' => '1990-05-01', 'res_street' => 'Rizal St.', 'sex' => 'Male', 'civil_status' => 'Single',
        'emergency_name' => 'Maria Dela Cruz', 'emergency_relation' => 'Spouse', 'emergency_phone' => '0917 987 6543',
        EmployeePosition::FORM_KEY => $positionId, 'employment_status' => 'Probationary', 'work_site_id' => $site->id,
        'date_hired' => '2026-08-01', 'monthly_rate' => '25000',
    ] + EmployeeFields::blankForm();
}

it('links a record to a position and copies its current name, without a page', function () {
    $world = emplinkWorld();
    $employee = emplinkEmployee($world['site'], 'Messenger');
    $hr = emplinkActor([ModuleCode::EMPLOYEES], true);

    expect(emplinkSave($hr, $employee, [EmployeePosition::FORM_KEY => $world['developer']->id]))->toBe('Employee Details');

    $employee->refresh();
    expect($employee->position_id)->toBe($world['developer']->id)
        ->and($employee->position)->toBe('Senior Developer');

    // The title changed, so Employment History records it.
    $history = EmployeeEmploymentHistory::query()->where('employee_id', $employee->id)->sole();
    expect($history->changed_fields)->toBe('position')
        ->and($history->position)->toBe('Senior Developer');

    // The audit names the field, never the id.
    expect(AuditLog::query()->where('entity_id', $employee->id)->where('action', 'EMPLOYEE_UPDATED')->sole()->details)
        ->toContain('position')->not->toContain('position_id');
});

it('keeps the current link and re-copies the name when the same position is sent', function () {
    $world = emplinkWorld();
    $employee = emplinkEmployee($world['site']);
    $hr = emplinkActor([ModuleCode::EMPLOYEES], true);
    emplinkSave($hr, $employee, [EmployeePosition::FORM_KEY => $world['director']->id]);

    // The text drifts (as a direct write would leave it); re-saving the same
    // link puts the position's current name back.
    EmployeeProfile::query()->whereKey($employee->id)->update(['position' => 'Old title']);
    expect(EmployeeFields::toForm($employee->refresh())[EmployeePosition::FORM_KEY])->toBe($world['director']->id);

    emplinkSave($hr, $employee, ['position_level' => 'Senior']);

    expect($employee->refresh()->position_id)->toBe($world['director']->id)
        ->and($employee->position)->toBe('Engineering Director')
        ->and($employee->position_level)->toBe('Senior');
});

it('keeps a title that is not on the Position list with KEEP, and clears on blank', function () {
    $world = emplinkWorld();
    $employee = emplinkEmployee($world['site'], 'Messenger');
    $hr = emplinkActor([ModuleCode::EMPLOYEES], true);

    expect(EmployeeFields::toForm($employee)[EmployeePosition::FORM_KEY])->toBe(EmployeePosition::KEEP);

    // HR saves the rest of Employee Details; the off-list title stays.
    emplinkSave($hr, $employee, ['position_level' => 'Senior']);
    expect($employee->refresh()->position)->toBe('Messenger')
        ->and($employee->position_id)->toBeNull()
        ->and($employee->position_level)->toBe('Senior');

    // A form that carries no choice at all keeps it too, never clears it.
    $form = EmployeeFields::toForm($employee);
    unset($form[EmployeePosition::FORM_KEY]);
    app(UpdateEmployee::class)->handle($hr, $employee->id, 'employment', ['position_level' => 'Lead'] + $form);
    expect($employee->refresh()->position)->toBe('Messenger');

    // Blank clears, as it always has — the text and any link.
    emplinkSave($hr, $employee, [EmployeePosition::FORM_KEY => '']);
    expect($employee->refresh()->position)->toBeNull()->and($employee->position_id)->toBeNull();

    emplinkSave($hr, $employee, [EmployeePosition::FORM_KEY => $world['driver']->id]);
    emplinkSave($hr, $employee, [EmployeePosition::FORM_KEY => '']);
    expect($employee->refresh()->position)->toBeNull()->and($employee->position_id)->toBeNull();
});

it('refuses an id that names no position, a title sent as a choice, and leaves the record as it was', function () {
    $world = emplinkWorld();
    $employee = emplinkEmployee($world['site'], 'Messenger');
    $hr = emplinkActor([ModuleCode::EMPLOYEES], true);

    foreach (['01JZZZZZZZZZZZZZZZZZZZZZZZ', 'Driver', 'Messenger'] as $choice) {
        expect(fn () => emplinkSave($hr, $employee, [EmployeePosition::FORM_KEY => $choice, 'position_level' => 'Changed']))
            ->toThrow(RuntimeException::class, 'Choose a position from Records Management.');
    }

    expect($employee->refresh()->position)->toBe('Messenger')
        ->and($employee->position_id)->toBeNull()
        ->and($employee->position_level)->toBe('Junior')
        ->and(AuditLog::query()->count())->toBe(0)
        ->and(EmployeeEmploymentHistory::query()->count())->toBe(0);
});

it('assigns a Not placed position, and the views name the department and unit', function () {
    $world = emplinkWorld();
    $hr = emplinkActor([ModuleCode::EMPLOYEES], true);
    $driver = emplinkEmployee($world['site'], null, '11');
    $developer = emplinkEmployee($world['site'], null, '12');
    $director = emplinkEmployee($world['site'], null, '13');
    $legacy = emplinkEmployee($world['site'], 'Messenger', '14');

    emplinkSave($hr, $driver, [EmployeePosition::FORM_KEY => $world['driver']->id]);
    emplinkSave($hr, $developer, [EmployeePosition::FORM_KEY => $world['developer']->id]);
    emplinkSave($hr, $director, [EmployeePosition::FORM_KEY => $world['director']->id]);
    expect($driver->refresh()->position_id)->toBe($world['driver']->id)->and($driver->position)->toBe('Driver');

    $records = app(EmployeeRecords::class);
    $view = $records->find($hr, $developer->id);
    expect($view->position_id)->toBe($world['developer']->id)
        ->and($view->department_id)->toBe($world['engineering']->id)
        ->and($view->department_name)->toBe('Engineering Department')
        ->and($view->unit_id)->toBe($world['software']->id)
        ->and($view->unit_name)->toBe('Software Development')
        ->and($view->position_unlisted)->toBeFalse();

    $own = $records->find($hr, $director->id, details: true);
    expect($own->department_name)->toBe('Engineering Department')->and($own->unit_name)->toBeNull();

    $placed = $records->find($hr, $driver->id);
    expect($placed->department_name)->toBeNull()->and($placed->unit_name)->toBeNull()->and($placed->position_unlisted)->toBeFalse();

    expect($records->find($hr, $legacy->id)->position_unlisted)->toBeTrue();

    // The list reads every placement in ONE positions query, however many rows.
    DB::flushQueryLog();
    DB::enableQueryLog();
    $list = $records->list($hr)->keyBy('id');
    $positionReads = array_filter(DB::getQueryLog(), static fn (array $q): bool => str_contains(str_replace('`', '"', $q['query']), '"positions"'));
    DB::disableQueryLog();

    expect(count($positionReads))->toBe(1)
        ->and($list[$developer->id]->unit_name)->toBe('Software Development')
        ->and($list[$director->id]->department_name)->toBe('Engineering Department')
        ->and($list[$legacy->id]->department_name)->toBeNull();
});

it('re-reads the placement from the position id, whatever a page sent as department or unit', function () {
    $world = emplinkWorld();
    $employee = emplinkEmployee($world['site']);
    $hr = emplinkActor([ModuleCode::EMPLOYEES], true);

    emplinkSave($hr, $employee, [
        'department_id' => 'NONE', 'unit_id' => $world['software']->id,
        EmployeePosition::FORM_KEY => $world['director']->id,
    ]);

    $view = app(EmployeeRecords::class)->find($hr, $employee->id);
    expect($view->department_name)->toBe('Engineering Department')->and($view->unit_name)->toBeNull();
});

it('refuses the link to actors who may not edit the record, with no effect', function () {
    $world = emplinkWorld();
    $employee = emplinkEmployee($world['site'], 'Messenger');
    $choice = [EmployeePosition::FORM_KEY => $world['driver']->id];

    // Own Employee Details edits require an independent approval.
    $self = emplinkActor([ModuleCode::EMPLOYEES], false, $employee);
    expect(fn () => emplinkSave($self, $employee, $choice))->toThrow(HttpException::class);

    // EMPLOYEES without all_data and unlinked: the record is not visible.
    expect(fn () => emplinkSave(emplinkActor([ModuleCode::EMPLOYEES], false), $employee, $choice))->toThrow(HttpException::class);

    // All-data RECORDS (the Position page's editors) do not edit records.
    expect(fn () => emplinkSave(emplinkActor([ModuleCode::RECORDS], true), $employee, $choice))->toThrow(HttpException::class);

    // All-data PAYROLL sees the record but may not edit it.
    $payroll = emplinkActor([ModuleCode::PAYROLL], true);
    expect(fn () => emplinkSave($payroll, $employee, $choice))->toThrow(HttpException::class);

    expect($employee->refresh()->position_id)->toBeNull()
        ->and($employee->position)->toBe('Messenger')
        ->and(AuditLog::query()->count())->toBe(0);
});

it('takes the structure mutex first: without its row no link is written', function () {
    $world = emplinkWorld();
    $employee = emplinkEmployee($world['site'], 'Messenger');
    $hr = emplinkActor([ModuleCode::EMPLOYEES], true);
    $row = (array) DB::table('locks')->where('name', OrganizationReferences::STRUCTURE_LOCK)->first();
    DB::table('locks')->where('name', OrganizationReferences::STRUCTURE_LOCK)->delete();

    try {
        expect(fn () => emplinkSave($hr, $employee, [EmployeePosition::FORM_KEY => $world['driver']->id]))
            ->toThrow(RuntimeException::class, 'The database lock is not ready');
        expect(fn () => app(CreateEmployee::class)->handle($hr, emplinkHireForm($world['site'], $world['driver']->id)))
            ->toThrow(RuntimeException::class, 'The database lock is not ready');
    } finally {
        DB::table('locks')->insert($row);
    }

    expect($employee->refresh()->position_id)->toBeNull()
        ->and(EmployeeProfile::query()->count())->toBe(1);
});

it('rolls the link back when a later write in the same save fails', function () {
    $world = emplinkWorld();
    $employee = emplinkEmployee($world['site'], 'Messenger');
    $hr = emplinkActor([ModuleCode::EMPLOYEES], true);

    // A write after the profile's save fails inside the same transaction.
    EmployeeProfile::saved(static function (): void {
        throw new RuntimeException('History could not be written.');
    });

    expect(fn () => emplinkSave($hr, $employee, [EmployeePosition::FORM_KEY => $world['driver']->id]))
        ->toThrow(RuntimeException::class, 'History could not be written.');

    expect($employee->refresh()->position_id)->toBeNull()
        ->and($employee->position)->toBe('Messenger')
        ->and(AuditLog::query()->count())->toBe(0);
});

it('hires with a linked position and refuses KEEP or an unknown id on a hire', function () {
    $world = emplinkWorld();
    $hr = emplinkActor([ModuleCode::EMPLOYEES], true);

    $view = app(CreateEmployee::class)->handle($hr, emplinkHireForm($world['site'], $world['developer']->id));
    $hired = EmployeeProfile::query()->findOrFail($view->id);
    expect($hired->position_id)->toBe($world['developer']->id)
        ->and($hired->position)->toBe('Senior Developer')
        ->and($view->unit_name)->toBe('Software Development')
        ->and(EmployeeEmploymentHistory::query()->where('employee_id', $hired->id)->sole()->position)->toBe('Senior Developer');

    foreach ([EmployeePosition::KEEP, 'Driver', '01JZZZZZZZZZZZZZZZZZZZZZZZ'] as $i => $choice) {
        expect(fn () => app(CreateEmployee::class)->handle($hr, emplinkHireForm($world['site'], $choice, "refused{$i}@company.com")))
            ->toThrow(RuntimeException::class, 'Choose a position from Records Management.');
    }

    expect(fn () => app(CreateEmployee::class)->handle($hr, emplinkHireForm($world['site'], '', 'blank@company.com')))
        ->toThrow(RuntimeException::class, 'Position is required.');

    expect(EmployeeProfile::query()->count())->toBe(1);

    // A hire is HR's alone.
    $limited = emplinkActor([ModuleCode::EMPLOYEES], false);
    expect(fn () => app(CreateEmployee::class)->handle($limited, emplinkHireForm($world['site'], $world['driver']->id, 'limited@company.com')))
        ->toThrow(HttpException::class);
    expect(EmployeeProfile::query()->count())->toBe(1);
});

it('seeds each demo employee linked to the one position of its title, and a re-run keeps HR re-assignments', function () {
    test()->seed([RoleSeeder::class, ReferenceListSeeder::class, EmployeeSeeder::class]);

    $driver = Position::query()->where('name', 'Driver')->sole();
    $ramon = EmployeeProfile::query()->where('last_name', 'Aquino')->sole();
    expect($ramon->position_id)->toBe($driver->id)->and($ramon->position)->toBe('Driver')
        ->and(EmployeeProfile::query()->whereNull('position_id')->count())->toBe(0);

    $clerk = Position::query()->where('name', 'Warehouse Clerk')->sole();
    $ramon->forceFill(['position_id' => $clerk->id, 'position' => 'Warehouse Clerk'])->save();
    test()->seed(EmployeeSeeder::class);

    expect($ramon->refresh()->position_id)->toBe($clerk->id)->and($ramon->position)->toBe('Warehouse Clerk');
});
