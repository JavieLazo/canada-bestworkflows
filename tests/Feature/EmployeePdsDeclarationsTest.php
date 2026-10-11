<?php

declare(strict_types=1);

use App\Domain\Enums\ModuleCode;
use App\Models\EmployeePdsDetail;
use App\Models\EmployeeProfile;
use App\Models\Role;
use App\Models\RoleModuleGrant;
use App\Models\User;
use App\Models\WorkSite;
use App\Modules\Workforce\Application\Commands\UpdateEmployee;
use App\Modules\Workforce\Application\EmployeeColumns;
use App\Modules\Workforce\Application\EmployeeFields;
use App\Modules\Workforce\Application\Queries\EmployeeRecords;
use App\Support\Access;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * A declaration is Not answered (NULL), Yes (true) or No (false) — NULL is
 * never read or exported as No. Its details are kept only for a Yes. Anyone
 * whose role can open the record reads the answers; only Employee Records
 * all-data writes them, and the audit log names "declarations", never an
 * answer or a detail.
 */

/** @param list<string> $modules */
function pdsdeclRole(array $modules, bool $allData): Role
{
    $role = new Role;
    $role->forceFill(['name' => 'Decl '.implode('-', $modules).' '.Str::random(6), 'all_data' => $allData, 'is_system' => false])->save();
    foreach ($modules as $module) {
        RoleModuleGrant::query()->create(['role_id' => $role->id, 'module' => $module]);
    }

    return $role;
}

/** @param list<string> $modules */
function pdsdeclActor(array $modules, bool $allData, ?EmployeeProfile $employee = null): Access
{
    $user = User::factory()->create();
    $user->forceFill(['role_id' => pdsdeclRole($modules, $allData)->id, 'employee_id' => $employee?->id])->save();

    return Access::forActor($user->refresh());
}

function pdsdeclEmployee(): EmployeeProfile
{
    $site = WorkSite::query()->create([
        'name' => 'Site '.Str::random(6), 'latitude' => 14.5995, 'longitude' => 120.9842, 'radius_meters' => 100, 'geofence_exempt' => true,
    ]);

    return EmployeeProfile::query()->create([
        'employee_code' => 'COP-'.Str::upper(Str::random(6)),
        'first_name' => 'Rosa', 'last_name' => 'Villanueva', 'position' => 'Developer',
        'employment_status' => 'Regular', 'date_hired' => '2024-08-01', 'work_site_id' => $site->id,
    ]);
}

/** @param array<string, mixed> $changes */
function pdsdeclSave(EmployeeProfile $employee, array $changes, ?Access $actor = null): ?string
{
    $form = EmployeeFields::toForm(EmployeeProfile::query()->findOrFail($employee->id));

    return app(UpdateEmployee::class)->handle($actor ?? pdsdeclActor([ModuleCode::EMPLOYEES], true), $employee->id, 'declarations', $changes + $form);
}

/** @return array<string, mixed> one details column as stored, untouched by any cast */
function pdsdeclRaw(EmployeeProfile $employee): array
{
    return (array) DB::table('employee_pds_details')->where('employee_id', $employee->id)->first();
}

it('stores Not answered as NULL, Yes as true and No as false', function () {
    $employee = pdsdeclEmployee();

    pdsdeclSave($employee, ['decl_34a' => '', 'decl_34b' => 'yes', 'decl_35a' => 'no']);
    $raw = pdsdeclRaw($employee);

    expect($raw['decl_34a'])->toBeNull()
        ->and((int) $raw['decl_34b'])->toBe(1)
        ->and($raw['decl_35a'])->not->toBeNull()
        ->and((int) $raw['decl_35a'])->toBe(0)
        ->and(EmployeeFields::toForm(EmployeeProfile::query()->findOrFail($employee->id)))
        ->toMatchArray(['decl_34a' => '', 'decl_34b' => 'yes', 'decl_35a' => 'no', 'decl_36' => '']);
});

it('keeps the details only for a Yes', function () {
    $employee = pdsdeclEmployee();
    pdsdeclSave($employee, [
        'decl_35b' => 'yes', 'decl_35b_details' => 'Estafa, RTC Lucena', 'decl_35b_date_filed' => '2020-03-12', 'decl_35b_case_status' => 'Dismissed',
        'decl_37' => 'yes', 'decl_37_details' => 'Finished contract',
    ]);

    expect(pdsdeclRaw($employee))->toMatchArray(['decl_35b_details' => 'Estafa, RTC Lucena', 'decl_35b_case_status' => 'Dismissed', 'decl_37_details' => 'Finished contract']);

    pdsdeclSave($employee, ['decl_35b' => 'no', 'decl_37' => '']);

    expect(pdsdeclRaw($employee))->toMatchArray([
        'decl_35b_details' => null, 'decl_35b_date_filed' => null, 'decl_35b_case_status' => null, 'decl_37' => null, 'decl_37_details' => null,
    ]);
});

it('reads each answer as Not answered, No or Yes with its detail lines', function () {
    $employee = pdsdeclEmployee();
    pdsdeclSave($employee, [
        'decl_34a' => 'no',
        'decl_35b' => 'yes', 'decl_35b_details' => 'Estafa, RTC Lucena', 'decl_35b_date_filed' => '2020-03-12', 'decl_35b_case_status' => 'Dismissed',
        'decl_36' => 'yes',
        'decl_39' => 'yes', 'decl_39_country' => 'Canada',
        'decl_40b' => 'yes', 'decl_40b_id_no' => 'PWD-123',
    ]);
    $view = app(EmployeeRecords::class)->find(pdsdeclActor([ModuleCode::EMPLOYEES], true), $employee->id, true);
    expect($view)->not->toBeNull();

    expect(EmployeeFields::declarationText($view, 'decl_34a'))->toBe(['answer' => false, 'text' => 'No'])
        ->and(EmployeeFields::declarationText($view, 'decl_34b'))->toBe(['answer' => null, 'text' => 'Not answered'])
        ->and(EmployeeFields::declarationText($view, 'decl_35b')['text'])->toBe("Yes\nDetails: Estafa, RTC Lucena\nDate filed: 12 Mar 2020\nStatus of case/s: Dismissed")
        ->and(EmployeeFields::declarationText($view, 'decl_36'))->toBe(['answer' => true, 'text' => 'Yes'])
        ->and(EmployeeFields::declarationText($view, 'decl_39')['text'])->toBe("Yes\nCountry: Canada")
        ->and(EmployeeFields::declarationText($view, 'decl_40b')['text'])->toBe("Yes\nID No.: PWD-123")
        ->and(EmployeeFields::declarationText(EmployeeProfile::query()->findOrFail($employee->id), 'decl_35b')['text'])
        ->toBe("Yes\nDetails: Estafa, RTC Lucena\nDate filed: 12 Mar 2020\nStatus of case/s: Dismissed");
});

it('never writes an answer or a detail into the audit log', function () {
    $employee = pdsdeclEmployee();
    pdsdeclSave($employee, ['decl_37' => 'yes', 'decl_37_details' => 'Finished contract, ABC Trading', 'decl_40c' => 'no']);

    $logs = DB::table('audit_logs')->get()->map(static fn (object $row): string => (string) json_encode($row));
    $named = $logs->filter(static fn (string $row): bool => str_contains($row, 'declarations updated'));

    expect($named)->toHaveCount(1)
        ->and($logs->implode("\n"))->not->toContain('Finished contract')
        ->not->toContain('decl_')
        ->not->toContain('ABC Trading');
});

it('refuses an answer off the list', function () {
    $employee = pdsdeclEmployee();

    expect(fn () => pdsdeclSave($employee, ['decl_34a' => 'maybe']))->toThrow(RuntimeException::class, 'must be one of the listed options.')
        ->and(EmployeePdsDetail::query()->count())->toBe(0);
});

it('refuses a Date filed in the future', function () {
    $employee = pdsdeclEmployee();
    $today = EmployeeFields::todayKey();
    $tomorrow = CarbonImmutable::parse($today)->addDay()->format('Y-m-d');

    expect(fn () => pdsdeclSave($employee, ['decl_35b' => 'yes', 'decl_35b_date_filed' => $tomorrow]))
        ->toThrow(RuntimeException::class, 'Date filed cannot be in the future.')
        ->and(EmployeePdsDetail::query()->count())->toBe(0);

    pdsdeclSave($employee, ['decl_35b' => 'yes', 'decl_35b_date_filed' => $today]);

    expect(EmployeePdsDetail::query()->sole()->decl_35b_date_filed?->format('Y-m-d'))->toBe($today);
});

it('shows the declarations to everyone who can open the record', function () {
    $employee = pdsdeclEmployee();
    pdsdeclSave($employee, ['decl_39' => 'yes', 'decl_39_country' => 'Canada']);
    $records = app(EmployeeRecords::class);

    $payroll = $records->find(pdsdeclActor([ModuleCode::PAYROLL], true), $employee->id, true);
    $own = $records->find(pdsdeclActor([ModuleCode::PROFILE], false, $employee), $employee->id, true);

    expect($payroll)->not->toBeNull()
        ->and(EmployeeFields::declarationText($payroll, 'decl_39')['text'])->toBe("Yes\nCountry: Canada")
        ->and($own)->not->toBeNull()
        ->and(EmployeeFields::declarationText($own, 'decl_39')['text'])->toBe("Yes\nCountry: Canada")
        ->and($records->find(pdsdeclActor([ModuleCode::PROFILE], false), $employee->id, true))->toBeNull()
        ->and(fn () => pdsdeclSave($employee, ['decl_39' => 'no'], pdsdeclActor([ModuleCode::PAYROLL], true)))->toThrow(HttpException::class);
});

it('exports the answers as Yes, No or blank and the details in their own columns', function () {
    $employee = pdsdeclEmployee();
    pdsdeclSave($employee, ['decl_34a' => 'no', 'decl_35b' => 'yes', 'decl_35b_details' => 'Estafa', 'decl_35b_date_filed' => '2020-03-12', 'decl_40b' => 'yes', 'decl_40b_id_no' => 'PWD-123']);
    $view = app(EmployeeRecords::class)->list(pdsdeclActor([ModuleCode::EMPLOYEES], true), true)->firstOrFail();
    $row = array_combine(EmployeeColumns::row(), EmployeeColumns::cells($view, []));

    expect($row)->toMatchArray([
        'Q34a Third Degree' => 'No', 'Q34a Details' => '',
        'Q34b Fourth Degree' => '',
        'Q35b Criminally Charged' => 'Yes', 'Q35b Details' => 'Estafa', 'Q35b Date Filed' => '2020-03-12', 'Q35b Status of Case' => '',
        'Q40b Person with Disability' => 'Yes', 'Q40b ID No.' => 'PWD-123',
        'Q40c Solo Parent' => '',
    ]);
});
