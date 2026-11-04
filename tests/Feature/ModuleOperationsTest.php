<?php

declare(strict_types=1);

use App\Domain\Enums\ModuleCode;
use App\Livewire\Profile\ProfilePage;
use App\Models\AuditLog;
use App\Models\DocumentType;
use App\Models\EmployeeDocument;
use App\Models\EmployeeProfile;
use App\Models\PayrollRun;
use App\Models\Project;
use App\Models\Role;
use App\Models\RoleModuleGrant;
use App\Models\User;
use App\Models\WorkSite;
use App\Modules\IdentityAccess\Application\Commands\ManageAccounts;
use App\Modules\IdentityAccess\Application\Commands\ManageRoles;
use App\Modules\Inventory\Application\Commands\SaveItem;
use App\Modules\Organization\Application\Commands\SaveSite;
use App\Modules\Organization\Application\SiteDraft;
use App\Modules\Organization\Application\SiteRetention;
use App\Modules\Payroll\Application\Commands\DeleteIncentiveType;
use App\Modules\Payroll\Application\Commands\DeletePayroll;
use App\Modules\Payroll\Application\Commands\SaveFormulas;
use App\Modules\Payroll\Application\Commands\SaveIncentiveType;
use App\Modules\Payroll\Application\Commands\SavePayroll;
use App\Modules\Payroll\Application\PayrollDraft;
use App\Modules\Timekeeping\Application\Commands\AssignShift;
use App\Modules\Timekeeping\Application\Commands\DecideOvertime;
use App\Modules\Timekeeping\Application\Commands\DecidePendingOvertime;
use App\Modules\Timekeeping\Application\Commands\RecordPunch;
use App\Modules\Timekeeping\Application\Commands\SaveLeaveCredits;
use App\Modules\Timekeeping\Application\Commands\SaveRemark;
use App\Modules\Timekeeping\Application\Commands\SaveRosterLocations;
use App\Modules\Timekeeping\Application\PunchInput;
use App\Modules\Workforce\Application\Commands\AttachDocument;
use App\Modules\Workforce\Application\Commands\DeleteDocument;
use App\Modules\Workforce\Application\Commands\DeleteEmployee;
use App\Modules\Workforce\Application\Commands\UpdateOwnProfile;
use App\Modules\Workforce\Application\Commands\UpdatePortrait;
use App\Modules\Workforce\Contracts\PunchEmployees;
use App\Modules\WorkManagement\Application\Commands\CreateProject;
use App\Modules\WorkManagement\Application\Commands\ReviewTaskResponse;
use App\Modules\WorkManagement\Application\ProjectDraft;
use App\Platform\Files\AttachmentUsage;
use App\Platform\Notifications\NotificationRecipients;
use App\Platform\Notifications\ReminderSource;
use App\Support\Access;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** Commands must enforce their own authorization and preserve legacy safety invariants without a screen. */

/** @param list<string> $modules */
function moduleOpsActor(array $modules, ?EmployeeProfile $employee = null): User
{
    $role = Role::create(['name' => 'Operation actor', 'all_data' => true, 'is_system' => false]);
    foreach ($modules as $module) {
        RoleModuleGrant::create(['role_id' => $role->id, 'module' => $module]);
    }

    return User::factory()->create(['role_id' => $role->id, 'employee_id' => $employee?->id]);
}

function moduleOpsEmployee(string $code): EmployeeProfile
{
    return EmployeeProfile::create(['employee_code' => $code, 'first_name' => 'Module', 'last_name' => $code, 'date_hired' => '2024-01-01', 'employment_status' => 'Regular', 'is_active' => true, 'monthly_rate' => 30000]);
}

it('refuses unprivileged direct operations across every business module', function () {
    $actor = Access::forActor(moduleOpsActor([]));
    $operations = [
        fn () => app(ManageAccounts::class)->create($actor, 'name@example.test', 'password123', 'missing', ''),
        fn () => app(ManageRoles::class)->save($actor, null, 'Role', true),
        fn () => app(AssignShift::class)->handle($actor, 'missing', 'missing'),
        fn () => app(SaveRosterLocations::class)->handle($actor, 'missing', [1 => 'WFH']),
        fn () => app(SaveLeaveCredits::class)->handle($actor, 'missing', 2026, ['VACATION_LEAVE' => '15']),
        fn () => app(DecideOvertime::class)->handle($actor, 'missing', '2026-09-01', 'APPROVED', '60'),
        fn () => app(DecidePendingOvertime::class)->handle($actor, 'missing', '2026-09-01', '2026-09-15', 'APPROVED'),
        fn () => app(SaveSite::class)->handle($actor, null, new SiteDraft('Site', '0', '0', '0', true)),
        fn () => app(CreateProject::class)->handle($actor, new ProjectDraft('Project', '', 'NONE', 'ACTIVE', '2026-09-01', '', 'NONE')),
        fn () => app(SaveItem::class)->handle($actor, null, []),
        fn () => app(SavePayroll::class)->handle($actor, new PayrollDraft(null, 'Run', '2026-09-01', '2026-09-15', [], [], [], [])),
        fn () => app(DeletePayroll::class)->handle($actor, 'missing'),
        fn () => app(SaveFormulas::class)->handle($actor, []),
        fn () => app(SaveIncentiveType::class)->handle($actor, null, 'Commission', null, true),
        fn () => app(DeleteIncentiveType::class)->handle($actor, 'missing'),
        fn () => app(ReviewTaskResponse::class)->handle($actor, 'missing', 'missing', 'missing', 'APPROVED', '', 0),
    ];
    foreach ($operations as $operation) {
        expect($operation)->toThrow(RuntimeException::class);
    }
    expect(app(SaveRemark::class)->handle($actor, 'missing', '2026-09-01', 'note'))->toBeNull();
    expect(AuditLog::count())->toBe(0);
    expect(PayrollRun::count())->toBe(0);
});

it('rechecks revoked account management grants for a direct call', function () {
    $user = moduleOpsActor([ModuleCode::USERS]);
    RoleModuleGrant::where('role_id', $user->role_id)->delete();
    $user->unsetRelation('role');
    expect(fn () => app(ManageRoles::class)->save(Access::forActor($user), null, 'Denied', true))->toThrow(RuntimeException::class);
    expect(Role::where('name', 'Denied')->exists())->toBeFalse();
});

it('cannot remove its own recovery role or grant through direct operations', function () {
    $user = moduleOpsActor([ModuleCode::USERS]);
    $actor = Access::forActor($user);
    expect(fn () => app(ManageRoles::class)->save($actor, $user->role_id, 'Changed', false))->toThrow(RuntimeException::class);
    expect(fn () => app(ManageRoles::class)->toggleGrant($actor, $user->role_id, ModuleCode::USERS))->toThrow(RuntimeException::class);
    expect($user->role->fresh()->all_data)->toBeTrue();
    expect(RoleModuleGrant::where('role_id', $user->role_id)->where('module', ModuleCode::USERS)->exists())->toBeTrue();
});

it('refuses direct all-data self-profile edits without creating a bypass around review', function () {
    $own = moduleOpsEmployee('EMP-OWN');
    $other = moduleOpsEmployee('EMP-OTHER');
    $actor = Access::forActor(moduleOpsActor([ModuleCode::EMPLOYEES], $own));
    expect(fn () => app(UpdateOwnProfile::class)->personal($actor, ['phone' => '0917 123 4567', 'employee_id' => $other->id]))
        ->toThrow(RuntimeException::class, 'Submit a record change request');
    expect($own->fresh()->phone)->toBeNull()
        ->and($other->fresh()->phone)->toBeNull()
        ->and(AuditLog::count())->toBe(0);
});

it('refuses direct document uploads and portrait edits for another employee', function () {
    Storage::fake('local');
    Storage::fake('public');
    $own = moduleOpsEmployee('EMP-OWN');
    $other = moduleOpsEmployee('EMP-OTHER');
    $actor = Access::forActor(moduleOpsActor([ModuleCode::PROFILE], $own));
    expect(fn () => app(AttachDocument::class)->handle($actor, $other->id, UploadedFile::fake()->create('file.pdf', 1), ['name' => '', 'category' => '', 'document_no' => '', 'expires_on' => '']))->toThrow(HttpException::class);
    expect(fn () => app(UpdatePortrait::class)->handle($actor, $other->id, null))->toThrow(HttpException::class);
    expect(Storage::disk('local')->allFiles())->toBe([]);
    expect(EmployeeDocument::count())->toBe(0);
});

it('preserves document bytes when metadata deletion fails', function () {
    Storage::fake('local');
    $employee = moduleOpsEmployee('EMP-FILE');
    $actor = Access::forActor(moduleOpsActor([ModuleCode::EMPLOYEES]));
    DocumentType::create(['code' => 'DOC-001', 'name' => 'Evidence']);
    app(AttachDocument::class)->handle($actor, $employee->id, UploadedFile::fake()->create('file.pdf', 1), ['name' => '', 'category' => 'Evidence', 'document_no' => '', 'expires_on' => '']);
    $document = EmployeeDocument::firstOrFail();
    EmployeeDocument::deleting(static function (): never {
        throw new RuntimeException('delete refused');
    });
    expect(fn () => app(DeleteDocument::class)->handle($actor, $employee->id, $document->id))->toThrow(RuntimeException::class, 'delete refused');
    expect(Storage::disk('local')->exists($document->path))->toBeTrue();
    expect($document->fresh())->not->toBeNull();
});

it('preserves the portrait when employee deletion is refused by persistence', function () {
    Storage::fake('public');
    $employee = moduleOpsEmployee('EMP-PORTRAIT');
    $employee->update(['is_active' => false, 'photo_path' => 'portraits/preserved.jpg']);
    Storage::disk('public')->put('portraits/preserved.jpg', 'existing portrait');
    $actor = Access::forActor(moduleOpsActor([ModuleCode::EMPLOYEES]));
    EmployeeProfile::deleting(static function (): never {
        throw new RuntimeException('delete refused');
    });
    expect(fn () => app(DeleteEmployee::class)->handle($actor, $employee->id))->toThrow(RuntimeException::class, 'delete refused');
    expect(Storage::disk('public')->get('portraits/preserved.jpg'))->toBe('existing portrait');
    expect($employee->fresh())->not->toBeNull();
});

it('keeps the upload budget supplied by each attachment owner', function () {
    Storage::fake('local');
    $employee = moduleOpsEmployee('EMP-BUDGET');
    $actor = Access::forActor(moduleOpsActor([ModuleCode::EMPLOYEES]));
    DocumentType::create(['code' => 'DOC-001', 'name' => 'Evidence']);
    app(AttachDocument::class)->handle($actor, $employee->id, UploadedFile::fake()->create('file.pdf', 2), ['name' => '', 'category' => 'Evidence', 'document_no' => '', 'expires_on' => '']);
    expect(AttachmentUsage::bytes())->toBe(2048);
    // Documents, task files, inventory and retained record-change evidence share the budget.
    // Community and Chat keep their own but are listed with them for ems:check-files.
    expect(iterator_to_array(app()->tagged('attachment-usage-sources')))->toHaveCount(4)
        ->and(iterator_to_array(app()->tagged('stored-file-sources')))->toHaveCount(5);
});

it('denies forged kiosk and unlinked punch lookups', function () {
    $employee = moduleOpsEmployee('EMP-PUNCH');
    $actor = Access::forActor(moduleOpsActor([ModuleCode::PROFILE]));
    expect(app(PunchEmployees::class)->find($actor, $employee->id))->toBeNull();
    expect(fn () => app(RecordPunch::class)->handle($actor, new PunchInput($employee->id, '', 'MANUAL', true, '', ''), 'TIME_IN'))->toThrow(RuntimeException::class, 'Forbidden.');
});

it('retains a worksite while projects reference it through their owning provider', function () {
    $site = WorkSite::create(['name' => 'Project site', 'latitude' => 0, 'longitude' => 0, 'radius_meters' => 0, 'geofence_exempt' => true]);
    Project::create(['code' => 'PRJ-001', 'name' => 'Project', 'status' => 'ACTIVE', 'start_date' => '2026-09-01', 'site_id' => $site->id]);
    expect(app(SiteRetention::class)->refusal($site->id))->not->toBeNull();
});

it('assigns every bounded reminder group to exactly one owning module', function () {
    $keys = [];
    foreach (app()->tagged('reminder-data-sources') as $source) {
        expect($source)->toBeInstanceOf(ReminderSource::class);
        foreach ($source->groups() as $key) {
            $keys[] = $key;
        }
    }
    sort($keys);
    expect($keys)->toBe(['assigned', 'documents', 'leaves', 'payroll', 'probation', 'projects', 'reviews']);
});

it('notifies every active account of an employee while respecting account exclusions', function () {
    $employee = moduleOpsEmployee('EMP-RECIPIENT');
    $first = User::factory()->create(['employee_id' => $employee->id, 'is_active' => true]);
    $second = User::factory()->create(['employee_id' => $employee->id, 'is_active' => true]);
    User::factory()->create(['employee_id' => $employee->id, 'is_active' => false]);
    $recipients = app(NotificationRecipients::class);
    expect($recipients->employees([$employee->id], []))->toBe([$first->id, $second->id]);
    expect($recipients->employees([$employee->id], [$first->id]))->toBe([$second->id]);
});

it('refreshes the authenticated credential after changing the password through My Profile', function () {
    $user = moduleOpsActor([ModuleCode::PROFILE]);
    $user->password = 'original-password';
    $user->save();
    $this->actingAs($user);
    Livewire::test(ProfilePage::class)
        ->set('currentPassword', 'original-password')->set('newPassword', 'Replacement-Password#2026')
        ->set('confirmPassword', 'Replacement-Password#2026')->call('changePassword');
    expect(Hash::check('Replacement-Password#2026', auth()->user()->getAuthPassword()))->toBeTrue();
});
