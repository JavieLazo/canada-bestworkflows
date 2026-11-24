<?php

declare(strict_types=1);

use App\Domain\Payroll\PayRules;
use App\Livewire\Payroll\Support\SettingsPages;
use App\Livewire\Records\AdjustmentCategoriesPage;
use App\Livewire\Records\ContributionTablesPage;
use App\Livewire\Records\EmployeePaySettingsPage;
use App\Livewire\Records\IncentiveTypesPage;
use App\Livewire\Records\PayRulesPage;
use App\Livewire\Records\PaySchedulesPage;
use App\Livewire\Records\ReferenceListPage;
use App\Livewire\Records\SignatoriesPage;
use App\Livewire\Records\Support\BenefitText;
use App\Models\EmployeeProfile;
use App\Models\Holiday;
use App\Models\PayrollDeduction;
use App\Models\PayrollPayRule;
use App\Models\Role;
use App\Models\RoleModuleGrant;
use App\Models\User;
use App\Modules\Payroll\Application\Catalogs\Deductions;
use App\Modules\Payroll\Application\Commands\SavePayRulesVersion;
use App\Support\Access;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Livewire\Livewire;

/**
 * Records → Payroll Settings: the settings sit in Records Management's
 * menu but stay gated on the PAYROLL grant (their earlier addresses
 * forwarding) and share a switch. The menu's own address has no page: it
 * leads to Employees, or to the tables for a holder who cannot read employee
 * pay. Whoever manages payroll manages every company-wide setting
 * (PayrollSettingsAccess).
 */

/** @param list<string> $modules */
function psoActor(array $modules = ['PAYROLL'], bool $allData = true): Access
{
    $role = new Role;
    $role->forceFill(['name' => 'Settings '.Str::random(6), 'all_data' => $allData, 'is_system' => false])->save();
    foreach ($modules as $module) {
        $grant = new RoleModuleGrant;
        $grant->forceFill(['role_id' => $role->getKey(), 'module' => $module])->save();
    }
    $user = User::factory()->create(['role_id' => $role->getKey()]);
    test()->actingAs($user);
    app()->forgetInstance(Access::class);

    return Access::forActor($user);
}

/** @param array<string, mixed> $overrides */
function psoEmployee(string $lastName, array $overrides = []): EmployeeProfile
{
    $employee = new EmployeeProfile;
    $employee->forceFill(array_merge([
        'employee_code' => 'PSO-'.Str::upper(Str::random(5)),
        'first_name' => 'Pat',
        'last_name' => $lastName,
        'employment_status' => 'Regular',
        'date_hired' => '2024-01-01',
        'monthly_rate' => 30000,
        'is_active' => true,
    ], $overrides))->save();

    return $employee->refresh();
}

/* |---| The gate and the menu |---| */

it('leads the menu\'s address to the first page the holder can read, and forwards the old addresses', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-31 04:00:00', 'UTC'));
    $this->get('/records/payroll-settings')->assertRedirect(route('login'));

    // Records alone opens nothing here, old addresses included; a PAYROLL
    // holder's old bookmarks forward.
    psoActor(['RECORDS']);
    $this->get('/records/payroll-settings')->assertRedirect('/records/positions');
    $this->get('/records/payroll-settings/employees')->assertRedirect('/records/positions');
    $this->get('/records/contribution-tables')->assertRedirect('/records/positions');
    $this->get('/payroll/settings')->assertRedirect('/records/positions');

    psoActor(['PAYROLL']);
    $this->get('/records/payroll-settings')->assertRedirect(route('records.employee-pay-settings'));
    $this->get('/records/contribution-tables')->assertRedirect('/records/payroll-settings/contribution-tables');
    $this->get('/records/employee-pay-settings')->assertRedirect('/records/payroll-settings/employees');
    $this->get('/payroll/settings')->assertRedirect('/records/payroll-settings');
    $this->get('/payroll/settings/pay-rates')->assertRedirect('/records/payroll-settings/pay-rates');
    $this->get('/payroll/settings/employees/emp-1')->assertRedirect('/records/payroll-settings/employees/emp-1');

    // Without all data the Employees table shows nothing, so the tables come first.
    psoActor(['PAYROLL'], false);
    $this->get('/records/payroll-settings')->assertRedirect(route('records.contribution-tables'));

    psoActor(['PAYROLL', 'RECORDS']);
    $page = $this->get('/records/payroll-settings/employees')->assertOk()
        ->assertSee('aria-label="Payroll Settings pages"', false)
        ->assertSee(route('payroll.settings.deductions'), false);
    $html = (string) $page->getContent();
    // One sidebar entry, lit in the Records tree; the Payroll tree does not hold it.
    expect(substr_count($html, 'href="'.url('/records/payroll-settings').'"'))->toBe(1)
        ->and($html)->not->toContain('href="'.url('/payroll/settings').'"')
        ->and($html)->not->toContain('href="'.url('/records/pay-rates').'"')
        ->and($html)->not->toContain('href="'.url('/records/employee-pay-settings').'"');
    expect(preg_match_all('/<a href="[^"]*?(\/(?:records|payroll)[^"]*)" class="[^"]*"\s+aria-current="page"\s*>/', $html, $lit))->toBeGreaterThanOrEqual(1)
        ->and($lit[1])->toContain('/records/payroll-settings');

    foreach (['/records/payroll-settings/employees', '/records/payroll-settings/contribution-tables', '/records/payroll-settings/pay-rates', '/records/payroll-settings/payroll-deductions', '/records/payroll-settings/incentive-types'] as $path) {
        $this->get($path)->assertOk()->assertSee('aria-label="Payroll Settings pages"', false)->assertDontSee('>Overview<', false);
    }
});

/* |---| Who manages the settings |---| */

it('lets an all-data PAYROLL holder manage the company-wide settings, and nobody without all data', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-31 04:00:00', 'UTC'));
    $actor = psoActor(['PAYROLL']);
    app(Deductions::class)->save($actor, null, 'Cash Advance', '');
    app(SavePayRulesVersion::class)->handle($actor, PayrollPayRule::COMPANY, '2026-11-16', 'Payroll', PayRules::fromFormulas()->toArray());
    expect(PayrollDeduction::query()->where('name', 'Cash Advance')->exists())->toBeTrue()
        ->and(PayrollPayRule::query()->where('effective_from', '2026-11-16')->exists())->toBeTrue();

    $reader = psoActor(['PAYROLL'], false);
    expect(fn () => app(SavePayRulesVersion::class)->handle($reader, PayrollPayRule::COMPANY, '2026-12-01', 'No', PayRules::fromFormulas()->toArray()))
        ->toThrow(RuntimeException::class, 'Your role cannot manage organization-wide data.')
        ->and(fn () => app(Deductions::class)->save($reader, null, 'Uniform', ''))
        ->toThrow(RuntimeException::class, 'Your role cannot manage organization-wide data.')
        ->and(PayrollPayRule::query()->where('effective_from', '2026-12-01')->exists())->toBeFalse()
        ->and(PayrollDeduction::query()->where('name', 'Uniform')->exists())->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| One head on every settings page
|--------------------------------------------------------------------------
*/

it('heads every settings page with Payroll Settings and the same tabs, Pay Schedule among them, without an employee search or Adjustments', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-31 04:00:00', 'UTC'));
    psoEmployee('Search');
    psoActor();

    $pages = [
        EmployeePaySettingsPage::class => [[], 'Employee Pay Settings'],
        PaySchedulesPage::class => [[], 'Pay Schedule'],
        ContributionTablesPage::class => [[], 'Contribution &amp; Tax Tables'],
        PayRulesPage::class => [[], 'Pay Basis &amp; Rates'],
        ReferenceListPage::class => [['slug' => 'payroll-deductions'], 'Payroll Deductions'],
        IncentiveTypesPage::class => [[], 'Incentives &amp; Bonuses'],
        AdjustmentCategoriesPage::class => [[], 'Adjustment Categories'],
        SignatoriesPage::class => [[], 'Signatories'],
    ];

    foreach ($pages as $class => [$params, $title]) {
        $page = Livewire::test($class, $params);
        $html = $page->html();

        expect($html)->toContain('>Payroll Settings</p>')
            ->and($html)->toContain('tracking-tight">'.$title.'</h1>')
            ->and($html)->toContain('aria-label="Payroll Settings pages"')
            ->and($html)->toContain(route('payroll.settings.schedules'))
            ->and($html)->not->toContain(route('payroll.settings.adjustment-categories'));

        // The switch is a bar of tabs and, for a narrow head, one dropdown of the same six pages; the page in
        // view is marked in both, and a page the switch does not list marks none.
        $listed = $class !== AdjustmentCategoriesPage::class;
        expect(substr_count($html, 'data-settings-head'))->toBe(1)
            ->and($html)->toContain('aria-label="Payroll Settings page"')
            ->and(substr_count($html, 'aria-current="page"'))->toBe($listed ? 1 : 0);
        foreach (SettingsPages::all() as $view) {
            expect(substr_count($html, 'href="'.route($view['route']).'"'))->toBeGreaterThanOrEqual(1)
                ->and($html)->toContain('value="'.route($view['route']).'"');
        }

        // The head has no employee search, and the page has nothing that answers one: an employee is opened
        // from their row on the Employees page.
        expect($html)->not->toContain('Find an employee')
            ->and($html)->not->toContain('settings-employee')
            ->and(method_exists($class, 'settingsEmployeePicked'))->toBeFalse();
    }

    // Adjustments is off the Payroll menu, which is one link to the run list and no tree.
    $payroll = null;
    foreach ((array) config('modules') as $module) {
        if (is_array($module) && ($module['code'] ?? null) === 'PAYROLL') {
            $payroll = $module;
        }
    }
    expect($payroll['path'] ?? null)->toBe('/payroll')
        ->and(array_key_exists('groups', (array) $payroll))->toBeFalse();

    // The Pay Schedules page stays reachable by its address.
    psoActor();
    app()->forgetInstance(Access::class);
    $this->get(route('payroll.settings.schedules'))->assertOk();
});

it('shows a blank rate cell as the percent it pays, from the Holidays list', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-31 04:00:00', 'UTC'));
    psoActor();

    $holiday = static function (string $name, string $date, string $type, float $percent): void {
        $row = new Holiday;
        $row->forceFill(['code' => (string) random_int(1000, 999999), 'name' => $name, 'date' => $date, 'schedule' => 'ONE_TIME', 'type' => $type, 'percent' => $percent])->save();
    };
    Holiday::query()->delete();
    $holiday('Bonifacio Day', '2026-11-30', 'REGULAR', 200);
    $holiday('All Saints', '2026-11-01', 'SPECIAL_NON_WORKING', 130);

    $placeholder = static fn (string $html, string $cell): ?string => preg_match('/wire:model\.live\.debounce\.400ms="formMatrix\.'.preg_quote($cell, '/').'" placeholder="([^"]*)"/', $html, $m) === 1 ? $m[1] : null;

    $html = Livewire::test(PayRulesPage::class)->html();

    // 130% rest day, 125% overtime, 110% night and 130% holiday overtime, as seeded.
    expect($placeholder($html, 'REGULAR.worked'))->toBe('200')
        ->and($placeholder($html, 'REGULAR.overtime'))->toBe('260')
        ->and($placeholder($html, 'REGULAR.night'))->toBe('210')
        ->and($placeholder($html, 'REGULAR_REST.worked'))->toBe('260')
        ->and($placeholder($html, 'REGULAR_REST.overtime'))->toBe('338')
        ->and($placeholder($html, 'SPECIAL.worked'))->toBe('130')
        ->and($placeholder($html, 'SPECIAL_REST.overtime'))->toBe('219.7')
        ->and($placeholder($html, 'DOUBLE.worked'))->toBe('Type a rate')
        ->and($placeholder($html, 'DOUBLE.overtime'))->toBe('Needs a worked rate')
        ->and($html)->not->toContain('holiday %');

    // Holidays of one kind that disagree have no single figure to show.
    $holiday('Rizal Day', '2026-12-30', 'REGULAR', 250);
    expect($placeholder(Livewire::test(PayRulesPage::class)->html(), 'REGULAR.worked'))->toBe('Per holiday');
});

it('words a bonus typed per payroll as paid when typed, not one-time', function () {
    $text = static fn (string $amountType, string $frequency): string => BenefitText::frequency((object) ['amount_type' => $amountType, 'frequency' => $frequency]);

    expect($text('TYPED', 'ONCE'))->toBe('When typed')
        ->and($text('FIXED', 'ONCE'))->toBe('One-time')
        ->and($text('FIXED', 'EVERY'))->toBe('Every payroll');
});
