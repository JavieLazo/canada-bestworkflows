<?php

declare(strict_types=1);

use App\Livewire\Leaves\Index;
use App\Models\AuditLog;
use App\Models\EmployeeProfile;
use App\Models\LeaveCredit;
use App\Models\LeaveTravelRequest;
use App\Models\LeaveType;
use App\Models\Notification;
use App\Models\Role;
use App\Models\RoleModuleGrant;
use App\Models\User;
use App\Modules\Timekeeping\Application\Commands\FileAbsence;
use App\Support\Access;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Livewire\Livewire;

/**
 * HR files on an employee's behalf and back-files (the PPMC plan's Phase
 * 14, D10, the owner's answer: "as long as the user wants", no window): a
 * leave manager with all-data LEAVES files a request in any active
 * employee's name but their own, past dates included, through the same
 * checks, credits and overlap rule as a self-service filing; the audit
 * and the notice name who filed for whom.
 */

/** @param list<string> $modules */
function hrlfActor(array $modules = ['LEAVES'], bool $allData = true, ?string $employeeId = null): Access
{
    $role = new Role;
    $role->forceFill(['name' => 'HRfile '.Str::random(6), 'all_data' => $allData, 'is_system' => false])->save();
    foreach ($modules as $module) {
        $grant = new RoleModuleGrant;
        $grant->forceFill(['role_id' => $role->getKey(), 'module' => $module])->save();
    }
    $user = User::factory()->create(['role_id' => $role->getKey(), 'employee_id' => $employeeId]);
    test()->actingAs($user);
    app()->forgetInstance(Access::class);

    return app(Access::class);
}

function hrlfEmployee(bool $active = true): EmployeeProfile
{
    $employee = new EmployeeProfile;
    $employee->forceFill(['employee_code' => 'HRLF-'.Str::random(6), 'first_name' => 'Fil', 'last_name' => 'Behalf '.Str::random(3),
        'date_hired' => '2024-01-01', 'employment_status' => 'Regular', 'is_active' => $active])->save();
    $credit = new LeaveCredit;
    $credit->forceFill(['employee_id' => $employee->id, 'year' => 2026, 'request_type' => 'VACATION_LEAVE', 'credits' => 5])->save();

    return $employee;
}

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-20 02:00:00', 'UTC'));
    $type = new LeaveType;
    $type->forceFill(['code' => 'HRLF-VL', 'name' => 'Vacation Leave'])->save();
});

it('files for an active employee, past dates included, against their credits, naming who filed for whom', function () {
    $employee = hrlfEmployee();
    $manager = hrlfActor();
    $command = app(FileAbsence::class);

    expect(fn () => $command->forEmployee($manager, (string) Str::ulid(), 'VACATION_LEAVE', 'WHOLE_DAY', '2026-09-01', '2026-09-01', 'Gone'))->toThrow(RuntimeException::class, 'active employee')
        ->and(fn () => $command->forEmployee($manager, hrlfEmployee(active: false)->id, 'VACATION_LEAVE', 'WHOLE_DAY', '2026-09-01', '2026-09-01', 'Inactive'))->toThrow(RuntimeException::class, 'active employee')
        ->and(fn () => $command->forEmployee($manager, $employee->id, 'BONUS_LEAVE', 'WHOLE_DAY', '2026-09-01', '2026-09-01', 'Bad type'))->toThrow(RuntimeException::class, 'Leave Settings')
        ->and(fn () => $command->forEmployee($manager, $employee->id, 'VACATION_LEAVE', 'WHOLE_DAY', '2026-09-01', '2026-09-30', 'Too many'))->toThrow(RuntimeException::class);
    expect(LeaveTravelRequest::query()->count())->toBe(0);

    // Another LEAVES holder hears of the filing; the actor never notifies themself.
    $holderRole = new Role;
    $holderRole->forceFill(['name' => 'HRfile holder '.Str::random(6), 'all_data' => true, 'is_system' => false])->save();
    $holderGrant = new RoleModuleGrant;
    $holderGrant->forceFill(['role_id' => $holderRole->getKey(), 'module' => 'LEAVES'])->save();
    User::factory()->create(['role_id' => $holderRole->getKey()]);

    // Back-filed: a past week, on paper.
    $command->forEmployee($manager, $employee->id, 'VACATION_LEAVE', 'HALF_DAY', '2026-09-01', '2026-09-02', 'Brought in on paper', 'AM');
    $request = LeaveTravelRequest::query()->sole();
    expect($request->employee_id)->toBe($employee->id)->and($request->status)->toBe('PENDING')->and($request->day_type)->toBe('HALF_DAY')->and($request->half)->toBe('AM')
        ->and(AuditLog::query()->where('action', 'REQUEST_FILED')->value('details'))->toContain('filed for Fil Behalf')
        ->and(Notification::query()->where('kind', 'LEAVE_FILED')->value('title'))->toContain('filed for Fil Behalf')->toContain('by');
    // The overlap rule holds for the same employee.
    expect(fn () => $command->forEmployee($manager, $employee->id, 'VACATION_LEAVE', 'WHOLE_DAY', '2026-09-02', '2026-09-03', 'Overlap'))->toThrow(RuntimeException::class, 'overlap');
});

it('refuses a manager without all data, the manager\'s own employee, and a PORTAL-only holder', function () {
    $employee = hrlfEmployee();
    $viewer = hrlfActor(allData: false);
    expect(fn () => app(FileAbsence::class)->forEmployee($viewer, $employee->id, 'VACATION_LEAVE', 'WHOLE_DAY', '2026-09-01', '2026-09-01', 'No'))->toThrow(RuntimeException::class, 'organization-wide');
    $own = hrlfActor(employeeId: $employee->id);
    expect(fn () => app(FileAbsence::class)->forEmployee($own, $employee->id, 'VACATION_LEAVE', 'WHOLE_DAY', '2026-09-01', '2026-09-01', 'Mine'))->toThrow(RuntimeException::class, 'My Portal');
    $portal = hrlfActor(['PORTAL'], allData: false, employeeId: hrlfEmployee()->id);
    expect(fn () => app(FileAbsence::class)->forEmployee($portal, $employee->id, 'VACATION_LEAVE', 'WHOLE_DAY', '2026-09-01', '2026-09-01', 'No'))->toThrow(RuntimeException::class, 'organization-wide')
        ->and(LeaveTravelRequest::query()->count())->toBe(0);
});

it('files from the Leave Management dialog and a viewer without all data sees no button and changes nothing', function () {
    $employee = hrlfEmployee();
    hrlfActor();
    $page = Livewire::test(Index::class)->assertSeeHtml('data-file-for-employee')
        ->call('openFile')->assertSet('fileOpen', true)->assertSee('File for an employee')
        ->set('fileForm.type', 'VACATION_LEAVE')->set('fileForm.start', '2026-09-07')->set('fileForm.end', '2026-09-07')->set('fileForm.reason', 'Dialog')
        ->call('fileForEmployee')->assertSet('fileError', 'Choose the employee the request is for.');
    $page->call('employeePicked', 'file-employee', $employee->id)->assertSet('fileEmployeeId', $employee->id)
        ->call('fileForEmployee')->assertSet('fileOpen', false)->assertSet('fileError', null);
    expect(LeaveTravelRequest::query()->where('employee_id', $employee->id)->where('start_date', '2026-09-07')->exists())->toBeTrue();
    // A forged pick of an unknown id is dropped.
    $page->call('openFile')->call('employeePicked', 'file-employee', (string) Str::ulid())->assertSet('fileEmployeeId', null);

    hrlfActor(allData: false);
    Livewire::test(Index::class)->assertDontSeeHtml('data-file-for-employee')
        ->call('openFile')->assertSet('fileOpen', false)
        ->call('employeePicked', 'file-employee', $employee->id)->assertSet('fileEmployeeId', null)
        ->set('fileForm', ['type' => 'VACATION_LEAVE', 'day_type' => 'WHOLE_DAY', 'half' => '', 'start' => '2026-09-08', 'end' => '2026-09-08', 'reason' => 'No'])
        ->call('fileForEmployee');
    expect(LeaveTravelRequest::query()->count())->toBe(1);
});
