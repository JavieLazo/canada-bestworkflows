<?php

declare(strict_types=1);

use App\Domain\Enums\ModuleCode;
use App\Models\EmployeeProfile;
use App\Models\LeaveCredit;
use App\Models\LeaveCreditEntry;
use App\Models\LeaveTravelRequest;
use App\Models\LeaveType;
use App\Models\Role;
use App\Models\RoleModuleGrant;
use App\Models\User;
use App\Modules\Timekeeping\Application\Queries\LeaveCreditRecords;
use App\Modules\Timekeeping\Application\Queries\LeaveLedgerRecords;
use App\Modules\Timekeeping\Contracts\Views\LeaveLedgerBalanceView;
use App\Modules\Timekeeping\Contracts\Views\LeaveLedgerEntryView;
use App\Support\Access;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Ledger reads must preserve established available credits and start-year
 * charging, expose paid/unpaid usage separately from future bookings, and
 * scope summaries, paginated history and exports before reading employee data.
 */
function llrEmployee(): EmployeeProfile
{
    $employee = new EmployeeProfile;
    $employee->forceFill([
        'employee_code' => 'LLR-'.Str::upper(Str::random(8)),
        'first_name' => 'Ledger', 'last_name' => 'Employee',
        'employment_status' => 'Regular', 'date_hired' => '2024-01-01', 'is_active' => true,
    ])->save();

    return $employee;
}

/** @param list<string> $modules */
function llrActor(array $modules = [ModuleCode::LEAVES], bool $all = true, ?EmployeeProfile $employee = null): Access
{
    $role = new Role;
    $role->forceFill(['name' => 'Ledger '.Str::random(8), 'all_data' => $all, 'is_system' => false])->save();
    foreach ($modules as $module) {
        $grant = new RoleModuleGrant;
        $grant->forceFill(['role_id' => $role->getKey(), 'module' => $module])->save();
    }
    $user = User::factory()->create(['role_id' => $role->getKey(), 'employee_id' => $employee?->getKey()]);
    test()->actingAs($user);
    app()->forgetInstance(Access::class);

    return Access::forActor($user);
}

function llrType(string $name = 'Vacation Leave'): void
{
    $type = new LeaveType;
    $type->forceFill(['code' => 'LLR-'.Str::upper(Str::random(8)), 'name' => $name])->save();
}

function llrCredit(EmployeeProfile $employee, float $days, string $type = 'VACATION_LEAVE', int $year = 2026): void
{
    $credit = new LeaveCredit;
    $credit->forceFill(['employee_id' => $employee->getKey(), 'year' => $year, 'request_type' => $type, 'credits' => $days])->save();
}

/** @param array<string, mixed> $extra */
function llrEntry(EmployeeProfile $employee, string $kind, float $days, array $extra = []): LeaveCreditEntry
{
    $entry = new LeaveCreditEntry;
    $entry->forceFill(array_merge([
        'employee_id' => $employee->getKey(), 'year' => 2026,
        'request_type' => 'VACATION_LEAVE', 'kind' => $kind, 'days' => $days,
        'note' => 'HR entry', 'source_year' => $kind === 'CARRY_FORWARD' ? 2025 : null,
    ], $extra))->save();

    return $entry;
}

/** @param array<string, mixed> $extra */
function llrRequest(EmployeeProfile $employee, string $start, ?string $end = null, array $extra = []): void
{
    $request = new LeaveTravelRequest;
    $request->forceFill(array_merge([
        'code' => 'LLR-'.Str::upper(Str::random(8)), 'employee_id' => $employee->getKey(),
        'request_type' => 'VACATION_LEAVE', 'start_date' => $start, 'end_date' => $end ?? $start,
        'day_type' => 'WHOLE_DAY', 'status' => 'APPROVED', 'is_paid' => true,
    ], $extra))->save();
}

/** @return array<string, LeaveLedgerBalanceView> */
function llrRows(Access $actor, EmployeeProfile $employee, int $year = 2026): array
{
    $rows = [];
    foreach (app(LeaveLedgerRecords::class)->summary($actor, (string) $employee->getKey(), $year) as $row) {
        $rows[$row->request_type] = $row;
    }

    return $rows;
}

it('splits manually recorded credits and approved taken or booked days without consuming unpaid leave', function () {
    $this->travelTo(new DateTimeImmutable('2026-10-02T10:00:00+08:00'));
    llrType();
    $employee = llrEmployee();
    llrCredit($employee, 14.0);
    foreach ([['BASELINE', 8.0], ['OPENING', 2.0], ['ACCRUAL', 1.5], ['CARRY_FORWARD', 3.0], ['ADJUSTMENT', -0.5]] as [$kind, $days]) {
        llrEntry($employee, $kind, $days);
    }
    llrRequest($employee, '2026-01-01', '2026-01-02');
    llrRequest($employee, '2026-09-30', '2026-10-03', ['day_type' => 'HALF_DAY']);
    llrRequest($employee, '2026-10-01', '2026-10-04', ['is_paid' => false]);
    llrRequest($employee, '2026-10-02', null, ['is_paid' => null]);
    foreach (['PENDING', 'REJECTED', 'WITHDRAWN'] as $status) {
        llrRequest($employee, '2026-07-01', '2026-07-10', ['status' => $status]);
    }
    llrRequest($employee, '2026-01-01', '2026-01-10', ['request_type' => 'BUSINESS_TRAVEL', 'is_paid' => null]);

    $actor = llrActor();
    $row = llrRows($actor, $employee)['VACATION_LEAVE'];
    expect([$row->opening, $row->accrued, $row->carried, $row->adjustments, $row->credited])->toBe([10.0, 1.5, 3.0, -0.5, 14.0])
        ->and([$row->paid_taken, $row->unpaid_taken, $row->booked_paid, $row->booked_unpaid])->toBe([4.5, 2.0, 0.5, 2.0])
        ->and([$row->closing, $row->available])->toBe([9.5, 9.0])
        ->and($row->available)->toBe(app(LeaveCreditRecords::class)->balances($actor, (string) $employee->getKey(), 2026)[0]->remaining);
});

it('shows approved unpaid and deleted request types without inventing credits or counting official business', function () {
    $this->travelTo(new DateTimeImmutable('2026-10-02T10:00:00+08:00'));
    llrType();
    llrType('Leave Without Pay');
    $employee = llrEmployee();
    llrRequest($employee, '2026-09-01', '2026-09-02', ['request_type' => 'LEAVE_WITHOUT_PAY', 'is_paid' => false]);
    llrRequest($employee, '2026-10-04', null, ['request_type' => 'DELETED_TYPE', 'is_paid' => false]);
    llrRequest($employee, '2026-09-01', null, ['request_type' => 'BUSINESS_TRAVEL', 'is_paid' => null]);
    $rows = llrRows(llrActor(), $employee);

    expect(array_keys($rows))->toBe(['VACATION_LEAVE', 'DELETED_TYPE', 'LEAVE_WITHOUT_PAY'])
        ->and($rows['LEAVE_WITHOUT_PAY']->label)->toBe('Leave Without Pay')
        ->and($rows['LEAVE_WITHOUT_PAY']->unpaid_taken)->toBe(2.0)
        ->and($rows['DELETED_TYPE']->booked_unpaid)->toBe(1.0)
        ->and($rows['LEAVE_WITHOUT_PAY']->credited)->toBeNull()
        ->and($rows['LEAVE_WITHOUT_PAY']->closing)->toBeNull()
        ->and($rows['LEAVE_WITHOUT_PAY']->available)->toBeNull();
});

it('keeps a New Year range entirely in its start year while splitting elapsed and future half days', function () {
    $this->travelTo(new DateTimeImmutable('2027-01-01T10:00:00+08:00'));
    llrType();
    $employee = llrEmployee();
    llrCredit($employee, 10.0);
    llrCredit($employee, 12.0, year: 2027);
    llrRequest($employee, '2026-12-31', '2027-01-02', ['day_type' => 'HALF_DAY']);
    $actor = llrActor();
    $previous = llrRows($actor, $employee)['VACATION_LEAVE'];
    $current = llrRows($actor, $employee, 2027)['VACATION_LEAVE'];

    expect([$previous->paid_taken, $previous->booked_paid, $previous->closing, $previous->available])->toBe([1.0, 0.5, 9.0, 8.5])
        ->and([$current->paid_taken, $current->booked_paid, $current->closing, $current->available])->toBe([0.0, 0.0, 12.0, 12.0]);
});

it('preserves exact-token and suffix charging even when a cleared old credit still has history', function () {
    $this->travelTo(new DateTimeImmutable('2026-10-02T10:00:00+08:00'));
    llrType('Sick Leave');
    $fallback = llrEmployee();
    $exact = llrEmployee();
    foreach ([$fallback, $exact] as $employee) {
        llrCredit($employee, 10.0, 'SICK_LEAVE');
        llrRequest($employee, '2026-09-01', '2026-09-02', ['request_type' => 'SICK']);
        llrRequest($employee, '2026-11-01', null, ['request_type' => 'SICK_LEAVE']);
    }
    llrEntry($fallback, 'OPENING', 5.0, ['request_type' => 'SICK']);
    llrEntry($fallback, 'ADJUSTMENT', -5.0, ['request_type' => 'SICK']);
    llrCredit($exact, 5.0, 'SICK');
    $actor = llrActor();
    $fallbackRows = llrRows($actor, $fallback);
    $exactRows = llrRows($actor, $exact);

    expect($fallbackRows['SICK_LEAVE']->available)->toBe(7.0)
        ->and($fallbackRows['SICK_LEAVE']->paid_taken)->toBe(2.0)
        ->and($fallbackRows['SICK']->label)->toBe('Sick Leave (SICK)')
        ->and($fallbackRows['SICK']->paid_taken)->toBe(0.0)
        ->and($fallbackRows['SICK']->credited)->toBeNull()
        ->and($exactRows['SICK_LEAVE']->available)->toBe(9.0)
        ->and($exactRows['SICK']->available)->toBe(3.0);
    foreach ([$fallback, $exact] as $employee) {
        $rows = llrRows($actor, $employee);
        foreach (app(LeaveCreditRecords::class)->balances($actor, (string) $employee->getKey(), 2026) as $old) {
            expect($rows[$old->request_type]->available)->toBe($old->remaining)
                ->and($rows[$old->request_type]->paid_taken + $rows[$old->request_type]->booked_paid)->toBe($old->used);
        }
    }
});

it('treats an unhistoried aggregate as its opening while leaving an unentered type blank', function () {
    llrType();
    llrType('Sick Leave');
    $employee = llrEmployee();
    llrCredit($employee, 15.0);
    $rows = llrRows(llrActor(), $employee);

    expect([$rows['VACATION_LEAVE']->opening, $rows['VACATION_LEAVE']->accrued, $rows['VACATION_LEAVE']->carried])->toBe([15.0, 0.0, 0.0])
        ->and($rows['SICK_LEAVE']->opening)->toBe(0.0)
        ->and($rows['SICK_LEAVE']->credited)->toBeNull();
});

it('scopes every direct read and pins own ledgers even for all-data holders', function () {
    llrType();
    $own = llrEmployee();
    $other = llrEmployee();
    llrCredit($own, 4.0);
    llrCredit($other, 19.0);
    llrEntry($own, 'OPENING', 4.0);
    llrEntry($other, 'OPENING', 19.0);
    $query = app(LeaveLedgerRecords::class);
    $admin = llrActor(employee: $own);
    expect($query->own($admin, 2026)[0]->credited)->toBe(4.0)
        ->and(llrRows($admin, $other)['VACATION_LEAVE']->credited)->toBe(19.0);

    foreach ([llrActor(all: false, employee: $own), llrActor([ModuleCode::PORTAL], employee: $own)] as $actor) {
        expect(llrRows($actor, $own)['VACATION_LEAVE']->credited)->toBe(4.0)
            ->and($query->summary($actor, (string) $other->getKey(), 2026))->toBe([])
            ->and($query->page($actor, (string) $other->getKey(), 2026, '')->total())->toBe(0)
            ->and(iterator_to_array($query->entries($actor, (string) $other->getKey(), 2026, '')))->toBe([]);
    }
    foreach ([Access::forGuest(), llrActor([ModuleCode::PORTAL]), llrActor(all: false)] as $actor) {
        expect($query->own($actor, 2026))->toBe([])
            ->and($query->summary($actor, (string) $own->getKey(), 2026))->toBe([])
            ->and($query->page($actor, (string) $own->getKey(), 2026, '')->total())->toBe(0)
            ->and(iterator_to_array($query->entries($actor, (string) $own->getKey(), 2026, '')))->toBe([]);
    }
    foreach ([1999, 2101] as $year) {
        expect($query->summary($admin, (string) $own->getKey(), $year))->toBe([])
            ->and($query->page($admin, (string) $own->getKey(), $year, '')->total())->toBe(0)
            ->and(iterator_to_array($query->entries($admin, (string) $own->getKey(), $year, '')))->toBe([]);
    }
});

it('paginates credit history in the database and exports the same filtered detached rows in order', function () {
    llrType();
    $employee = llrEmployee();
    $other = llrEmployee();
    $ids = [];
    for ($day = 1; $day <= 5; $day++) {
        $ids[] = (string) llrEntry($employee, 'ACCRUAL', 0.5, [
            'created_at' => CarbonImmutable::parse(sprintf('2026-09-%02dT00:00:00Z', $day)),
        ])->getKey();
    }
    llrEntry($employee, 'OPENING', 10.0, ['year' => 2025]);
    llrEntry($employee, 'OPENING', 2.0, ['request_type' => 'SICK_LEAVE']);
    llrEntry($other, 'OPENING', 20.0);
    $actor = llrActor();
    $query = app(LeaveLedgerRecords::class);

    DB::enableQueryLog();
    DB::flushQueryLog();
    try {
        $page = $query->page($actor, (string) $employee->getKey(), 2026, 'VACATION_LEAVE', 2, 2);
        $sql = array_column(DB::getQueryLog(), 'query');
    } finally {
        DB::disableQueryLog();
    }
    expect($page->total())->toBe(5)
        ->and($page->currentPage())->toBe(2)
        ->and($page->getPageName())->toBe('ledgerPage')
        ->and($page->getCollection()->pluck('id')->all())->toBe([$ids[2], $ids[1]])
        ->and(implode('\n', $sql))->toContain('limit 2 offset 2');
    $last = $query->page($actor, (string) $employee->getKey(), 2026, 'VACATION_LEAVE', 99, 2);
    expect($last->currentPage())->toBe(3)
        ->and($last->getCollection()->pluck('id')->all())->toBe([$ids[0]]);

    $export = iterator_to_array($query->entries($actor, (string) $employee->getKey(), 2026, 'VACATION_LEAVE'));
    expect($query->page($actor, (string) $employee->getKey(), 2026, 'ALL')->total())->toBe(6)
        ->and(iterator_to_array($query->entries($actor, (string) $employee->getKey(), 2026, 'ALL')))->toHaveCount(6);
    expect(array_map(static fn (LeaveLedgerEntryView $row): string => $row->id, $export))->toBe(array_reverse($ids))
        ->and($export[0]->label)->toBe('Vacation Leave')
        ->and($export[0]->recorded_at->format('Y-m-d H:i:s'))->toBe('2026-09-05 00:00:00')
        ->and($export[0]->toArray())->not->toHaveKey('recorded_by');
});
