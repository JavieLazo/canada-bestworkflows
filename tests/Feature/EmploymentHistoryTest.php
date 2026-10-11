<?php

declare(strict_types=1);

use App\Domain\Enums\ModuleCode;
use App\Livewire\Employees\History;
use App\Livewire\Employees\Show;
use App\Models\AuditLog;
use App\Models\EmployeeEmploymentHistory;
use App\Models\EmployeeProfile;
use App\Models\EmploymentStatusOption;
use App\Models\Position;
use App\Models\Role;
use App\Models\RoleModuleGrant;
use App\Models\User;
use App\Models\WorkSite;
use App\Modules\Organization\Application\Commands\DeleteSite;
use App\Modules\Organization\Application\Commands\SaveSite;
use App\Modules\Organization\Application\SiteDraft;
use App\Modules\Workforce\Application\Commands\UpdateEmployee;
use App\Modules\Workforce\Application\EmployeeFields;
use App\Modules\Workforce\Application\EmploymentHistoryRecorder;
use App\Modules\Workforce\Application\Queries\EmploymentHistoryRecords;
use App\Support\Access;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Employment history preserves recorded facts, stays scoped to the employee,
 * and cannot replace current values on the printed 201 file.
 */

/** @return array{EmployeeProfile, WorkSite, WorkSite} */
function ehfSetup(): array
{
    Position::query()->create(['code' => 'POS-HISTORY', 'name' => 'Driver']);
    EmploymentStatusOption::query()->create(['code' => 'STATUS-HISTORY', 'name' => 'Regular']);
    $siteA = WorkSite::query()->create(['name' => 'Original office', 'latitude' => 14.5, 'longitude' => 120.9, 'radius_meters' => 100]);
    $siteB = WorkSite::query()->create(['name' => 'Current office', 'latitude' => 14.5, 'longitude' => 120.9, 'radius_meters' => 100]);
    $role = Role::query()->create(['name' => 'History HR', 'all_data' => true, 'is_system' => false]);
    foreach ([ModuleCode::EMPLOYEES, ModuleCode::RECORDS] as $module) {
        RoleModuleGrant::query()->create(['role_id' => $role->id, 'module' => $module]);
    }
    test()->actingAs(User::factory()->create(['role_id' => $role->id]));
    app()->forgetInstance(Access::class);
    $employee = EmployeeProfile::query()->create([
        'employee_code' => 'HISTORY-1', 'first_name' => 'History', 'last_name' => 'Employee',
        'position' => 'Driver', 'position_level' => 'Level Alpha', 'employment_status' => 'Regular',
        'date_hired' => '2024-01-01', 'work_site_id' => $siteA->id, 'monthly_rate' => 30000,
        'is_active' => true,
    ]);
    app(EmploymentHistoryRecorder::class)->hired(app(Access::class), $employee->refresh());

    return [$employee, $siteA, $siteB];
}

function ehfText(string $html, string $expression): string
{
    $document = new DOMDocument;
    @$document->loadHTML($html);
    $nodes = (new DOMXPath($document))->query($expression);
    assert($nodes !== false);
    $node = $nodes->item(0);
    expect($node)->toBeInstanceOf(DOMNode::class);
    assert($node instanceof DOMNode);

    return trim($node->textContent);
}

it('prints the current worksite after a transfer and Livewire rerender', function () {
    [$employee, $oldSite, $currentSite] = ehfSetup();
    $component = Livewire::test(Show::class, ['employee' => $employee->id])
        ->call('startEdit', 'employment')
        ->set('form.work_site_id', $currentSite->id)
        ->call('saveSection', 'employment')
        ->assertSet('editingSection', null);

    expect($employee->refresh()->work_site_id)->toBe($currentSite->id)
        ->and(ehfText($component->html(), '//dt[normalize-space(.)="Area office"]/following-sibling::dd[1]'))->toBe($currentSite->name);

    $history = app(EmploymentHistoryRecords::class)->forEmployee(app(Access::class), $employee->id);
    expect($history)->toHaveCount(2)
        ->and($history->first()?->work_site_name)->toBe($currentSite->name)
        ->and($history->last()?->work_site_name)->toBe($oldSite->name);
});

it('preserves a historical worksite name through renaming and deletion', function () {
    [$employee, $oldSite, $currentSite] = ehfSetup();
    $actor = app(Access::class);
    app(UpdateEmployee::class)->handle($actor, $employee->id, 'employment',
        ['work_site_id' => $currentSite->id] + EmployeeFields::toForm($employee));
    app(SaveSite::class)->handle($actor, $oldSite->id, new SiteDraft('Renamed office', '14.5', '120.9', '100', false));

    $historyXPath = '//*[@*[name()="wire:key"]="employment-history-'.EmployeeEmploymentHistory::query()->where('event_type', EmploymentHistoryRecorder::UPDATED)->sole()->id.'"]';
    $html = Livewire::test(History::class)->set('employeeId', $employee->id)->html();
    expect(ehfText($html, $historyXPath))->toContain('Original office')->not->toContain('Renamed office');

    app(DeleteSite::class)->handle($actor, $oldSite->id);
    $html = Livewire::test(History::class)->set('employeeId', $employee->id)->html();
    expect(WorkSite::query()->whereKey($oldSite->id)->exists())->toBeFalse()
        ->and(ehfText($html, $historyXPath))->toContain('Original office')->not->toContain('Unknown worksite');
});

it('shows only the changed position level and active state with their previous values', function () {
    [$employee] = ehfSetup();
    $original = EmployeeEmploymentHistory::query()->sole();
    $component = Livewire::test(Show::class, ['employee' => $employee->id])
        ->call('startEdit', 'employment')
        ->set('form.position_level', 'Level Beta')
        ->set('form.is_active', false)
        ->call('saveSection', 'employment')
        ->assertSet('editingSection', null);
    $updated = EmployeeEmploymentHistory::query()->where('event_type', EmploymentHistoryRecorder::UPDATED)->sole();

    $history = Livewire::test(History::class)->set('employeeId', $employee->id);
    expect($updated->position_level)->toBe('Level Beta')->and($updated->is_active)->toBeFalse()
        ->and($history->html())->not->toContain('employment-history-'.$original->id)
        ->and(ehfText($history->html(), '//*[@*[name()="wire:key"]="employment-history-'.$updated->id.'"]'))
        ->toContain('Level Alpha', 'Level Beta', 'Active', 'Inactive')
        ->not->toContain('Original office')->not->toContain('30,000');
});

it('rolls employee details back when their employment snapshot cannot be recorded', function () {
    [$employee] = ehfSetup();
    $auditCount = AuditLog::query()->count();
    EmployeeEmploymentHistory::creating(static function (): never {
        throw new RuntimeException('History unavailable');
    });

    expect(fn () => app(UpdateEmployee::class)->handle(app(Access::class), $employee->id, 'employment',
        ['monthly_rate' => '40000'] + EmployeeFields::toForm($employee)))
        ->toThrow(RuntimeException::class, 'History unavailable');

    expect($employee->refresh()->monthly_rate)->toBe(30000.0)
        ->and(EmployeeEmploymentHistory::query()->count())->toBe(1)
        ->and(AuditLog::query()->count())->toBe($auditCount);
});

it('refuses history reads and employee edits to an unlinked employee viewer', function () {
    [$employee] = ehfSetup();
    $role = Role::query()->create(['name' => 'History viewer', 'all_data' => false, 'is_system' => false]);
    RoleModuleGrant::query()->create(['role_id' => $role->id, 'module' => ModuleCode::EMPLOYEES]);
    $actor = Access::forActor(User::factory()->create(['role_id' => $role->id, 'employee_id' => null]));
    $auditCount = AuditLog::query()->count();

    expect(app(EmploymentHistoryRecords::class)->forEmployee($actor, $employee->id))->toBeEmpty()
        ->and(fn () => app(UpdateEmployee::class)->handle($actor, $employee->id, 'employment',
            ['monthly_rate' => '40000'] + EmployeeFields::toForm($employee)))->toThrow(HttpException::class)
        ->and($employee->refresh()->monthly_rate)->toBe(30000.0)
        ->and(EmployeeEmploymentHistory::query()->count())->toBe(1)
        ->and(AuditLog::query()->count())->toBe($auditCount);
});
