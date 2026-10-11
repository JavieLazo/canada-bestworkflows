<?php

declare(strict_types=1);

use App\Livewire\Leaves\Index;
use App\Livewire\Leaves\LeaveLedger;
use App\Models\EmployeeProfile;
use App\Models\LeaveCredit;
use App\Models\LeaveCreditEntry;
use App\Models\LeaveType;
use App\Models\Role;
use App\Models\RoleModuleGrant;
use App\Models\User;
use App\Modules\Timekeeping\Application\Commands\RecordLeaveCredit;
use App\Support\Access;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

/** Ledger screens and CSVs enforce the same employee scope and keep manual posts repeat-safe. */
function ledgerPageEmployee(string $name): EmployeeProfile
{
    return EmployeeProfile::query()->create([
        'employee_code' => 'LP-'.Str::random(8), 'first_name' => $name, 'last_name' => 'Ledger',
        'employment_status' => 'Regular', 'date_hired' => '2024-01-01', 'is_active' => true,
    ]);
}

/** @param list<string> $modules */
function ledgerPageActor(array $modules, bool $allData, ?EmployeeProfile $employee = null): User
{
    $role = Role::query()->create(['name' => 'Ledger '.Str::random(8), 'all_data' => $allData, 'is_system' => false]);
    foreach ($modules as $module) {
        RoleModuleGrant::query()->create(['role_id' => $role->id, 'module' => $module]);
    }
    $actor = User::factory()->create(['role_id' => $role->id, 'employee_id' => $employee?->id]);
    test()->actingAs($actor);
    app()->forgetInstance(Access::class);

    return $actor;
}

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-02T04:00:00Z'));
    LeaveType::query()->create(['code' => 'LP-SICK', 'name' => 'Sick Leave']);
});

it('opens the ledger tab and records manual accrual and optional carry forward through the command', function (): void {
    $employee = ledgerPageEmployee('Alex');
    ledgerPageActor(['LEAVES'], true);
    Livewire::withQueryParams(['tab' => 'ledger']);
    Livewire::test(Index::class)->assertSet('tab', 'ledger')->assertSee('data-leave-ledger', false);

    $page = Livewire::test(LeaveLedger::class)
        ->call('employeePicked', 'ledger-employee', $employee->id)
        ->call('openEntry')->set('entryType', 'SICK_LEAVE')->set('kind', 'OPENING')
        ->set('days', '10')->set('note', 'Opening approved by HR')->call('saveEntry')
        ->assertSet('entryOpen', false)->assertSee('Opening approved by HR');
    $page->call('openEntry')->set('kind', 'ACCRUAL')->set('days', '1.5')
        ->set('note', 'Earned this month')->call('saveEntry');
    $page->call('openEntry')->set('kind', 'CARRY_FORWARD')->set('days', '2')
        ->set('note', 'HR approved unused credits')->call('saveEntry')
        ->assertSee('HR approved unused credits')->assertSee('from 2025');
    $page->call('openEntry')->set('kind', 'ADJUSTMENT')->set('days', '-0.5')
        ->set('note', 'Correct the manual allocation')->call('saveEntry');
    // A duplicate action after a successful submit cannot post another entry.
    $page->call('saveEntry');
    expect(LeaveCredit::query()->firstOrFail()->credits)->toBe(13.0)
        ->and(LeaveCreditEntry::query()->count())->toBe(4);
});

it('pins the portal ledger to its employee even for an all-data manager and refuses posts there', function (): void {
    $own = ledgerPageEmployee('Own');
    $other = ledgerPageEmployee('Other');
    ledgerPageActor(['LEAVES', 'PORTAL'], true, $own);
    app(RecordLeaveCredit::class)->handle(app(Access::class), $other->id, 2026, 'SICK_LEAVE', 'OPENING', '9', 'Other private entry', (string) Str::ulid());
    Livewire::test(LeaveLedger::class, ['own' => true])
        ->assertSet('employeeId', $own->id)->assertDontSee('Other private entry')
        ->assertDontSee('Export CSV')
        ->assertDontSee('Record credits')->call('employeePicked', 'ledger-employee', $other->id)
        ->assertSet('employeeId', $own->id)->call('openEntry')->assertSet('entryOpen', false)
        ->set('days', '100')->call('saveEntry')->assertDontSee('Export CSV');
    expect(LeaveCreditEntry::query()->count())->toBe(1);
    $this->get('/portal/ledger/export?employee='.$other->id.'&year=2026')->assertNotFound();
});

it('refuses restricted writes and forged employee changes', function (): void {
    $own = ledgerPageEmployee('Own');
    $other = ledgerPageEmployee('Other');
    ledgerPageActor(['LEAVES'], false, $own);
    Livewire::test(LeaveLedger::class)->call('employeePicked', 'ledger-employee', $other->id)
        ->assertSet('employeeId', $own->id)->call('openEntry')->assertSet('entryOpen', false)
        ->call('saveEntry');
    expect(LeaveCreditEntry::query()->count())->toBe(0);
    expect(fn () => Livewire::test(LeaveLedger::class)->set('employeeId', $other->id))
        ->toThrow(CannotUpdateLockedPropertyException::class);
});

it('exports the same credit history and balance breakdown while preserving type filters', function (): void {
    $employee = ledgerPageEmployee('Export');
    ledgerPageActor(['LEAVES'], true);
    app(RecordLeaveCredit::class)->handle(app(Access::class), $employee->id, 2026, 'SICK_LEAVE', 'CARRY_FORWARD', '2.5', 'Manually carried', (string) Str::ulid());
    Livewire::test(LeaveLedger::class)->call('employeePicked', 'ledger-employee', $employee->id)
        ->assertSee('Export CSV')->assertSeeHtml('/leaves/ledger/export');
    $history = $this->get('/leaves/ledger/export?employee='.$employee->id.'&year=2026&type=ALL')->assertOk()->streamedContent();
    expect($history)->toContain('Manually carried')->toContain('2025')->toContain($employee->employee_code)->toContain('Export Ledger');
    $filtered = $this->get('/leaves/ledger/export?employee='.$employee->id.'&year=2026&type=VACATION_LEAVE')->assertOk()->streamedContent();
    expect($filtered)->not->toContain('Manually carried');
    $summary = $this->get('/leaves/ledger/export?employee='.$employee->id.'&year=2026&section=balances')->assertOk()->streamedContent();
    expect($summary)->toContain('Opening balance')->toContain('Carried forward')->toContain('2.5');
});

it('hides ledger data from unlinked viewers and refuses direct components without their module', function (): void {
    ledgerPageActor(['PORTAL'], false);
    Livewire::test(LeaveLedger::class, ['own' => true])->assertSee('No employee record linked.')->assertDontSee('Record credits');
    Livewire::test(LeaveLedger::class)->assertForbidden();
    $this->get('/leaves/ledger/export')->assertRedirect();
});

it('invalidates a credit draft when the year or employee changes and refuses a cancelled draft', function (): void {
    $first = ledgerPageEmployee('First');
    $second = ledgerPageEmployee('Second');
    ledgerPageActor(['LEAVES'], true);
    $page = Livewire::test(LeaveLedger::class)->call('employeePicked', 'ledger-employee', $first->id)
        ->call('openEntry')->set('days', '5')->set('note', 'A staged allocation');
    $page->set('year', 2027)->assertSet('entryId', '')->call('saveEntry');
    $page->call('openEntry')->set('days', '5')->set('note', 'A staged allocation')
        ->call('employeePicked', 'ledger-employee', $second->id)
        ->call('employeePicked', 'ledger-employee', $first->id)->assertSet('entryId', '')->call('saveEntry');
    $page->call('openEntry')->set('days', '5')->set('note', 'A cancelled allocation')
        ->set('entryOpen', false)->call('saveEntry');
    expect(LeaveCreditEntry::query()->count())->toBe(0)
        ->and(LeaveCredit::query()->count())->toBe(0);
});
