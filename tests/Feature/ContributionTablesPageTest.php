<?php

declare(strict_types=1);

use App\Livewire\Records\ContributionTablesPage;
use App\Livewire\Records\Support\DeductionTablesText;
use App\Models\Role;
use App\Models\RoleModuleGrant;
use App\Models\User;
use App\Modules\Payroll\Domain\Canada\DeductionRates;
use App\Support\Access;
use App\Support\LocalClock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Livewire\Livewire;

/**
 * Contribution & Tax Tables is a read-only page: it shows the published
 * Canada Pension Plan, Employment Insurance and income tax figures of one set
 * at a time, with no way to change them, and a sample pay worked out by the
 * calculator payroll uses. The page is behind the PAYROLL grant like every
 * Payroll Settings page.
 */
/** @param  list<string>  $modules */
function ctpActor(array $modules = ['PAYROLL'], bool $allData = true): Access
{
    $role = new Role;
    $role->forceFill(['name' => 'Tables '.Str::random(6), 'all_data' => $allData, 'is_system' => false])->save();
    foreach ($modules as $module) {
        $grant = new RoleModuleGrant;
        $grant->forceFill(['role_id' => $role->getKey(), 'module' => $module])->save();
    }
    test()->actingAs(User::factory()->create(['role_id' => $role->getKey()]));
    app()->forgetInstance(Access::class);

    return app(Access::class);
}

function ctpOn(string $day): void
{
    test()->travelTo(CarbonImmutable::parse($day.' 09:00:00', LocalClock::zone()));
}

/* |---| The gate |---| */

it('opens for a PAYROLL holder and turns everyone else away', function () {
    test()->get('/records/payroll-settings/contribution-tables')->assertRedirect(route('login'));

    ctpActor(['EMPLOYEES']);
    test()->get('/records/payroll-settings/contribution-tables')->assertRedirect();

    ctpActor(['PAYROLL'], allData: false);
    test()->get('/records/payroll-settings/contribution-tables')->assertOk()
        ->assertSee('Contribution & Tax Tables')
        ->assertSee('These figures are set by law and cannot be changed here.');
});

/* |---| What it shows |---| */

it('shows the pension plan and insurance figures in use, with what changes next', function () {
    ctpOn('2026-11-24');
    ctpActor();

    Livewire::test(ContributionTablesPage::class)
        ->assertSee('Canada Pension Plan (CPP) Figures')
        ->assertSee('5.95%')
        ->assertSee('$74,600.00')
        ->assertSee('$4,230.45')
        ->assertSee('$85,000.00')
        ->assertSee('Employment Insurance (EI) Figures')
        ->assertSee('1.63%')
        ->assertSee('2.282%')
        ->assertSee('$68,900.00')
        ->assertSee('$1,123.07')
        // The set that starts next is named under each table.
        ->assertSee('From Jan 01, 2027')
        ->assertSee('5.75%')
        ->assertSee('$4,209.00')
        ->assertSee('1.64%')
        // Nothing on the page edits a figure.
        ->assertDontSee('Edit Figures')
        ->assertDontSee('Confirm Figures')
        ->assertDontSee('SSS')
        ->assertDontSee('PhilHealth')
        ->assertDontSee('Pag-IBIG');
});

it('shows the federal and provincial tax brackets on the income tax tables', function () {
    ctpOn('2026-11-24');
    ctpActor();

    Livewire::test(ContributionTablesPage::class)
        ->set('tab', ContributionTablesPage::TAB_TAX)
        ->assertSee('How Income Tax Is Worked Out')
        ->assertSee('Federal Income Tax, 2026')
        ->assertSee('$0 to $58,523')
        ->assertSee('Over $258,482')
        ->assertSee('20.5%')
        ->assertSee('$16,452.00')
        ->assertSee('$1,501.00')
        ->assertSee('Alberta Income Tax, 2026')
        ->assertSee('$0 to $61,200')
        ->assertSee('Over $370,220')
        ->assertSee('$22,769.00')
        ->assertSee('$60,044, $120,088, $186,157 and $265,203')
        ->assertDontSee('Canada Pension Plan (CPP) Figures');
});

it('shows a later set when asked and the newer set once its day arrives', function () {
    ctpOn('2026-11-24');
    ctpActor();

    Livewire::test(ContributionTablesPage::class)
        ->set('from', '2027-01-01')
        ->assertSee('5.75%')
        ->assertSee('$76,700.00')
        ->assertSee('$4,209.00')
        ->assertDontSee('$4,230.45')
        // No set follows the last one, so nothing is announced.
        ->assertDontSee('From Jan 01, 2027')
        // An unknown date falls back to the set in use.
        ->set('from', '2031-06-01')
        ->assertSee('$4,230.45');

    ctpOn('2027-01-04');
    Livewire::test(ContributionTablesPage::class)
        ->assertSee('$4,209.00')
        ->assertSee('1.64%')
        ->assertDontSee('$4,230.45');
});

/* |---| The sample pay |---| */

it('works a typed sample pay out with the calculator payroll uses', function () {
    ctpOn('2026-11-24');
    ctpActor();

    $page = Livewire::test(ContributionTablesPage::class);
    // 2,000.00 every two weeks, the figures on the approved design.
    $page->assertSeeHtml('data-sample-pension>$110.99<')
        ->assertSeeHtml('data-sample-insurance>$32.60<')
        ->assertSee('$1,865.39')
        ->assertSee('$45.64');

    $page->set('samplePay', '2,322.00')
        ->assertSeeHtml('data-sample-pension>$130.15<')
        ->assertSeeHtml('data-sample-insurance>$37.85<')
        ->set('tab', ContributionTablesPage::TAB_TAX)
        ->assertSeeHtml('data-sample-federal>$208.09<')
        ->assertSeeHtml('data-sample-provincial>$102.26<')
        // A sample that is not an amount reads as nothing to deduct.
        ->set('samplePay', 'plenty')
        ->assertSeeHtml('data-sample-federal>$0.00<')
        ->assertSeeHtml('data-sample-provincial>$0.00<');
});

/* |---| The words |---| */

it('writes rates and brackets from the figures themselves', function () {
    $rates = DeductionRates::inForceOn('2026-06-01');

    expect(DeductionTablesText::percent(0.0595))->toBe('5.95%')
        ->and(DeductionTablesText::percent(0.205))->toBe('20.5%')
        ->and(DeductionTablesText::percent(0.14))->toBe('14%')
        ->and(DeductionTablesText::percent(0.0163 * 1.4))->toBe('2.282%')
        ->and(DeductionTablesText::bracketSummary($rates->federal['brackets']))->toBe('5 tax brackets · 14% to 33%')
        ->and(DeductionTablesText::brackets($rates->province('AB')['brackets'])[5])->toBe(['range' => 'Over $370,220', 'rate' => '15%'])
        ->and(DeductionTablesText::year($rates))->toBe('2026')
        ->and($rates->next()?->effectiveFrom)->toBe('2027-01-01')
        ->and($rates->next()?->next())->toBeNull();
});
