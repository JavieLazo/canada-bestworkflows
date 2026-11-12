<?php

declare(strict_types=1);

use App\Livewire\Leaves\Index;
use App\Livewire\Portal\PortalPage;
use App\Models\AuditLog;
use App\Models\EmployeeProfile;
use App\Models\LeaveTravelRequest;
use App\Models\Notification;
use App\Models\Role;
use App\Models\RoleModuleGrant;
use App\Models\User;
use App\Modules\Timekeeping\Application\Commands\ReviewAbsence;
use App\Support\Access;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Once;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Support\ScheduleFixture;

/**
 * A disapproved leave says why. ReviewAbsence refuses a disapproval
 * (`REJECTED`) without a reason, keeps the reason on the request and sends
 * it to the employee; Leave Management and My Portal show it, and every
 * screen, export and sentence says "Disapproved" while the stored status
 * stays `REJECTED`. Before the column's migration has run, a disapproval
 * still lands and the employee's notice still carries the reason.
 */

/** @param  list<string>  $modules */
function ldisRole(array $modules, bool $allData): Role
{
    $role = new Role;
    $role->forceFill(['name' => 'Disapproval '.Str::random(6), 'all_data' => $allData, 'is_system' => false])->save();
    foreach ($modules as $module) {
        $grant = new RoleModuleGrant;
        $grant->forceFill(['role_id' => $role->getKey(), 'module' => $module])->save();
    }

    return $role;
}

/** @param  list<string>  $modules */
function ldisActor(array $modules, bool $allData = false, ?string $employeeId = null): User
{
    $user = User::factory()->create(['role_id' => ldisRole($modules, $allData)->getKey(), 'employee_id' => $employeeId]);
    test()->actingAs($user);
    app()->forgetInstance(Access::class);

    return $user;
}

function ldisEmployee(string $code, string $first, string $last): EmployeeProfile
{
    $employee = new EmployeeProfile;
    $employee->forceFill([
        'employee_code' => $code, 'first_name' => $first, 'last_name' => $last,
        'employment_status' => 'Regular', 'date_hired' => '2024-01-01', 'is_active' => true,
    ])->save();

    ScheduleFixture::standard($employee);

    return $employee;
}

/** @param  array<string, string>  $overrides */
function ldisRequest(EmployeeProfile $employee, string $code, array $overrides = []): LeaveTravelRequest
{
    $request = new LeaveTravelRequest;
    $request->forceFill(array_merge([
        'code' => $code, 'employee_id' => $employee->getKey(), 'request_type' => 'SICK', 'day_type' => 'WHOLE_DAY',
        'start_date' => '2026-08-10', 'end_date' => '2026-08-11', 'reason' => 'Flu', 'status' => 'PENDING',
    ], $overrides))->save();

    return $request;
}

/**
 * Runs `$run` as the host stands before `2026_11_08_0001` has run: the
 * column dropped FIRST, before anything is seeded, then restored through
 * the migration's own up(). On MariaDB the DDL commits RefreshDatabase's
 * transaction, so one is reopened around the run and again for the
 * rollback at the end (the testing guide).
 */
function ldisWithoutColumn(Closure $run): void
{
    $engine = in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true);
    $migration = require base_path('database/migrations/2026_11_08_0001_add_disapproval_reason_to_leave_travel_requests_table.php');
    $migration->down();
    Once::flush();
    if ($engine) {
        DB::connection()->getPdo()->beginTransaction();
    }

    try {
        $run();
    } finally {
        if ($engine) {
            DB::connection()->getPdo()->rollBack();
        }
        $migration->up();
        Once::flush();
        if ($engine) {
            DB::connection()->getPdo()->beginTransaction();
        }
    }
}

/*
|--------------------------------------------------------------------------
| The command
|--------------------------------------------------------------------------
*/

it('refuses a disapproval without a reason, or with one too long to keep, and changes nothing', function (string $reason, string $message) {
    $employee = ldisEmployee('EMP-001', 'Liza', 'Domingo');
    $request = ldisRequest($employee, 'LR-001');
    $manager = Access::forActor(ldisActor(['LEAVES'], allData: true));

    expect(fn () => app(ReviewAbsence::class)->handle($manager, $request->id, 'REJECTED', null, $reason))
        ->toThrow(RuntimeException::class, $message);

    $fresh = $request->fresh();
    expect($fresh->status)->toBe('PENDING')
        ->and($fresh->reviewed_by)->toBeNull()
        ->and($fresh->disapproval_reason)->toBeNull()
        ->and(AuditLog::query()->where('entity_id', $request->id)->exists())->toBeFalse();
})->with([
    'no reason' => ['', 'Say why the request is disapproved.'],
    'spaces only' => ["   \n ", 'Say why the request is disapproved.'],
    'too long' => [str_repeat('x', 1001), 'Keep the reason to 1000 characters.'],
]);

it('disapproves with the reason: kept on the request, audited as disapproved and sent to the employee', function () {
    $employee = ldisEmployee('EMP-001', 'Liza', 'Domingo');
    $request = ldisRequest($employee, 'LR-001');
    $requester = User::factory()->create(['role_id' => ldisRole(['PORTAL'], false)->getKey(), 'employee_id' => $employee->getKey()]);
    $manager = ldisActor(['LEAVES'], allData: true);

    $outcome = app(ReviewAbsence::class)->handle(Access::forActor($manager), $request->id, 'REJECTED', true, '  Not enough staff on those dates. ');

    $fresh = $request->fresh();
    expect($outcome)->toBe('disapproved')
        ->and($fresh->status)->toBe('REJECTED')
        ->and($fresh->is_paid)->toBeNull()
        ->and($fresh->reviewed_by)->toBe((int) $manager->getKey())
        ->and($fresh->disapproval_reason)->toBe('Not enough staff on those dates.');

    $audit = AuditLog::query()->where('entity_id', $request->id)->sole();
    expect($audit->action)->toBe('REQUEST_REJECTED')
        ->and($audit->details)->toBe('Request LR-001 disapproved');

    $notification = Notification::query()->where('user_id', $requester->getKey())->sole();
    expect($notification->title)->toBe('Request LR-001 disapproved')
        ->and($notification->body)->toBe('Disapproved due to: Not enough staff on those dates.')
        ->and($notification->href)->toBe('/portal');
});

it('keeps no reason on an approval, whatever was sent', function () {
    $employee = ldisEmployee('EMP-001', 'Liza', 'Domingo');
    $request = ldisRequest($employee, 'LR-001', ['request_type' => 'BUSINESS_TRAVEL']);
    $manager = Access::forActor(ldisActor(['LEAVES'], allData: true));

    app(ReviewAbsence::class)->handle($manager, $request->id, 'APPROVED', null, 'Ignored');

    expect($request->fresh()->status)->toBe('APPROVED')
        ->and($request->fresh()->disapproval_reason)->toBeNull();
});

it('still disapproves before the column exists, telling the employee why', function () {
    ldisWithoutColumn(function (): void {
        $employee = ldisEmployee('EMP-001', 'Liza', 'Domingo');
        $request = ldisRequest($employee, 'LR-001');
        $requester = User::factory()->create(['role_id' => ldisRole(['PORTAL'], false)->getKey(), 'employee_id' => $employee->getKey()]);
        $manager = Access::forActor(ldisActor(['LEAVES'], allData: true));

        expect(app(ReviewAbsence::class)->handle($manager, $request->id, 'REJECTED', null, 'Filed too late'))->toBe('disapproved')
            ->and(LeaveTravelRequest::query()->whereKey($request->id)->value('status'))->toBe('REJECTED')
            ->and(Notification::query()->where('user_id', $requester->getKey())->sole()->body)->toBe('Disapproved due to: Filed too late');
    });
});

it('adds the reason column twice without error and drops it on the way down', function () {
    $migration = require base_path('database/migrations/2026_11_08_0001_add_disapproval_reason_to_leave_travel_requests_table.php');
    $engine = in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true);

    try {
        $migration->down();
        $migration->down();
        expect(Schema::hasColumn('leave_travel_requests', 'disapproval_reason'))->toBeFalse();
        $migration->up();
        $migration->up();
        expect(Schema::hasColumn('leave_travel_requests', 'disapproval_reason'))->toBeTrue();
    } finally {
        $migration->up();
        Once::flush();
        if ($engine) {
            DB::connection()->getPdo()->beginTransaction();
        }
    }
});

/*
|--------------------------------------------------------------------------
| The screens
|--------------------------------------------------------------------------
*/

it('asks for the reason in the review dialog before it disapproves', function () {
    $employee = ldisEmployee('EMP-001', 'Liza', 'Domingo');
    $request = ldisRequest($employee, 'LR-001');
    ldisActor(['LEAVES'], allData: true);

    $page = Livewire::test(Index::class)
        ->call('show', $request->id)
        ->assertSeeHtml('data-disapprove')
        ->assertDontSeeHtml('data-disapproval-form')
        ->set('disapproving', true)
        ->assertSeeHtml('data-disapproval-form')
        ->assertSee('Disapproved due to')
        ->assertSeeHtml('data-required-mark')
        ->assertSee('(required)')
        ->call('review', 'REJECTED')
        ->assertSet('disapprovalError', 'Say why the request is disapproved.')
        ->assertSet('detailOpen', true);
    expect($request->fresh()->status)->toBe('PENDING')
        ->and(preg_match('/<textarea[^>]*\srequired[\s>=]/', $page->html()))->toBe(1)
        ->and(preg_match('/<textarea[^>]*\sdata-invalid[\s>=]/', $page->html()))->toBe(1);

    $page->set('disapprovalReason', 'Not enough staff on those dates.')
        ->call('review', 'REJECTED')
        ->assertSet('detailOpen', false)
        ->assertSet('disapproving', false)
        ->assertSet('disapprovalReason', '');
    expect($request->fresh()->status)->toBe('REJECTED')
        ->and($request->fresh()->disapproval_reason)->toBe('Not enough staff on those dates.');
});

it('forgets a half-typed reason when another request is opened', function () {
    $employee = ldisEmployee('EMP-001', 'Liza', 'Domingo');
    $first = ldisRequest($employee, 'LR-001');
    $second = ldisRequest($employee, 'LR-002', ['start_date' => '2026-08-17', 'end_date' => '2026-08-17']);
    ldisActor(['LEAVES'], allData: true);

    Livewire::test(Index::class)
        ->call('show', $first->id)
        ->set('disapproving', true)
        ->set('disapprovalReason', 'Meant for the first one')
        ->call('show', $second->id)
        ->assertSet('disapproving', false)
        ->assertSet('disapprovalReason', '');
});

it('shows a disapproved request as Disapproved with its reason in Leave Management, the CSV and My Portal', function () {
    $employee = ldisEmployee('EMP-001', 'Liza', 'Domingo');
    $request = ldisRequest($employee, 'LR-001', ['status' => 'REJECTED', 'disapproval_reason' => 'Not enough staff on those dates.']);
    ldisActor(['LEAVES'], allData: true);

    $page = Livewire::test(Index::class)
        ->assertSee('DISAPPROVED')
        ->call('show', $request->id)
        ->assertSeeHtml('data-decision>Disapproved</p>')
        ->assertSeeHtml('data-disapproval-reason>Not enough staff on those dates.</p>');
    expect($page->html())->not->toContain('>REJECTED<')
        ->not->toContain('Rejected');

    $csv = test()->get('/leaves/export')->assertOk()->streamedContent();
    expect($csv)->toContain('DISAPPROVED')
        ->not->toContain('REJECTED');

    ldisActor(['PORTAL'], employeeId: $employee->getKey());
    Livewire::test(PortalPage::class)->set('tab', 'requests')
        ->assertSee('DISAPPROVED')
        ->assertSeeHtml('data-disapproval-reason')
        ->assertSee('Not enough staff on those dates.');
});

it('says no reason was recorded for a request disapproved before reasons were kept', function () {
    $employee = ldisEmployee('EMP-001', 'Liza', 'Domingo');
    $request = ldisRequest($employee, 'LR-001', ['status' => 'REJECTED']);
    ldisActor(['LEAVES'], allData: true);

    Livewire::test(Index::class)
        ->call('show', $request->id)
        ->assertSee('No reason was recorded.')
        ->assertDontSeeHtml('data-disapproval-reason>');

    ldisActor(['PORTAL'], employeeId: $employee->getKey());
    Livewire::test(PortalPage::class)->set('tab', 'requests')
        ->assertSee('DISAPPROVED')
        ->assertDontSeeHtml('data-disapproval-reason');
});
