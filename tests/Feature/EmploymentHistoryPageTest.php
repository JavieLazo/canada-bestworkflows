<?php

declare(strict_types=1);

use App\Domain\Enums\ModuleCode;
use App\Livewire\Employees\History;
use App\Livewire\Employees\Show;
use App\Livewire\Employees\Support\EmploymentHistoryExport;
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
use App\Support\Access;
use App\Support\Csv;
use App\Support\LocalClock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** History navigation, filters and exports reveal only changes within the viewer's employee scope. */
function ehpageActor(bool $allData = true, ?EmployeeProfile $employee = null, string $module = ModuleCode::EMPLOYEES): User
{
    $role = Role::query()->create(['name' => 'History viewer '.Str::random(8), 'all_data' => $allData, 'is_system' => false]);
    RoleModuleGrant::query()->create(['role_id' => $role->id, 'module' => $module]);
    $user = User::factory()->create(['role_id' => $role->id, 'employee_id' => $employee?->id]);
    test()->actingAs($user);
    app()->forgetInstance(Access::class);

    return $user;
}

function ehpageEmployee(string $name = 'Ana'): EmployeeProfile
{
    $site = WorkSite::query()->firstOrCreate(['name' => 'History site'], ['latitude' => 14.5, 'longitude' => 121, 'radius_meters' => 100]);
    Position::query()->firstOrCreate(['code' => 'HISTORY-POS'], ['name' => 'Developer']);
    EmploymentStatusOption::query()->firstOrCreate(['code' => 'HISTORY-STATUS'], ['name' => 'Regular']);
    $employee = EmployeeProfile::query()->create([
        'employee_code' => 'HIST-'.$name, 'first_name' => $name, 'last_name' => 'History',
        'position' => 'Developer', 'position_level' => 'Junior', 'employment_status' => 'Regular',
        'date_hired' => '2024-01-01', 'work_site_id' => $site->id,
        'monthly_rate' => 25000, 'daily_rate' => 1000, 'is_active' => true,
    ]);
    app(EmploymentHistoryRecorder::class)->hired(app(Access::class), $employee->refresh());

    return $employee;
}

/** @param array<string, mixed> $values */
function ehpageChange(EmployeeProfile $employee, array $values): void
{
    app(UpdateEmployee::class)->handle(app(Access::class), $employee->id, 'employment', $values + EmployeeFields::toForm($employee->refresh()));
}

/** @return list<array<string, string>> */
function ehpageCsv(string $url): array
{
    $csv = test()->get($url)->assertOk()->streamedContent();
    $lines = array_map(static fn (string $line): array => str_getcsv($line, ',', '"', ''), explode("\r\n", substr($csv, strlen(Csv::BOM))));
    $headers = array_map('strval', array_shift($lines));

    return array_map(static fn (array $cells): array => array_combine($headers, array_map('strval', $cells)), $lines);
}

it('opens history separately and links the employee profile to its selected history', function () {
    ehpageActor();
    $employee = ehpageEmployee();
    ehpageChange($employee, ['position_level' => 'Senior', 'monthly_rate' => '28000']);

    $this->get(route('employees.index'))->assertOk()->assertSeeHtml(route('employees.history'));
    $this->get(route('employees.history', ['employee' => $employee->id]))->assertOk()
        ->assertSee('Previous Value')->assertSee('New Value')->assertSee('Senior');
    Livewire::test(Show::class, ['employee' => $employee->id])
        ->assertSeeHtml(route('employees.history', ['employee' => $employee->id]))
        ->assertDontSee('Previous Value')->assertDontSee('Starting record when history tracking began.');
});

it('groups changed fields under one edit and leaves unchanged facts out of it', function () {
    ehpageActor();
    $employee = ehpageEmployee();
    ehpageChange($employee, ['position_level' => 'Senior', 'monthly_rate' => '28000']);
    $component = Livewire::test(History::class)->set('employeeId', $employee->id);
    $document = new DOMDocument;
    @$document->loadHTML('<?xml encoding="UTF-8">'.$component->html());
    $xpath = new DOMXPath($document);
    $entries = '//*[@*[name()="wire:key"] and starts-with(@*[name()="wire:key"], "employment-history-")]';
    expect($xpath->evaluate('count('.$entries.')'))->toBe(1.0)
        ->and($xpath->evaluate('string('.$entries.')'))->toContain('Junior', 'Senior', '$25,000.00', '$28,000.00')
        // One needle per negation: negated together they pass when any ONE is absent.
        ->not->toContain('History site')->not->toContain('1,000.00')->not->toContain('Baseline')
        // The starting record now closes the timeline, keyed APART from the
        // updates: it holds the unchanged facts and no change row.
        ->and($xpath->evaluate('count('.$entries.'//*[@data-field])'))->toBe(2.0)
        ->and($xpath->evaluate('count(//*[@data-entry="start"])'))->toBe(1.0)
        ->and($xpath->evaluate('count(//*[@data-entry="start"]//*[@data-field])'))->toBe(0.0);
});

it('uses the same employee date and field filters for the view and CSV', function () {
    ehpageActor();
    $this->travelTo(CarbonImmutable::parse('2026-10-01 10:00:00', LocalClock::zone()));
    $ana = ehpageEmployee();
    $ben = ehpageEmployee('Ben');
    ehpageChange($ana, ['monthly_rate' => '26000']);
    $this->travelTo(CarbonImmutable::parse('2026-10-02 11:00:00', LocalClock::zone()));
    ehpageChange($ana, ['position_level' => 'Senior', 'monthly_rate' => '28000']);
    ehpageChange($ben, ['monthly_rate' => '99999']);

    Livewire::test(History::class)->set('employeeId', $ana->id)
        ->set('from', '2026-10-02')->set('to', '2026-10-02')->set('field', 'monthly_rate')
        ->assertSee('$26,000.00')->assertSee('$28,000.00')
        ->assertDontSee('Senior')->assertDontSee('$99,999.00');
    $rows = ehpageCsv(route('employees.history.export', ['employee' => $ana->id, 'from' => '2026-10-02', 'to' => '2026-10-02', 'field' => 'monthly_rate']));
    expect($rows)->toHaveCount(1)
        ->and($rows[0]['Employee'])->toBe('Ana History')
        ->and($rows[0]['Changed on (local time)'])->toBe('2026-10-02 11:00:00')
        ->and($rows[0]['Previous value'])->toBe('$26,000.00')
        ->and($rows[0]['New value'])->toBe('$28,000.00');
});

it('keeps an employee viewer inside their own history despite forged employee filters', function () {
    ehpageActor();
    $own = ehpageEmployee();
    $other = ehpageEmployee('Ben');
    ehpageChange($own, ['position_level' => 'Own change']);
    ehpageChange($other, ['position_level' => 'Private foreign change']);
    ehpageActor(false, $own);

    // The forged filter may not surface the other employee by name either:
    // the timeline header is read under the same scope as the changes.
    Livewire::test(History::class)->assertSee('Own change')->assertDontSee('Private foreign change')
        ->set('employeeId', $other->id)->assertDontSee('Private foreign change')->assertDontSee('Ben History')
        ->assertDontSeeHtml('data-subject');
    expect(ehpageCsv(route('employees.history.export', ['employee' => $other->id])))->toBeEmpty();
    ehpageActor(false);
    Livewire::test(History::class)->assertDontSee('Own change')->assertDontSee('Private foreign change');
    expect(ehpageCsv(route('employees.history.export')))->toBeEmpty();
});

it('refuses invalid report dates before downloading an export', function () {
    ehpageActor();
    $this->getJson(route('employees.history.export', ['from' => '2026-02-30']))->assertUnprocessable();
    $this->getJson(route('employees.history.export', ['from' => '2026-10-03', 'to' => '2026-10-02']))->assertUnprocessable();
    Livewire::test(History::class)->set('from', '2026-02-30')->assertHasErrors('from')
        ->assertDontSeeHtml(route('employees.history.export'));
    // An invalid range shows no one's record: neither the header nor a starting record.
    $employee = ehpageEmployee();
    Livewire::test(History::class)->set('employeeId', $employee->id)->assertSeeHtml('data-subject')
        ->set('from', '2026-02-30')->assertHasErrors('from')
        ->assertDontSeeHtml('data-subject')->assertDontSeeHtml('data-entry');
});

it('gates the history page and export without granting payroll access to employee history', function () {
    $this->get(route('employees.history'))->assertRedirect(route('login'));
    $this->get(route('employees.history.export'))->assertRedirect(route('login'));
    $payroll = ehpageActor(module: ModuleCode::PAYROLL);
    $this->get(route('employees.history'))->assertRedirect();
    $this->get(route('employees.history.export'))->assertRedirect();
    expect(fn () => app(EmploymentHistoryExport::class)->export(Access::forActor($payroll), []))->toThrow(HttpException::class);
});
