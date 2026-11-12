<?php

declare(strict_types=1);

use App\Domain\Enums\ModuleCode;
use App\Livewire\Employees\Index;
use App\Livewire\Kit\CsvImport;
use App\Models\EmployeeProfile;
use App\Models\EmploymentStatusOption;
use App\Models\Position;
use App\Models\Role;
use App\Models\RoleModuleGrant;
use App\Models\User;
use App\Models\WorkSite;
use App\Modules\Workforce\Application\Commands\CreateEmployee;
use App\Modules\Workforce\Application\EmployeeColumns;
use App\Platform\Transfer\TableTransfers;
use App\Support\Access;
use App\Support\Csv;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Employee Records export each listed employee's whole 201 record, a
 * worksite by name, never an id. EXPORT ONLY: there is no employee import,
 * so no importer is registered for the table, the shared import dialog
 * refuses it, and the list offers Export CSV and no Import CSV. The scope
 * holds: a non-HR holder exports only their own record.
 */
function exferActor(bool $allData, ?string $employeeId = null): User
{
    $role = new Role;
    $role->forceFill(['name' => 'Employee transfer '.Str::random(6), 'all_data' => $allData, 'is_system' => false])->save();
    $grant = new RoleModuleGrant;
    $grant->forceFill(['role_id' => $role->getKey(), 'module' => ModuleCode::EMPLOYEES])->save();
    $user = User::factory()->create(['role_id' => $role->getKey(), 'employee_id' => $employeeId]);
    test()->actingAs($user);
    app()->forgetInstance(Access::class);

    return $user;
}

function exferSite(string $name): WorkSite
{
    return WorkSite::query()->create(['name' => $name, 'latitude' => 14.5995, 'longitude' => 120.9842, 'radius_meters' => 100, 'geofence_exempt' => false]);
}

function exferHire(WorkSite $site, string $first, string $email): EmployeeProfile
{
    $view = app(CreateEmployee::class)->handle(app(Access::class), [
        'employee_id_no' => 'ID-'.substr(md5($email), 0, 8),
        'first_name' => $first, 'last_name' => 'Dela Cruz', 'phone' => '0917 123 4567', 'email' => $email,
        'birth_date' => '1990-05-01', 'res_street' => '123 Mabini St.', 'sex' => 'Male', 'civil_status' => 'Single',
        'emergency_name' => 'Maria Dela Cruz', 'emergency_relation' => 'Spouse', 'emergency_phone' => '(02) 8123-4567',
        'position_id' => (string) Position::query()->where('name', 'Driver')->value('id'), 'employment_status' => 'Probationary', 'work_site_id' => (string) $site->getKey(),
        'date_hired' => '2026-08-01', 'monthly_rate' => '25000', 'is_active' => true,
    ]);

    return EmployeeProfile::query()->findOrFail($view->id);
}

/** @return list<array<string, string>> the export's rows, keyed by header */
function exferRows(string $url = '/employees/export'): array
{
    $csv = test()->get($url)->assertOk()->streamedContent();
    $lines = array_map(static fn (string $line): array => str_getcsv($line, ',', '"', ''), explode("\r\n", substr($csv, strlen(Csv::BOM))));
    $headers = array_map('strval', array_shift($lines));

    return array_map(static fn (array $cells): array => array_combine($headers, array_map('strval', $cells)), $lines);
}

beforeEach(function () {
    Position::query()->create(['code' => '1', 'name' => 'Driver']);
    EmploymentStatusOption::query()->create(['code' => '1', 'name' => 'Probationary']);
    EmploymentStatusOption::query()->create(['code' => '2', 'name' => 'Regular']);
});

it('exports the whole record with the worksite by name', function () {
    exferActor(allData: true);
    $site = exferSite('Head Office');
    $employee = exferHire($site, 'Juan', 'juan@company.com');

    $rows = exferRows();

    expect(array_keys($rows[0]))->toBe(EmployeeColumns::row())
        ->and($rows[0]['ID'])->toBe($employee->employee_code)
        ->and($rows[0]['Assigned Worksite'])->toBe('Head Office')
        ->and($rows[0]['Birthday'])->toBe('1990-05-01')
        ->and($rows[0]['Active employee'])->toBe('Yes')
        ->and($rows[0]['Emergency Contact Number'])->toBe('(02) 8123-4567')
        ->and($rows[0])->not->toHaveKey('Allowances')
        ->and($rows[0])->not->toHaveKey('Residence Certificate')
        ->and($rows[0])->not->toHaveKey('Deduct SSS')
        ->and($rows[0])->not->toHaveKey('Hourly Rate Override');
});

it('exports Place of Birth, Citizenship and the emergency contact Address in their own columns', function () {
    exferActor(allData: true);
    $employee = exferHire(exferSite('Head Office'), 'Juan', 'juan@company.com');
    $employee->forceFill([
        'place_of_birth' => 'Quezon City', 'citizenship' => 'Filipino', 'emergency_address' => '45 Rizal Ave., Pasig City',
    ])->save();

    $rows = exferRows();

    // The emergency Address has its own header beside the employee's own.
    // "Citizenship" carries the stored text while no citizenship is chosen;
    // "Address" the residential address the hire typed in parts.
    expect($rows[0]['Place of Birth'])->toBe('Quezon City')
        ->and($rows[0]['Citizenship'])->toBe('Filipino')
        ->and($rows[0]['Emergency Contact Address'])->toBe('45 Rizal Ave., Pasig City')
        ->and($rows[0]['Address'])->toBe('123 Mabini St.');
});

it('exports the Rate Per Day beside Rate Per Month, blank when none is entered', function () {
    exferActor(allData: true);
    $employee = exferHire(exferSite('Head Office'), 'Juan', 'juan@company.com');

    $rows = exferRows();
    $headers = array_keys($rows[0]);

    expect(array_search('Rate Per Day', $headers, true))->toBe((int) array_search('Rate Per Month', $headers, true) + 1)
        ->and($rows[0]['Rate Per Day'])->toBe('');

    $employee->forceFill(['daily_rate' => 1250.5])->save();

    expect(exferRows()[0]['Rate Per Day'])->toBe('1250.5');
});

it('exports only the actor\'s own record without all_data', function () {
    exferActor(allData: true);
    $site = exferSite('Head Office');
    $own = exferHire($site, 'Juan', 'juan@company.com');
    exferHire($site, 'Pedro', 'pedro@company.com');

    exferActor(allData: false, employeeId: (string) $own->getKey());

    expect(array_column(exferRows(), 'First Name'))->toBe(['Juan']);
});

it('offers no import on Employee Records, only Export CSV', function () {
    exferActor(allData: true);
    $transfers = app(TableTransfers::class);

    expect($transfers->imports('employees'))->toBeFalse()
        ->and($transfers->importKeys())->not->toContain('employees')
        ->and(fn () => $transfers->import('employees'))->toThrow(NotFoundHttpException::class);

    // The shared import dialog refuses the table even when named directly.
    Livewire::test(CsvImport::class, ['table' => 'employees'])->assertNotFound();

    Livewire::test(Index::class)
        ->assertSee('Export CSV')
        ->assertDontSee('Import CSV');
});
