<?php

declare(strict_types=1);

use App\Domain\Enums\ModuleCode;
use App\Models\EmployeeEmploymentHistory;
use App\Models\EmployeeProfile;
use App\Models\EmploymentStatusOption;
use App\Models\Position;
use App\Models\Role;
use App\Models\RoleModuleGrant;
use App\Models\User;
use App\Models\WorkSite;
use App\Modules\Workforce\Application\Commands\UpdateEmployee;
use App\Modules\Workforce\Application\EmployeeFields;
use App\Modules\Workforce\Application\EmploymentHistoryRecorder;
use App\Modules\Workforce\Application\Queries\EmploymentHistoryRecords;
use App\Support\Access;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Employment change reports expose only changed facts and never infer missing evidence from today's profile. */
function ehChangesEmployee(string $code, ?string $site = null): EmployeeProfile
{
    return EmployeeProfile::query()->create([
        'employee_code' => $code, 'first_name' => 'History', 'last_name' => $code, 'suffix' => 'Jr.',
        'position' => 'Driver', 'position_level' => 'Level one', 'employment_status' => 'Regular',
        'date_hired' => '2024-01-01', 'monthly_rate' => 25000, 'daily_rate' => null,
        'work_site_id' => $site, 'is_active' => true,
    ]);
}

/** @param list<string> $modules */
function ehChangesActor(array $modules = [ModuleCode::EMPLOYEES], bool $all = true, ?EmployeeProfile $employee = null): Access
{
    $role = Role::query()->create(['name' => 'Changes '.Str::random(10), 'all_data' => $all, 'is_system' => false]);
    foreach ($modules as $module) {
        RoleModuleGrant::query()->create(['role_id' => $role->id, 'module' => $module]);
    }

    return Access::forActor(User::factory()->create(['role_id' => $role->id, 'employee_id' => $employee?->id]));
}

/** @param array<string, mixed> $overrides */
function ehChangesRow(EmployeeProfile $employee, string $time, array $overrides = []): EmployeeEmploymentHistory
{
    $row = new EmployeeEmploymentHistory;
    $row->forceFill($overrides + [
        'employee_id' => $employee->id, 'event_type' => 'UPDATED', 'effective_date' => '2026-10-01',
        'changed_fields' => 'monthly_rate', 'position' => $employee->position,
        'position_level' => $employee->position_level, 'employment_status' => $employee->employment_status,
        'date_hired' => '2024-01-01', 'monthly_rate' => 30000, 'daily_rate' => null,
        'work_site_id' => $employee->work_site_id, 'is_active' => true,
        'created_at' => $time,
    ])->save();

    return $row;
}

it('captures normalized previous values inside the existing employee update transaction', function () {
    Position::query()->create(['code' => 'CHANGE-POS', 'name' => 'Driver']);
    EmploymentStatusOption::query()->create(['code' => 'CHANGE-STATUS', 'name' => 'Regular']);
    $site = WorkSite::query()->create(['name' => 'Original site']);
    $employee = ehChangesEmployee('CHANGE-CAPTURE', $site->id);
    $actor = ehChangesActor();
    app(UpdateEmployee::class)->handle($actor, $employee->id, 'employment', [
        'monthly_rate' => '28000.50', 'daily_rate' => '1200', 'is_active' => false,
        'position_level' => 'Level two', 'date_regular' => '2024-07-01',
    ] + EmployeeFields::toForm($employee));

    $row = EmployeeEmploymentHistory::query()->sole();
    expect($row->previous_values)->toMatchArray([
        'monthly_rate' => '25000.00', 'daily_rate' => null, 'is_active' => '1',
        'position_level' => 'Level one', 'date_hired' => '2024-01-01', 'date_regular' => null,
        'work_site_id' => $site->id, 'work_site_name' => 'Original site',
    ])->and($row->monthly_rate)->toBe(28000.5);
    $view = app(EmploymentHistoryRecords::class)->page($actor)->getCollection()->sole();
    expect($view->changes)->toHaveCount(5)
        ->and(array_column($view->changes, 'field'))->not->toContain('position', 'date_hired', 'work_site_id')
        ->and(collect($view->changes)->firstWhere('field', 'monthly_rate'))->toMatchArray([
            'before' => '25000.00', 'after' => '28000.50', 'before_known' => true,
        ]);

    app(UpdateEmployee::class)->handle($actor, $employee->id, 'employment', EmployeeFields::toForm($employee->refresh()));
    expect(EmployeeEmploymentHistory::query()->count())->toBe(1);
});

it('captures a renamed former worksite before transfer and preserves both names after later renames', function () {
    Position::query()->create(['code' => 'CHANGE-SITE-POS', 'name' => 'Driver']);
    EmploymentStatusOption::query()->create(['code' => 'CHANGE-SITE-STATUS', 'name' => 'Regular']);
    $old = WorkSite::query()->create(['name' => 'Name at hire']);
    $new = WorkSite::query()->create(['name' => 'Destination at transfer']);
    $employee = ehChangesEmployee('CHANGE-SITE', $old->id);
    $actor = ehChangesActor();
    app(EmploymentHistoryRecorder::class)->hired($actor, $employee);
    $old->update(['name' => 'Name before transfer']);
    app(UpdateEmployee::class)->handle($actor, $employee->id, 'employment',
        ['work_site_id' => $new->id] + EmployeeFields::toForm($employee));
    $old->update(['name' => 'Former site renamed later']);
    $new->update(['name' => 'Destination renamed later']);

    $change = app(EmploymentHistoryRecords::class)->page($actor)->getCollection()->sole()->changes[0];
    expect($change)->toMatchArray(['field' => 'work_site_id', 'before' => 'Name before transfer',
        'after' => 'Destination at transfer', 'before_known' => true, 'after_known' => true]);
});

it('scopes paginated and exported changes to employees access before applying filters', function () {
    $own = ehChangesEmployee('CHANGE-OWN');
    $other = ehChangesEmployee('CHANGE-OTHER');
    foreach ([$own, $other] as $employee) {
        ehChangesRow($employee, '2026-10-01 01:00:00.000');
    }
    $query = app(EmploymentHistoryRecords::class);
    $owner = ehChangesActor(all: false, employee: $own);
    expect($query->page($owner)->total())->toBe(1)
        ->and($query->page($owner)->getCollection()->sole()->employee_id)->toBe($own->id)
        ->and($query->page($owner, $other->id)->total())->toBe(0)
        ->and(iterator_to_array($query->rows($owner, $other->id)))->toBe([])
        ->and($query->page(ehChangesActor(all: false))->total())->toBe(0)
        ->and($query->page(ehChangesActor([ModuleCode::PAYROLL]))->total())->toBe(0)
        ->and($query->page(Access::forGuest())->total())->toBe(0)
        ->and($query->page(ehChangesActor())->total())->toBe(2);
    $payroll = ehChangesActor([ModuleCode::PAYROLL], true, $own);
    expect($query->page($payroll)->total())->toBe(0)
        ->and(iterator_to_array($query->rows($payroll)))->toBe([])
        ->and($query->forEmployee($payroll, $own->id))->toBeEmpty()
        ->and($query->page($payroll, $other->id)->total())->toBe(0);
});

it('compares a legacy change to the immediate predecessor outside the page date and field filters', function () {
    $employee = ehChangesEmployee('CHANGE-LEGACY');
    ehChangesRow($employee, '2026-09-29 01:00:00.000', ['event_type' => 'BASELINE', 'monthly_rate' => 25000]);
    ehChangesRow($employee, '2026-09-30 01:00:00.000', ['monthly_rate' => 28000]);
    $last = ehChangesRow($employee, '2026-10-01 01:00:00.000', [
        'changed_fields' => 'monthly_rate,position_level', 'monthly_rate' => 31000, 'position_level' => 'Level two',
    ]);
    $query = app(EmploymentHistoryRecords::class);
    $actor = ehChangesActor();
    $page = $query->page($actor, $employee->id, '2026-10-01', '2026-10-01', 'monthly_rate', 99, 1);
    expect($page->total())->toBe(1)->and($page->currentPage())->toBe(1);
    $view = $page->getCollection()->sole();
    expect($view->id)->toBe($last->id)
        ->and($view->employee_name)->toBe('History CHANGE-LEGACY Jr.')
        ->and($view->changes)->toHaveCount(1)
        ->and($view->changes[0])->toMatchArray(['field' => 'monthly_rate', 'before' => '28000.00', 'after' => '31000.00', 'before_known' => true]);
    expect(iterator_to_array($query->rows($actor, $employee->id, '2026-10-01', '2026-10-01', 'monthly_rate'))[0]->changes)
        ->toBe($view->changes);
    expect($query->page($actor, perPage: 1, page: 2)->getCollection()->sole()->changes[0]['before'])->toBe('25000.00');
});

it('orders changes by recorded time and id instead of their historical effective dates', function () {
    $employee = ehChangesEmployee('CHANGE-ORDER');
    ehChangesRow($employee, '2026-09-01 01:00:00.000', ['event_type' => 'HIRED']);
    $earlier = ehChangesRow($employee, '2026-10-01 01:00:00.000', ['id' => str_repeat('0', 25).'1', 'monthly_rate' => 28000, 'effective_date' => '2099-01-01']);
    $later = ehChangesRow($employee, '2026-10-01 01:00:00.000', ['id' => str_repeat('0', 25).'2', 'monthly_rate' => 30000, 'effective_date' => '2000-01-01']);
    $query = app(EmploymentHistoryRecords::class);
    $actor = ehChangesActor();
    expect($query->page($actor)->getCollection()->pluck('id')->all())->toBe([$later->id, $earlier->id])
        ->and($query->page($actor)->getCollection()->first()->changes[0]['before'])->toBe('28000.00');
});

it('uses inclusive Manila day bounds for recorded dates without losing the last millisecond', function () {
    $employee = ehChangesEmployee('CHANGE-DATES');
    $before = ehChangesRow($employee, '2026-09-30 15:59:59.999');
    $start = ehChangesRow($employee, '2026-09-30 16:00:00.000');
    $end = ehChangesRow($employee, '2026-10-01 15:59:59.999');
    $after = ehChangesRow($employee, '2026-10-01 16:00:00.000');
    $page = app(EmploymentHistoryRecords::class)->page(ehChangesActor(), from: '2026-10-01', to: '2026-10-01');
    expect($page->getCollection()->pluck('id')->all())->toBe([$end->id, $start->id])
        ->not->toContain($before->id, $after->id);
});

it('leaves unknown before values explicit when legacy evidence is missing or discontinuous', function () {
    $missing = ehChangesEmployee('CHANGE-MISSING');
    ehChangesRow($missing, '2026-10-01 01:00:00.000');
    $gap = ehChangesEmployee('CHANGE-GAP');
    ehChangesRow($gap, '2026-09-01 01:00:00.000', ['event_type' => 'BASELINE', 'monthly_rate' => 25000]);
    ehChangesRow($gap, '2026-10-01 01:00:00.000', ['position' => 'An unrecorded new position']);
    $query = app(EmploymentHistoryRecords::class);
    $actor = ehChangesActor();
    foreach ([$missing, $gap] as $employee) {
        $change = $query->page($actor, $employee->id)->getCollection()->sole()->changes[0];
        expect($change['before_known'])->toBeFalse()->and($change['before'])->toBeNull()
            ->and($change['after'])->toBe('30000.00');
    }
});

it('never resolves a missing historical worksite name from current reference data', function () {
    $old = WorkSite::query()->create(['name' => 'Current name is not evidence']);
    $new = WorkSite::query()->create(['name' => 'Current destination is not evidence']);
    $employee = ehChangesEmployee('CHANGE-UNKNOWN-SITE', $new->id);
    ehChangesRow($employee, '2026-09-01 01:00:00.000', ['event_type' => 'BASELINE', 'work_site_id' => $old->id]);
    ehChangesRow($employee, '2026-10-01 01:00:00.000', ['changed_fields' => 'work_site_id']);
    $change = app(EmploymentHistoryRecords::class)->page(ehChangesActor())->getCollection()->sole()->changes[0];
    expect($change)->toMatchArray(['before' => null, 'after' => null, 'before_known' => false, 'after_known' => false]);
});

it('leaves legacy before values unknown when a declared change contradicts the predecessor', function () {
    $employee = ehChangesEmployee('CHANGE-CONTRADICTION');
    ehChangesRow($employee, '2026-09-01 01:00:00.000', ['event_type' => 'BASELINE', 'monthly_rate' => 25000]);
    ehChangesRow($employee, '2026-10-01 01:00:00.000', [
        'changed_fields' => 'monthly_rate,position_level', 'monthly_rate' => 25000, 'position_level' => 'Level two',
    ]);

    $changes = app(EmploymentHistoryRecords::class)->page(ehChangesActor())->getCollection()->sole()->changes;

    expect($changes)->toHaveCount(2);
    foreach ($changes as $change) {
        expect($change['before_known'])->toBeFalse()->and($change['before'])->toBeNull();
    }
    expect(collect($changes)->firstWhere('field', 'monthly_rate')['after'])->toBe('25000.00');
});

it('does not present a legacy predecessor worksite name as the exact name before a transfer', function () {
    $old = WorkSite::query()->create(['name' => 'Name at baseline']);
    $new = WorkSite::query()->create(['name' => 'Destination']);
    $employee = ehChangesEmployee('CHANGE-LEGACY-RENAME', $new->id);
    ehChangesRow($employee, '2026-09-01 01:00:00.000', [
        'event_type' => 'BASELINE', 'work_site_id' => $old->id, 'work_site_name' => 'Name at baseline',
    ]);
    $old->update(['name' => 'Name when employee transferred']);
    ehChangesRow($employee, '2026-10-01 01:00:00.000', [
        'changed_fields' => 'work_site_id', 'work_site_name' => 'Destination',
    ]);

    $query = app(EmploymentHistoryRecords::class);
    $actor = ehChangesActor();
    $change = $query->page($actor, $employee->id)->getCollection()->sole()->changes[0];
    expect($change)->toMatchArray([
        'before' => null, 'before_known' => false, 'after' => 'Destination', 'after_known' => true,
    ]);

    $unassigned = ehChangesEmployee('CHANGE-LEGACY-NO-SITE', $new->id);
    ehChangesRow($unassigned, '2026-09-01 01:00:00.000', [
        'event_type' => 'BASELINE', 'work_site_id' => null, 'work_site_name' => null,
    ]);
    ehChangesRow($unassigned, '2026-10-01 01:00:00.000', [
        'changed_fields' => 'work_site_id', 'work_site_name' => 'Destination',
    ]);
    expect($query->page($actor, $unassigned->id)->getCollection()->sole()->changes[0])->toMatchArray([
        'before' => null, 'before_known' => true, 'after' => 'Destination', 'after_known' => true,
    ]);
});

it('rejects invalid report filters before returning a streaming iterator', function (string $from, string $to, string $field, string $errorKey) {
    $query = app(EmploymentHistoryRecords::class);
    $actor = ehChangesActor();
    foreach (['page', 'rows'] as $method) {
        try {
            $query->{$method}($actor, from: $from, to: $to, field: $field);
            test()->fail('Invalid filters were accepted.');
        } catch (ValidationException $error) {
            expect($error->errors())->toHaveKey($errorKey);
        }
    }
})->with([
    'impossible date' => ['2026-02-30', '', '', 'from'],
    'relative date' => ['', 'tomorrow', '', 'to'],
    'reversed range' => ['2026-10-02', '2026-10-01', '', 'to'],
    'nonemployment field' => ['', '', 'address', 'field'],
]);

it('batches comparison reads while keeping pagination in SQL', function (int $count) {
    $employee = ehChangesEmployee('CHANGE-BATCH');
    ehChangesRow($employee, '2026-09-01 01:00:00.000', ['event_type' => 'BASELINE', 'monthly_rate' => 25000]);
    for ($i = 0; $i < $count; $i++) {
        ehChangesRow($employee, '2026-10-01 01:00:00.'.str_pad((string) $i, 3, '0', STR_PAD_LEFT), ['monthly_rate' => 26000 + $i]);
    }
    $actor = ehChangesActor();
    DB::enableQueryLog();
    DB::flushQueryLog();
    $page = app(EmploymentHistoryRecords::class)->page($actor, perPage: 20);
    $queries = DB::getQueryLog();
    DB::disableQueryLog();
    expect($page->total())->toBe($count)->and($page->count())->toBe(min($count, 20))
        ->and(count($queries))->toBeLessThanOrEqual(5);
    $sql = implode(' ', array_column($queries, 'query'));
    expect(strtolower($sql))->toContain('limit 20');
})->with(['one event' => 1, 'thirty events' => 30]);

it('streams every report event once across keyset batches with deterministic ties', function () {
    $employee = ehChangesEmployee('CHANGE-STREAM');
    for ($i = 0; $i < 205; $i++) {
        ehChangesRow($employee, '2026-10-01 01:00:00.000', [
            'previous_values' => ['monthly_rate' => '25000.00'], 'monthly_rate' => 26000 + $i,
        ]);
    }
    $query = app(EmploymentHistoryRecords::class);
    $actor = ehChangesActor();
    $rows = iterator_to_array($query->rows($actor));
    expect($rows)->toHaveCount(205)->and(collect($rows)->pluck('id')->unique())->toHaveCount(205);
    $ids = array_map(static fn ($row): string => $row->id, $rows);
    $sorted = $ids;
    rsort($sorted);
    expect($ids)->toBe($sorted);
});
