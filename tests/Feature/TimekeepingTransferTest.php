<?php

declare(strict_types=1);

use App\Domain\Enums\ModuleCode;
use App\Livewire\Kit\CsvImport;
use App\Livewire\Leaves\LeaveCredits;
use App\Models\EmployeeProfile;
use App\Models\LeaveCredit;
use App\Models\LeaveType;
use App\Models\Role;
use App\Models\RoleModuleGrant;
use App\Models\User;
use App\Platform\Transfer\TableTransfers;
use App\Support\Access;
use App\Support\Csv;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Leave credits travel as CSV keyed by the employee's ID: the file carries
 * the days CREDITED per type and year beside the remaining days the tab
 * shows. EXPORT ONLY: leave credits have no CSV import, so no importer is
 * registered for the table, the shared import dialog refuses it, and the
 * tab offers Export CSV and no Import CSV. (The Shift Roster has no CSV
 * export or import; Timekeeping's other transfer is Shift Types, in
 * RecordsTransferTest.)
 */

/** @param list<string> $modules */
function tkxferActor(array $modules, ?string $employeeId = null): User
{
    $role = new Role;
    $role->forceFill(['name' => 'Tk transfer '.Str::random(6), 'all_data' => true, 'is_system' => false])->save();
    foreach ($modules as $module) {
        $grant = new RoleModuleGrant;
        $grant->forceFill(['role_id' => $role->getKey(), 'module' => $module])->save();
    }
    $user = User::factory()->create(['role_id' => $role->getKey(), 'employee_id' => $employeeId]);
    test()->actingAs($user);
    app()->forgetInstance(Access::class);

    return $user;
}

function tkxferEmployee(string $code, string $first): EmployeeProfile
{
    $employee = new EmployeeProfile;
    $employee->forceFill([
        'employee_code' => $code, 'first_name' => $first, 'last_name' => 'Santos',
        'employment_status' => 'Regular', 'date_hired' => '2024-01-01', 'is_active' => true,
    ])->save();

    return $employee;
}

/** @return list<array<string, string>> */
function tkxferRows(string $url): array
{
    $csv = test()->get($url)->assertOk()->streamedContent();
    $lines = array_map(static fn (string $line): array => str_getcsv($line, ',', '"', ''), explode("\r\n", substr($csv, strlen(Csv::BOM))));
    $headers = array_map('strval', array_shift($lines));

    return array_map(static fn (array $cells): array => array_combine($headers, array_map('strval', $cells)), $lines);
}

/* |---| Leave credits |---| */

beforeEach(function () {
    // A fixed date, so the year the export is asked for is the current one.
    $this->travelTo(new DateTimeImmutable('2026-09-30T06:00:00+08:00'));
    foreach (['Sick Leave', 'Vacation Leave'] as $index => $name) {
        $type = new LeaveType;
        $type->forceFill(['code' => (string) ($index + 1), 'name' => $name])->save();
    }
});

it('exports the credits on the books and the balance per type as of a day, as credit figures, blank where no credit was entered', function () {
    $ana = tkxferEmployee('101', 'Ana');
    tkxferEmployee('102', 'Ben');
    $credit = new LeaveCredit;
    $credit->forceFill(['employee_id' => (string) $ana->getKey(), 'year' => 2026, 'request_type' => 'SICK_LEAVE', 'credits' => 7.5])->save();
    tkxferActor([ModuleCode::LEAVES]);

    $rows = tkxferRows('/leaves/credits/export?asof=2026-09-15');
    expect(array_keys($rows[0]))->toBe(['ID', 'Employee', 'As of', 'Sick Leave', 'Sick Leave available balance', 'Vacation Leave', 'Vacation Leave available balance'])
        ->and(array_column($rows, 'ID'))->toBe(['101', '102'])
        ->and(array_column($rows, 'As of'))->toBe(['2026-09-15', '2026-09-15'])
        ->and([$rows[0]['Sick Leave'], $rows[0]['Sick Leave available balance'], $rows[0]['Vacation Leave']])->toBe(['7.500', '7.500', ''])
        ->and([$rows[1]['Sick Leave'], $rows[1]['Sick Leave available balance']])->toBe(['', '']);

    // No date, or one that is not a date, reads today.
    expect(array_column(tkxferRows('/leaves/credits/export'), 'As of'))->toBe(['2026-09-30', '2026-09-30'])
        ->and(array_column(tkxferRows('/leaves/credits/export?asof=nonsense'), 'As of'))->toBe(['2026-09-30', '2026-09-30']);
});

it('offers no import on Leave Credits, only Export CSV', function () {
    tkxferEmployee('101', 'Ana');
    tkxferActor([ModuleCode::LEAVES]);
    $transfers = app(TableTransfers::class);

    expect($transfers->imports('leaves.credits'))->toBeFalse()
        ->and($transfers->importKeys())->not->toContain('leaves.credits')
        ->and(fn () => $transfers->import('leaves.credits'))->toThrow(NotFoundHttpException::class);

    // The shared import dialog refuses the table even when named directly.
    Livewire::test(CsvImport::class, ['table' => 'leaves.credits'])->assertNotFound();

    Livewire::test(LeaveCredits::class)
        ->assertSee('Export CSV')
        ->assertDontSee('Import CSV');
});
