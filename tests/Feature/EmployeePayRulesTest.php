<?php

declare(strict_types=1);

use App\Domain\Enums\AuditEvent;
use App\Domain\Payroll\PayRules;
use App\Livewire\Records\EmployeePaySettingsPage;
use App\Livewire\Records\EmployeePaySettingsShow;
use App\Livewire\Records\PayRulesPage;
use App\Models\AuditLog;
use App\Models\EmployeeProfile;
use App\Models\EmploymentStatusOption;
use App\Models\PayrollPayRule;
use App\Models\Role;
use App\Models\RoleModuleGrant;
use App\Models\User;
use App\Modules\Payroll\Application\Commands\ApproveEmployeePayRules;
use App\Modules\Payroll\Application\Commands\DiscardEmployeePayRules;
use App\Modules\Payroll\Application\Commands\SaveEmployeePayRules;
use App\Modules\Payroll\Application\PayrollData;
use App\Modules\Payroll\Application\PayrollInputs;
use App\Modules\Payroll\Application\PayRulesStore;
use App\Modules\Payroll\Application\Queries\PayRuleRecords;
use App\Support\Access;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\PayrollEvidenceFixture;

/**
 * Pay Basis & Rates for ONE employee (2026_10_30_0001): a version of their
 * own prices them ahead of their Employment status's override and the
 * company rule; a "follows" version (no figures) hands them back; any of
 * their versions starting inside a period refuses it, naming them. Their
 * versions are pay data, saved by all-data PAYROLL through
 * SaveEmployeePayRules on the Pay Basis & Rates page opened for them, and
 * the payroll evidence records which version priced them.
 */

/** @param list<string> $modules */
function eprActor(array $modules = ['PAYROLL'], bool $allData = true, ?string $employeeId = null): Access
{
    $role = new Role;
    $role->forceFill(['name' => 'Employee rates '.Str::random(6), 'all_data' => $allData, 'is_system' => false])->save();
    foreach ($modules as $module) {
        $grant = new RoleModuleGrant;
        $grant->forceFill(['role_id' => $role->getKey(), 'module' => $module])->save();
    }
    $user = User::factory()->create(['role_id' => $role->getKey(), 'employee_id' => $employeeId]);
    test()->actingAs($user);
    app()->forgetInstance(Access::class);

    return Access::forActor($user);
}

function eprStatus(string $name): EmploymentStatusOption
{
    $status = new EmploymentStatusOption;
    $status->forceFill(['code' => (string) (EmploymentStatusOption::query()->count() + 1), 'name' => $name])->save();

    return $status;
}

function eprEmployee(string $lastName, string $status = 'Regular'): EmployeeProfile
{
    $employee = new EmployeeProfile;
    $employee->forceFill(['employee_code' => 'EPR-'.Str::upper(Str::random(5)), 'first_name' => 'Ren', 'last_name' => $lastName,
        'employment_status' => $status, 'date_hired' => '2024-01-01', 'monthly_rate' => 30000, 'is_active' => true])->save();

    return $employee->refresh();
}

/**
 * A version straight into the table, figures changed by dotted keys, or a "follows" row with null.
 *
 * @param  array<string, mixed>|null  $changes
 */
function eprVersion(string $scope, string $type, string $from, ?array $changes = []): PayrollPayRule
{
    $figures = null;
    if ($changes !== null) {
        $figures = PayRules::fromFormulas()->toArray();
        foreach ($changes as $path => $value) {
            $ref = &$figures;
            foreach (explode('.', $path) as $key) {
                $ref = &$ref[$key];
            }
            $ref = $value;
            unset($ref);
        }
    }
    $row = new PayrollPayRule;
    $row->forceFill(['scope' => $scope, 'scope_type' => $type, 'effective_from' => $from, 'reason' => 'Synthetic', 'params' => $figures, 'status' => PayrollPayRule::APPROVED, 'approved_at' => now('UTC')])->save();

    return $row;
}

/** @return array<string, mixed> */
function eprDivisor(float $days): array
{
    return ['daily_rate.method' => PayRules::METHOD_DIVISOR, 'daily_rate.value' => $days];
}

/* |---| Resolution |---| */

it('prices an employee by their own version, then their status, then the company, and hands them back on a follows version', function () {
    $regular = eprStatus('Regular');
    $employee = eprEmployee('Own');
    $status = eprVersion($regular->id, PayrollPayRule::SCOPE_STATUS, '2026-09-01', eprDivisor(25.0));
    $own = eprVersion($employee->id, PayrollPayRule::SCOPE_EMPLOYEE, '2026-10-01', eprDivisor(20.0));
    eprVersion($employee->id, PayrollPayRule::SCOPE_EMPLOYEE, '2026-11-01', null);
    $store = app(PayRulesStore::class);

    expect($store->resolve('2026-09-16', '2026-09-30', $regular->id, $employee->id)['entry']['version_id'])->toBe($status->id)
        ->and($store->resolve('2026-10-01', '2026-10-15', $regular->id, $employee->id)['entry'])->toMatchArray(['scope' => $employee->id, 'version_id' => $own->id])
        ->and($store->resolve('2026-11-01', '2026-11-15', $regular->id, $employee->id)['entry']['version_id'])->toBe($status->id)
        // Without the status override, the follows version hands them to the company.
        ->and($store->resolve('2026-11-01', '2026-11-15', null, $employee->id)['entry']['scope'])->toBe(PayrollPayRule::COMPANY)
        // Everyone else on the status is untouched.
        ->and($store->resolve('2026-10-01', '2026-10-15', $regular->id)['entry']['version_id'])->toBe($status->id)
        ->and($store->employeesWithOwnRules([$employee->id, (string) Str::ulid()]))->toBe([$employee->id]);

    // A version of theirs inside a period splits it (DIVERGENCE 17), the follows version too:
    // the period-level read names the change, the cut-off check no longer refuses it.
    foreach ([['2026-09-20', '2026-10-05', '01 Oct 2026', '2026-10-01'], ['2026-10-20', '2026-11-05', '01 Nov 2026', '2026-11-01']] as [$start, $end, $day, $from]) {
        expect($store->resolve($start, $end, $regular->id, $employee->id)['error'])
            ->toBe("The Pay Basis & Rates change on {$day}. End this payroll before that date, or start it on that date.")
            ->and($store->cutoffError($start, $end, [$regular->id], [], [$employee->id => ['status_id' => $regular->id, 'name' => 'Ren Own']]))->toBeNull()
            ->and(array_column($store->timeline($start, $end, $regular->id, $employee->id)['segments'], 'from'))->toBe([$from]);
    }
    expect($store->cutoffError('2026-09-20', '2026-10-05', [$regular->id]))->toBeNull();
});

it('records the employee\'s own version in the evidence, prices with it, and names them at the cut-off', function () {
    ['actor' => $actor, 'employee' => $employee] = PayrollEvidenceFixture::make();
    PayrollEvidenceFixture::sourceFacts($employee);
    $regular = eprStatus('Regular');
    eprVersion($regular->id, PayrollPayRule::SCOPE_STATUS, '2026-09-01', eprDivisor(25.0));

    $status = PayrollData::calculateDrafts([$employee->id], '2026-09-01', '2026-09-15', actor: $actor);
    expect($status['inputs']->payRules[$employee->id]['scope'])->toBe($regular->id)
        // A worked day and a paid business-travel day at 30,000 ÷ 25.
        ->and($status['drafts'][0]->work_days_pay)->toBe(2400.0);

    $own = eprVersion($employee->id, PayrollPayRule::SCOPE_EMPLOYEE, '2026-09-01', eprDivisor(20.0));
    $mine = PayrollData::calculateDrafts([$employee->id], '2026-09-01', '2026-09-15', actor: $actor);
    expect($mine['inputs']->payRules[$employee->id])->toMatchArray(['scope' => $employee->id, 'version_id' => $own->id])
        ->and($mine['drafts'][0]->work_days_pay)->toBe(3000.0)
        ->and($mine['inputs']->fingerprint())->not->toBe($status['inputs']->fingerprint());

    // The evidence replays the captured figures, whatever the table holds later.
    $restored = PayrollInputs::restore(PayrollInputs::json($mine['inputs']->toArray()));
    expect($restored->fingerprint())->toBe($mine['inputs']->fingerprint());
    $own->delete();
    expect(PayrollData::calculateInputs($restored, strict: true)['drafts'][0]->work_days_pay)->toBe(3000.0);

    // A version of theirs starting inside the period splits it (DIVERGENCE 17): captured as a dated segment.
    $mid = eprVersion($employee->id, PayrollPayRule::SCOPE_EMPLOYEE, '2026-09-10', eprDivisor(21.0));
    expect(PayrollData::cutoffError($actor, [$employee->id], '2026-09-01', '2026-09-15'))->toBeNull();
    $split = PayrollData::calculateDrafts([$employee->id], '2026-09-01', '2026-09-15', actor: $actor);
    expect(array_map(static fn (array $s): array => [$s['from'], $s['scope'], $s['version_id']], $split['inputs']->payRules[$employee->id]['segments'] ?? []))->toBe([['2026-09-10', $employee->id, $mid->id]])
        ->and($split['inputs']->timelineFor($employee->id)->on('2026-09-10')->dailyRateValue)->toBe(21.0);
});

it('labels a company or status row created without a kind by its scope, and keeps employees off the status overrides', function () {
    $regular = eprStatus('Regular');
    $employee = eprEmployee('Kinds');
    $company = new PayrollPayRule;
    $company->forceFill(['scope' => PayrollPayRule::COMPANY, 'effective_from' => '2027-01-01', 'params' => PayRules::fromFormulas()->toArray(), 'status' => PayrollPayRule::APPROVED, 'approved_at' => now('UTC')])->save();
    $status = new PayrollPayRule;
    $status->forceFill(['scope' => $regular->id, 'effective_from' => '2027-01-01', 'params' => PayRules::fromFormulas()->toArray(), 'status' => PayrollPayRule::APPROVED, 'approved_at' => now('UTC')])->save();
    eprVersion($employee->id, PayrollPayRule::SCOPE_EMPLOYEE, '2027-01-01');
    $actor = eprActor(['PAYROLL']);

    expect($company->refresh()->scope_type)->toBe(PayrollPayRule::SCOPE_COMPANY)
        ->and($status->refresh()->scope_type)->toBe(PayrollPayRule::SCOPE_STATUS)
        ->and(app(PayRuleRecords::class)->overriddenScopes($actor))->toBe([$regular->id])
        ->and(array_keys(app(PayRuleRecords::class)->employeeVersions($actor)))->toBe([$employee->id]);

    // One employee's versions are pay data.
    $records = eprActor(['RECORDS']);
    expect(fn () => app(PayRuleRecords::class)->versions($records, $employee->id))->toThrow(HttpException::class)
        ->and(app(PayRuleRecords::class)->versions($records, $regular->id))->toHaveCount(1);
});

/* |---| The command |---| */

it('prepares an employee\'s own version as a draft the preparer approves, their return to the general rules too, audited, for all-data PAYROLL only', function () {
    $employee = eprEmployee('Saved');
    $preparer = eprActor(['PAYROLL']);
    $command = app(SaveEmployeePayRules::class);
    $approve = app(ApproveEmployeePayRules::class);
    $figures = PayRules::preset(PayRules::PRESET_GOVERNMENT);

    expect(fn () => $command->handle($preparer, $employee->id, '2026-11-01', 'Back', null))
        ->toThrow(RuntimeException::class, 'Ren Saved has no rates of their own to end.');

    expect($command->handle($preparer, $employee->id, '2026-11-01', 'Monthly-paid by contract', $figures))->toBe("Ren Saved's Pay Basis & Rates from 01 Nov 2026 saved as a draft. Approve it to take effect.");
    $own = PayrollPayRule::query()->where('scope', $employee->id)->sole();
    expect($own->scope_type)->toBe(PayrollPayRule::SCOPE_EMPLOYEE)
        ->and($own->status)->toBe(PayrollPayRule::DRAFT)
        ->and($own->params)->toEqual(PayRules::fromArray($figures)->toArray())
        ->and($own->reason)->toBe('Monthly-paid by contract')
        ->and(AuditLog::query()->where('action', AuditEvent::PAY_RULES_SAVED->value)->where('entity_id', $own->id)->value('details'))
        ->toBe('Pay Basis & Rates draft from 01 Nov 2026 prepared for employee Ren Saved (Monthly-paid by contract)')
        // A draft prices nobody.
        ->and(app(PayRulesStore::class)->employeesWithOwnRules([$employee->id]))->toBe([])
        ->and(app(PayRulesStore::class)->resolve('2026-11-01', '2026-11-15', null, $employee->id)['entry']['scope'])->toBe(PayrollPayRule::COMPANY);

    // The same date again edits the draft in place.
    expect($command->handle($preparer, $employee->id, '2026-11-01', 'Corrected', $figures))->toContain('saved as a draft')
        ->and(PayrollPayRule::query()->where('scope', $employee->id)->count())->toBe(1)
        ->and($own->refresh()->reason)->toBe('Corrected')
        ->and($own->refresh()->status)->toBe(PayrollPayRule::DRAFT);

    // The preparer approves their own draft: one administrator runs the whole flow.
    $approver = $preparer;
    expect($approve->handle($approver, $own->id))->toBe("Ren Saved's Pay Basis & Rates from 01 Nov 2026 approved.")
        ->and($own->refresh()->status)->toBe(PayrollPayRule::APPROVED)
        ->and($own->approved_by)->toBe((int) $approver->userId)
        ->and($own->approved_at)->not->toBeNull()
        ->and(AuditLog::query()->where('action', AuditEvent::PAY_RULES_APPROVED->value)->sole()->details)->toContain('version from 01 Nov 2026 approved for employee Ren Saved (prepared by')
        ->and(fn () => $approve->handle($approver, $own->id))->toThrow(RuntimeException::class, 'That version is already approved.')
        ->and(app(PayRulesStore::class)->employeesWithOwnRules([$employee->id]))->toBe([$employee->id]);

    expect(fn () => $command->handle($approver, $employee->id, '2026-11-01', 'Again', $figures))->toThrow(RuntimeException::class, 'An approved version already takes effect on 01 Nov 2026 and never changes. Prepare one from a later date.')
        ->and(fn () => $command->handle($approver, $employee->id, '2026-12-01', ' ', $figures))->toThrow(RuntimeException::class, 'Enter the reason for this version.')
        ->and(fn () => $command->handle($approver, $employee->id, '2026-12-01', 'Bad', ['basis' => 'DAILY']))->toThrow(RuntimeException::class, 'The pay rules are incomplete')
        ->and(fn () => $command->handle($approver, (string) Str::ulid(), '2026-12-01', 'Nobody', $figures))->toThrow(RuntimeException::class, 'That employee no longer exists.');

    expect($command->handle($approver, $employee->id, '2027-01-01', 'Contract ended', null))->toBe('From 01 Jan 2027, Ren Saved follows the general Pay Basis & Rates again — once approved.');
    $follows = PayrollPayRule::query()->where('scope', $employee->id)->where('effective_from', '2027-01-01')->sole();
    expect($follows->params)->toBeNull()
        ->and(AuditLog::query()->where('entity_id', $follows->id)->value('details'))->toBe('Ren Saved follows the employment status or company Pay Basis & Rates again from 01 Jan 2027: draft (Contract ended)')
        ->and($approve->handle($preparer, $follows->id))->toBe('From 01 Jan 2027, Ren Saved follows the general Pay Basis & Rates again: approved.')
        ->and(fn () => $command->handle($approver, $employee->id, '2027-02-01', 'Twice', null))->toThrow(RuntimeException::class, 'has no rates of their own to end.');

    // A draft is discarded, an approved version never.
    $discard = app(DiscardEmployeePayRules::class);
    $command->handle($approver, $employee->id, '2027-03-01', 'Later', $figures);
    $later = PayrollPayRule::query()->where('scope', $employee->id)->where('effective_from', '2027-03-01')->sole();
    expect(fn () => $discard->handle($approver, $own->id))->toThrow(RuntimeException::class, 'An approved version cannot be discarded.')
        ->and($discard->handle($approver, $later->id))->toBe('Draft from 01 Mar 2027 discarded.')
        ->and(PayrollPayRule::query()->whereKey($later->id)->exists())->toBeFalse()
        ->and(AuditLog::query()->where('action', AuditEvent::PAY_RULES_DISCARDED->value)->sole()->details)->toBe('Pay Basis & Rates draft from 01 Mar 2027 for employee Ren Saved discarded (Later)');

    $count = PayrollPayRule::query()->count();
    $records = eprActor(['RECORDS', 'EMPLOYEES']);
    expect(fn () => $command->handle($records, $employee->id, '2027-03-01', 'No', $figures))->toThrow(RuntimeException::class, 'Your role cannot manage employee pay settings.')
        ->and(PayrollPayRule::query()->count())->toBe($count);
});

/* |---| The pages |---| */

it('opens Pay Basis & Rates for one employee to an all-data payroll officer, and saves and ends their own rates there', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-12 02:00:00', 'UTC'));
    $regular = eprStatus('Regular');
    eprVersion($regular->id, PayrollPayRule::SCOPE_STATUS, '2026-01-01', eprDivisor(25.0));
    $employee = eprEmployee('Paged');
    eprActor(['PAYROLL']);

    Livewire::withQueryParams(['for' => $employee->id]);
    $page = Livewire::test(PayRulesPage::class)
        ->assertSet('scope', $employee->id)
        ->assertSee('Ren Paged (this employee)')
        ->assertSee('Ren Paged follows the Regular override, which the form shows.')
        // The form starts from the status override it falls back to.
        ->assertSet('formMethod', PayRules::METHOD_DIVISOR)
        ->assertSet('formDivisor', '25')
        ->set('formDivisor', '20')
        ->call('save')
        ->assertSet('formError', null)
        // In use at once: no draft, no approval step, no date.
        ->assertDontSee('Awaiting approval')
        ->assertSee("Ren Paged's own rates, in place of the Regular override.", false);
    $own = PayrollPayRule::query()->where('scope', $employee->id)->sole();
    expect($own->scope_type)->toBe(PayrollPayRule::SCOPE_EMPLOYEE)
        ->and($own->status)->toBe(PayrollPayRule::APPROVED)
        ->and($own->effective_from->format('Y-m-d'))->toBe('2025-01-01')
        ->and($own->params['daily_rate']['value'] ?? null)->toEqual(20.0)
        ->and(app(PayRulesStore::class)->resolve('2026-10-01', '2026-10-15', $regular->id, $employee->id)['entry']['scope'])->toBe($employee->id);

    // Removing them returns the employee to the general rules, at once.
    $page->assertSee('Return to the Regular override')
        ->call('returnToGeneral')
        ->assertSet('formError', null)
        ->assertSee('Ren Paged follows the Regular override, which the form shows.')
        ->assertSet('formDivisor', '25');
    expect(PayrollPayRule::query()->where('scope', $employee->id)->exists())->toBeFalse()
        ->and(app(PayRulesStore::class)->resolve('2026-10-01', '2026-10-15', $regular->id, $employee->id)['entry']['scope'])->toBe($regular->id);

    // A Records manager without Payroll cannot open one employee's rates: the page falls back to the company rule.
    eprActor(['RECORDS']);
    Livewire::withQueryParams(['for' => $employee->id]);
    Livewire::test(PayRulesPage::class)
        ->assertSet('scope', PayrollPayRule::COMPANY)
        ->assertDontSee('Ren Paged');
});

it('opens Pay Basis & Rates to payroll officers, who save the company rules with all data', function () {
    eprActor(['PAYROLL'], allData: false);
    $this->get('/records/payroll-settings/pay-rates')->assertOk()->assertSee('Payroll access to all data');
    $before = PayrollPayRule::query()->sole()->params;
    Livewire::test(PayRulesPage::class)
        ->set('formMatrix.ORDINARY.overtime', '150')
        ->call('save');
    expect(PayrollPayRule::query()->sole()->params)->toEqual($before);

    // Whoever manages payroll manages its settings (PayrollSettingsAccess).
    eprActor(['PAYROLL']);
    Livewire::test(PayRulesPage::class)
        ->set('formMatrix.ORDINARY.overtime', '150')
        ->call('save');
    expect(PayrollPayRule::query()->sole()->params['overtime']['rate'])->toBe(1.5);
});

it('lists an employee with rates of their own as customized and says what prices them', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-12 02:00:00', 'UTC'));
    $regular = eprStatus('Regular');
    eprVersion($regular->id, PayrollPayRule::SCOPE_STATUS, '2026-01-01', eprDivisor(25.0));
    $own = eprEmployee('Ownrates');
    eprVersion($own->id, PayrollPayRule::SCOPE_EMPLOYEE, '2026-10-01', eprDivisor(20.0));
    $soon = eprEmployee('Soon');
    eprVersion($soon->id, PayrollPayRule::SCOPE_EMPLOYEE, '2026-11-01', eprDivisor(20.0));
    eprEmployee('Plain');
    eprEmployee('Company', 'Probationary');
    eprActor(['PAYROLL']);

    // The list opens on everyone; Only customized narrows it.
    Livewire::test(EmployeePaySettingsPage::class)
        ->assertSee('Plain')
        ->set('onlyCustomized', true)
        ->assertSee('Ownrates')->assertSee('Own rates')
        ->assertSee('Soon')->assertSee('Regular override, own from 01 Nov 2026')
        ->assertDontSee('Plain')
        ->set('onlyCustomized', false)
        ->assertSee('Plain')
        ->assertSee('Company rule');

    Livewire::test(EmployeePaySettingsShow::class, ['employee' => $own->id])
        ->assertSee('Their own rates')
        ->assertSee('Daily-paid · a day is the monthly rate ÷ 20')
        ->assertSee(route('records.pay-rates', ['for' => $own->id]), false);
    Livewire::test(EmployeePaySettingsShow::class, ['employee' => $soon->id])
        ->assertSee('The Regular override')
        ->assertSee('rates of their own');
});
