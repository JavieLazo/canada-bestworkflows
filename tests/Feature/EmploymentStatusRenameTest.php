<?php

declare(strict_types=1);

use App\Models\AuditLog;
use App\Models\EmployeeEmploymentHistory;
use App\Models\EmployeeProfile;
use App\Models\EmploymentStatusOption;
use App\Models\Role;
use App\Models\RoleModuleGrant;
use App\Models\User;
use App\Modules\Workforce\Application\Catalogs\EmploymentStatuses;
use App\Modules\Workforce\Contracts\EmployeePayContexts;
use App\Support\Access;
use Illuminate\Support\Str;

/**
 * Renaming an Employment status rewrites the status name on every employee
 * record carrying the old one, active or not, inside the renaming
 * transaction (Workforce's EmploymentStatuses catalog, the one catalog
 * besides positions whose rename reaches the records): the name is what
 * picks an employee's pay rules and benefit eligibility, so a rename must
 * not silently drop its holders to the company rule. The copy fires no
 * model events, so Employment History gains no rows; a record under another
 * status is untouched; a refused actor changes nothing.
 */
function esrActor(bool $allData = true): Access
{
    $role = new Role;
    $role->forceFill(['name' => 'Status rename '.Str::random(6), 'all_data' => $allData, 'is_system' => false])->save();
    foreach (['RECORDS', 'PAYROLL'] as $module) {
        $grant = new RoleModuleGrant;
        $grant->forceFill(['role_id' => $role->getKey(), 'module' => $module])->save();
    }
    $user = User::factory()->create(['role_id' => $role->getKey()]);
    test()->actingAs($user);
    app()->forgetInstance(Access::class);

    return Access::forActor($user);
}

function esrStatus(string $name): EmploymentStatusOption
{
    $status = new EmploymentStatusOption;
    $status->forceFill(['code' => (string) (EmploymentStatusOption::query()->count() + 1), 'name' => $name])->save();

    return $status;
}

function esrEmployee(string $last, string $status, bool $active = true): EmployeeProfile
{
    $employee = new EmployeeProfile;
    $employee->forceFill([
        'employee_code' => 'ESR-'.random_int(1000, 9999), 'first_name' => 'Nina', 'last_name' => $last,
        'employment_status' => $status, 'date_hired' => '2024-01-01', 'is_active' => $active, 'monthly_rate' => 30000,
    ])->save();

    return $employee;
}

it('copies a renamed employment status onto every record holding it, active or not, and nowhere else', function () {
    $actor = esrActor();
    $regular = esrStatus('Regular');
    esrStatus('Contractual');
    $active = esrEmployee('Active', 'Regular');
    $former = esrEmployee('Former', 'Regular', active: false);
    $other = esrEmployee('Other', 'Contractual');
    $histories = EmployeeEmploymentHistory::query()->count();

    expect(app(EmploymentStatuses::class)->save($actor, $regular->id, 'Permanent', ''))->toBe('Permanent updated.');

    expect($active->refresh()->employment_status)->toBe('Permanent')
        ->and($former->refresh()->employment_status)->toBe('Permanent')
        ->and($other->refresh()->employment_status)->toBe('Contractual')
        ->and($regular->refresh()->name)->toBe('Permanent')
        // No model events: Employment History keeps the name each entry was saved with.
        ->and(EmployeeEmploymentHistory::query()->count())->toBe($histories)
        ->and(AuditLog::query()->where('action', 'REFERENCE_UPDATED')->value('details'))->toContain('updated to "Permanent", copied onto 2 employee records');

    // The renamed holders still resolve to the same list entry, so a status override keeps pricing them.
    $contexts = collect(app(EmployeePayContexts::class)->get($actor, [$active->id, $other->id]))->keyBy('id');
    expect($contexts[$active->id]->employment_status_id)->toBe((string) $regular->id)
        ->and($contexts[$active->id]->employment_status)->toBe('Permanent')
        ->and($contexts[$other->id]->employment_status_id)->not->toBe((string) $regular->id);
});

it('copies nothing when only the description changes, and nothing at all for a refused actor', function () {
    $actor = esrActor();
    $regular = esrStatus('Regular');
    $employee = esrEmployee('Same', 'Regular');

    app(EmploymentStatuses::class)->save($actor, $regular->id, 'Regular', 'Plantilla staff');
    expect($employee->refresh()->employment_status)->toBe('Regular')
        ->and(AuditLog::query()->where('action', 'REFERENCE_UPDATED')->value('details'))->not->toContain('copied onto');

    $viewer = esrActor(allData: false);
    expect(fn () => app(EmploymentStatuses::class)->save($viewer, $regular->id, 'Permanent', ''))
        ->toThrow(RuntimeException::class, 'Your role cannot manage organization-wide data.');
    expect($employee->refresh()->employment_status)->toBe('Regular')
        ->and($regular->refresh()->name)->toBe('Regular');
});
