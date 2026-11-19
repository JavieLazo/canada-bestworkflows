<?php

declare(strict_types=1);

use App\Domain\Enums\AuditEvent;
use App\Domain\Enums\ModuleCode;
use App\Domain\Payroll\PayRules;
use App\Models\AuditLog;
use App\Models\EmploymentStatusOption;
use App\Models\PayrollFormula;
use App\Models\PayrollPayRule;
use App\Models\Role;
use App\Models\RoleModuleGrant;
use App\Models\User;
use App\Modules\Payroll\Application\Commands\SavePayRulesVersion;
use App\Modules\Payroll\Application\PayrollData;
use App\Modules\Payroll\Application\PayrollInputs;
use App\Modules\Payroll\Application\PayRulesStore;
use App\Support\Access;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\PayrollEvidenceFixture;

/**
 * Pay Basis & Rates (DIVERGENCE 13) as the application reads them: the
 * migration seeds ONE company version from the Formulas rows so no money
 * moves; a period is priced by the version in force on its first day — an
 * Employment status's override before the company rule — and refused when
 * a version changes inside it; a version is saved only through the audited
 * command; and every payroll captures each member's version in its
 * evidence, so a new version stales the preview and a replay never reads
 * the live table.
 */
function prulesActor(bool $allData = true): User
{
    $role = new Role;
    $role->forceFill(['name' => 'Pay rules '.Str::random(6), 'all_data' => $allData, 'is_system' => false])->save();
    $grant = new RoleModuleGrant;
    $grant->forceFill(['role_id' => $role->id, 'module' => ModuleCode::PAYROLL])->save();
    $user = User::factory()->create(['role_id' => $role->id, 'employee_id' => null]);
    test()->actingAs($user);
    app()->forgetInstance(Access::class);

    return $user;
}

function prulesStatus(string $name): EmploymentStatusOption
{
    $status = new EmploymentStatusOption;
    $status->forceFill(['code' => (string) (EmploymentStatusOption::query()->count() + 1), 'name' => $name])->save();

    return $status;
}

/**
 * The reference's figures with some of them changed.
 *
 * @param  array<string, mixed>  $changes  dotted keys
 * @return array<string, mixed>
 */
function prulesFigures(array $changes = []): array
{
    $figures = PayRules::fromFormulas()->toArray();
    foreach ($changes as $path => $value) {
        $ref = &$figures;
        foreach (explode('.', $path) as $key) {
            $ref = &$ref[$key];
        }
        $ref = $value;
        unset($ref);
    }

    return $figures;
}

/** @param array<string, mixed> $changes */
function prulesVersion(string $scope, string $from, array $changes = [], ?string $reason = null): PayrollPayRule
{
    $row = new PayrollPayRule;
    $row->forceFill(['scope' => $scope, 'effective_from' => $from, 'reason' => $reason, 'params' => prulesFigures($changes), 'status' => PayrollPayRule::APPROVED, 'approved_at' => now('UTC')])->save();

    return $row;
}

function prulesMigration(): mixed
{
    return require base_path('database/migrations/2026_10_12_0002_create_payroll_pay_rules_table.php');
}

/*
|--------------------------------------------------------------------------
| The seeded version
|--------------------------------------------------------------------------
*/

it('seeds one company version carrying the Formulas rows\' values and the reference behaviour, once', function () {
    $seeded = PayrollPayRule::query()->sole();
    expect($seeded->scope)->toBe(PayrollPayRule::COMPANY)
        ->and($seeded->effective_from->format('Y-m-d'))->toBe('2025-01-01')
        ->and($seeded->created_by)->toBeNull()
        ->and($seeded->params)->toEqual(PayRules::fromFormulas()->toArray())
        ->and(PayRules::fromArray($seeded->params)->shortfalls())->not->toBe([]);

    $migration = prulesMigration();
    $migration->up();
    expect(PayrollPayRule::query()->count())->toBe(1);

    // The seed writes the columns the table had when it was created. On the
    // finished schema MariaDB refuses that row (the scope kind and the
    // approval stamp came later and are backfilled by their own migrations),
    // so there the edited-Formulas case is PayRulesSchemaTest's, which
    // re-creates the table in its first shape before seeding it.
    if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
        return;
    }

    // On an installation whose Formulas rows were edited, the seeded version carries those values.
    PayrollPayRule::query()->delete();
    $formula = new PayrollFormula;
    $formula->forceFill(['code' => '1', 'key' => 'OT', 'name' => 'Overtime', 'expression' => 'x', 'params' => [['key' => 'percentage', 'value' => 1.5], ['key' => 'max_ot', 'value' => 4]]])->save();
    $migration->up();
    $seeded = PayrollPayRule::query()->sole();
    expect($seeded->params['overtime'])->toEqual(['rate' => 1.5, 'max_hours' => 4.0])
        ->and($seeded->params['night']['rate'])->toBe(0.1)
        ->and(PayRulesStore::rulesFor(['scope' => 'COMPANY', 'version_id' => $seeded->id, 'effective_from' => '2025-01-01', 'figures' => $seeded->params], [])->rates->otRate)->toBe(1.5);
});

/*
|--------------------------------------------------------------------------
| Resolution
|--------------------------------------------------------------------------
*/

it('prices a period by the status override in force on its first day, else the company rule, and refuses a change inside it', function () {
    $contract = prulesStatus('Contract of Service');
    $regular = prulesStatus('Regular');
    $override = prulesVersion($contract->id, '2026-09-01', ['daily_rate.method' => PayRules::METHOD_DIVISOR, 'daily_rate.value' => 22.0]);
    $store = app(PayRulesStore::class);

    $resolved = $store->resolve('2026-09-01', '2026-09-15', $contract->id);
    expect($resolved['error'])->toBeNull()
        ->and($resolved['entry']['scope'])->toBe($contract->id)
        ->and($resolved['entry']['version_id'])->toBe($override->id)
        ->and($resolved['entry']['effective_from'])->toBe('2026-09-01')
        ->and(PayRulesStore::rulesFor($resolved['entry'], [])->dailyRateMethod)->toBe(PayRules::METHOD_DIVISOR);
    expect($store->resolve('2026-09-01', '2026-09-15', $regular->id)['entry']['scope'])->toBe(PayrollPayRule::COMPANY)
        ->and($store->resolve('2026-09-01', '2026-09-15', null)['entry']['scope'])->toBe(PayrollPayRule::COMPANY)
        // Before the override starts, the company rule; a period the override starts inside is refused.
        ->and($store->resolve('2026-08-16', '2026-08-31', $contract->id)['entry']['scope'])->toBe(PayrollPayRule::COMPANY)
        // The period-level read still names a change inside the period; the timeline splits it instead (DIVERGENCE 17).
        ->and($store->resolve('2026-08-20', '2026-09-05', $contract->id)['error'])->toBe('The Pay Basis & Rates change on 01 Sep 2026. End this payroll before that date, or start it on that date.')
        ->and($store->cutoffError('2026-08-20', '2026-09-05', [$regular->id]))->toBeNull()
        ->and($store->cutoffError('2026-08-20', '2026-09-05', [$regular->id, $contract->id]))->toBeNull()
        ->and($store->cutoffError('2026-08-20', '2026-09-05', [$regular->id, $contract->id], [$contract->id => 'Contract of Service']))->toBeNull();
    $timeline = $store->timeline('2026-08-20', '2026-09-05', $contract->id);
    expect($timeline['error'])->toBeNull()
        ->and($timeline['entry']['scope'])->toBe(PayrollPayRule::COMPANY)
        ->and(array_map(static fn (array $s): array => [$s['from'], $s['scope'], $s['version_id']], $timeline['segments']))->toBe([['2026-09-01', $contract->id, $override->id]])
        ->and($store->timeline('2026-09-01', '2026-09-15', $contract->id)['segments'])->toBe([]);

    // A company version landing inside a period splits it for everyone the company rule prices; a company with no version at all cannot run.
    $later = prulesVersion(PayrollPayRule::COMPANY, '2026-09-10');
    expect($store->resolve('2026-09-01', '2026-09-15', null)['error'])->toBe('The Pay Basis & Rates change on 10 Sep 2026. End this payroll before that date, or start it on that date.')
        ->and($store->resolve('2026-09-01', '2026-09-15', $contract->id)['error'])->toBeNull()
        ->and($store->cutoffError('2026-09-01', '2026-09-15', []))->toBeNull()
        ->and($store->cutoffError('2026-09-10', '2026-09-15', []))->toBeNull()
        ->and(array_column($store->timeline('2026-09-01', '2026-09-15', null)['segments'], 'version_id'))->toBe([$later->id])
        ->and($store->timeline('2026-09-01', '2026-09-15', $contract->id)['segments'])->toBe([]);
    PayrollPayRule::query()->where('scope', PayrollPayRule::COMPANY)->delete();
    expect($store->resolve('2026-09-01', '2026-09-15', null)['error'])->toBe('The company has no Pay Basis & Rates version covering 01 Sep 2026. Add one in Payroll Settings › Pay Basis & Rates.')
        ->and($store->timeline('2026-09-01', '2026-09-15', null)['error'])->toBe('The company has no Pay Basis & Rates version covering 01 Sep 2026. Add one in Payroll Settings › Pay Basis & Rates.')
        ->and($store->cutoffError('2026-09-01', '2026-09-15', []))->toBe('The company has no Pay Basis & Rates version covering 01 Sep 2026. Add one in Payroll Settings › Pay Basis & Rates.');
});

/*
|--------------------------------------------------------------------------
| The command
|--------------------------------------------------------------------------
*/

it('saves a version through the audited command and refuses an earlier date, a missing reason, a date off the cut-off and an actor without all data', function () {
    $actor = prulesActor();
    $access = app(Access::class);
    $fixed = prulesFigures(['matrix.REST.overtime' => 169.0, 'matrix.REST.night' => 143.0, 'matrix.REGULAR.unworked' => 100.0, 'matrix.DOUBLE.unworked' => 200.0]);

    $message = app(SavePayRulesVersion::class)->handle($access, PayrollPayRule::COMPANY, '2026-11-01', 'Rates at the minimums', $fixed);
    $saved = PayrollPayRule::query()->where('effective_from', '2026-11-01')->sole();
    expect($message)->toBe('Pay Basis & Rates version from 01 Nov 2026 saved.')
        ->and($saved->params)->toEqual($fixed)
        ->and($saved->reason)->toBe('Rates at the minimums')
        ->and($saved->created_by)->toBe($actor->id)
        ->and($saved->created_by_name)->not->toBe('')
        ->and(AuditLog::query()->where('action', AuditEvent::PAY_RULES_SAVED->value)->where('entity_id', $saved->id)->exists())->toBeTrue();

    expect(fn () => app(SavePayRulesVersion::class)->handle($access, PayrollPayRule::COMPANY, '2026-11-01', 'again', $fixed))
        ->toThrow(RuntimeException::class, 'A version already starts on 01 Nov 2026. Choose a later date.');
    // Every version carries a reason, shortfall or not, and starts on a cut-off's first day.
    expect(fn () => app(SavePayRulesVersion::class)->handle($access, PayrollPayRule::COMPANY, '2026-12-01', '  ', $fixed))
        ->toThrow(RuntimeException::class, 'Enter the reason for this version.');
    expect(fn () => app(SavePayRulesVersion::class)->handle($access, PayrollPayRule::COMPANY, '2026-12-01', 'wrong', ['basis' => 'DAILY']))
        ->toThrow(RuntimeException::class, 'The pay rules are incomplete');
    expect(fn () => app(SavePayRulesVersion::class)->handle($access, '', '2026-12-01', 'wrong', $fixed))
        ->toThrow(RuntimeException::class, 'Choose who the rules apply to.');
    // Only the company or an Employment status: an employee's id is pay data this command never writes.
    expect(fn () => app(SavePayRulesVersion::class)->handle($access, (string) Str::ulid(), '2026-12-01', 'An employee', $fixed))
        ->toThrow(RuntimeException::class, 'Choose who the rules apply to.')
        ->and(PayrollPayRule::query()->where('effective_from', '2026-12-01')->exists())->toBeFalse();
    $status = prulesStatus('Probationary');
    app(SavePayRulesVersion::class)->handle($access, $status->id, '2026-11-16', 'Probation rates', $fixed);
    expect(PayrollPayRule::query()->where('scope', $status->id)->sole()->scope_type)->toBe(PayrollPayRule::SCOPE_STATUS);
    expect(fn () => app(SavePayRulesVersion::class)->handle($access, PayrollPayRule::COMPANY, 'soon', 'wrong', $fixed))
        ->toThrow(RuntimeException::class, 'Enter the date the rules take effect.');
    // A shortfall is saved like any version; the reason is on the row and in the audit line.
    app(SavePayRulesVersion::class)->handle($access, PayrollPayRule::COMPANY, '2026-12-01', 'Managerial staff', prulesFigures());
    expect(PayrollPayRule::query()->where('effective_from', '2026-12-01')->sole()->reason)->toBe('Managerial staff')
        ->and(AuditLog::query()->where('action', AuditEvent::PAY_RULES_SAVED->value)->latest('id')->value('details'))->toContain('(Managerial staff)');
    // A version may start on any day (DIVERGENCE 17): a payroll it falls inside is split, not refused.
    app(SavePayRulesVersion::class)->handle($access, PayrollPayRule::COMPANY, '2026-12-10', 'Mid-period', $fixed);
    expect(PayrollPayRule::query()->where('effective_from', '2026-12-10')->exists())->toBeTrue();

    $count = PayrollPayRule::query()->count();
    prulesActor(false);
    expect(fn () => app(SavePayRulesVersion::class)->handle(app(Access::class), PayrollPayRule::COMPANY, '2027-01-01', 'no', $fixed))
        ->toThrow(RuntimeException::class, 'Your role cannot manage organization-wide data.');
    expect(PayrollPayRule::query()->count())->toBe($count);
});

it('rolls the version back when its audit cannot be recorded', function () {
    prulesActor();
    $count = PayrollPayRule::query()->count();
    AuditLog::creating(static function (): void {
        throw new RuntimeException('Synthetic audit failure');
    });
    expect(fn () => app(SavePayRulesVersion::class)->handle(app(Access::class), PayrollPayRule::COMPANY, '2027-01-01', 'Synthetic', PayRules::preset(PayRules::PRESET_STANDARD)))
        ->toThrow(RuntimeException::class, 'audit record could not be recorded');
    expect(PayrollPayRule::query()->count())->toBe($count);
});

/*
|--------------------------------------------------------------------------
| Evidence
|--------------------------------------------------------------------------
*/

it('captures each member\'s version in the evidence, stales the preview when a version lands and replays the captured figures', function () {
    ['actor' => $actor, 'employee' => $employee] = PayrollEvidenceFixture::make();
    PayrollEvidenceFixture::sourceFacts($employee);
    $regular = prulesStatus('Regular');
    $seeded = PayrollPayRule::query()->sole();

    $first = PayrollData::calculateDrafts([$employee->id], '2026-09-01', '2026-09-15', actor: $actor);
    $inputs = $first['inputs'];
    expect($inputs->payRules[$employee->id]['scope'])->toBe(PayrollPayRule::COMPANY)
        ->and($inputs->payRules[$employee->id]['version_id'])->toBe($seeded->id)
        ->and($inputs->employees[0]->employment_status_id)->toBe($regular->id)
        ->and($inputs->rulesFor($employee->id)->dailyRateMethod)->toBe(PayRules::METHOD_MONTH)
        // A worked day and a paid business-travel day, at 30,000 over September's 22 working days.
        ->and($first['drafts'][0]->work_days_pay)->toBe(2727.27);
    expect(PayrollData::cutoffError($actor, [$employee->id], '2026-09-01', '2026-09-15'))->toBeNull();

    // An override for the member's status, from the period's first day: a different day rate, a different fingerprint.
    prulesVersion($regular->id, '2026-09-01', ['daily_rate.method' => PayRules::METHOD_DIVISOR, 'daily_rate.value' => 20.0]);
    $second = PayrollData::calculateDrafts([$employee->id], '2026-09-01', '2026-09-15', actor: $actor);
    expect($second['inputs']->payRules[$employee->id]['scope'])->toBe($regular->id)
        ->and($second['inputs']->fingerprint())->not->toBe($inputs->fingerprint())
        ->and($second['drafts'][0]->work_days_pay)->toBe(3000.0);

    // The evidence restores to the same figures, and never to the live table.
    $restored = PayrollInputs::restore(PayrollInputs::json($second['inputs']->toArray()));
    expect($restored->fingerprint())->toBe($second['inputs']->fingerprint())
        ->and($restored->rulesFor($employee->id)->dailyRateValue)->toBe(20.0);
    PayrollPayRule::query()->where('scope', $regular->id)->delete();
    expect(PayrollData::calculateInputs($restored, strict: true)['drafts'][0]->work_days_pay)->toBe(3000.0);

    $broken = $second['inputs']->toArray();
    $broken['pay_rules'][$employee->id]['figures']['basis'] = 'WEEKLY';
    expect(fn () => PayrollInputs::restore(PayrollInputs::json($broken)))->toThrow(RuntimeException::class, 'Invalid payroll pay rules evidence.');
    $stranger = $second['inputs']->toArray();
    $stranger['pay_rules']['someone-else'] = $stranger['pay_rules'][$employee->id];
    expect(fn () => PayrollInputs::restore(PayrollInputs::json($stranger)))->toThrow(RuntimeException::class, 'Invalid payroll pay rules evidence.');

    // Older evidence carries no versions: the reference's rules over its captured Formulas rows.
    $seven = $inputs->toArray();
    unset($seven['statutory_tables'], $seven['pay_rules'], $seven['benefit_rules'], $seven['deduction_rules'], $seven['recurring_lines'], $seven['skipped_recurring_lines'], $seven['attendance_through'], $seven['settlements'], $seven['leave_charges'], $seven['leave_cash_outs'], $seven['cash_out_type'], $seven['gov_policies'], $seven['adjustments']);
    $seven['schema_version'] = 7;
    $seven = PayrollEvidenceFixture::beforeSchema17($seven);
    expect(PayrollInputs::restore(PayrollInputs::json($seven))->rulesFor($employee->id)->dailyRateMethod)->toBe(PayRules::METHOD_MONTH);

    // A version landing inside the period splits it (DIVERGENCE 17): the
    // evidence carries the later version as a dated segment, the days before
    // it stay priced by the first (the company rule again, the override
    // deleted above), and a replay never reads the live table.
    $later = prulesVersion($regular->id, '2026-09-10', ['daily_rate.method' => PayRules::METHOD_DIVISOR, 'daily_rate.value' => 25.0]);
    expect(PayrollData::cutoffError($actor, [$employee->id], '2026-09-01', '2026-09-15'))->toBeNull();
    $third = PayrollData::calculateDrafts([$employee->id], '2026-09-01', '2026-09-15', actor: $actor);
    $segments = $third['inputs']->payRules[$employee->id]['segments'] ?? [];
    expect(array_map(static fn (array $s): array => [$s['from'], $s['version_id']], $segments))->toBe([['2026-09-10', $later->id]])
        ->and($third['inputs']->payRules[$employee->id]['scope'])->toBe(PayrollPayRule::COMPANY)
        ->and($third['inputs']->timelineFor($employee->id)->isSingle())->toBeFalse()
        ->and($third['inputs']->timelineFor($employee->id)->on('2026-09-09')->dailyRateMethod)->toBe(PayRules::METHOD_MONTH)
        ->and($third['inputs']->timelineFor($employee->id)->on('2026-09-10')->dailyRateValue)->toBe(25.0)
        ->and($third['inputs']->fingerprint())->not->toBe($inputs->fingerprint())
        // Both paid days fall before the 10th: at the company's day over September's 22 working days.
        ->and($third['drafts'][0]->work_days_pay)->toBe(2727.27);
    $restoredThird = PayrollInputs::restore(PayrollInputs::json($third['inputs']->toArray()));
    expect($restoredThird->fingerprint())->toBe($third['inputs']->fingerprint())
        ->and($restoredThird->timelineFor($employee->id)->segments[1]['from'])->toBe('2026-09-10');
    PayrollPayRule::query()->where('scope', $regular->id)->delete();
    expect(PayrollData::calculateInputs($restoredThird, strict: true)['drafts'][0]->work_days_pay)->toBe(2727.27);
    $brokenSegment = $third['inputs']->toArray();
    $brokenSegment['pay_rules'][$employee->id]['segments'][0]['from'] = '2026-08-01';
    $brokenSegment['pay_rules'][$employee->id]['segments'][] = $brokenSegment['pay_rules'][$employee->id]['segments'][0];
    expect(fn () => PayrollInputs::restore(PayrollInputs::json($brokenSegment)))->toThrow(RuntimeException::class, 'Invalid payroll pay rules evidence.');
});

/*
|--------------------------------------------------------------------------
| The rate-step keys (2026_11_02_0007)
|--------------------------------------------------------------------------
*/

function prulesRateFiguresMigration(): mixed
{
    return require base_path('database/migrations/2026_11_02_0007_add_rate_figures_to_payroll_pay_rules_table.php');
}

it('adds the three rate-step keys, blank, to every stored version once and takes them away on rollback', function () {
    // A version saved before the keys existed, and a "follows" row with no figures.
    $older = prulesFigures();
    unset($older['hourly_rate'], $older['rounding'], $older['overtime']['seaport_rate']);
    $legacy = new PayrollPayRule;
    $legacy->forceFill(['scope' => 'st-legacy', 'effective_from' => '2026-01-01', 'reason' => 'Before', 'params' => $older, 'status' => PayrollPayRule::APPROVED, 'approved_at' => now('UTC')])->save();
    $follows = new PayrollPayRule;
    $follows->forceFill(['scope' => (string) Str::ulid(), 'scope_type' => PayrollPayRule::SCOPE_EMPLOYEE, 'effective_from' => '2026-01-01', 'reason' => 'Follows', 'params' => null, 'status' => PayrollPayRule::APPROVED, 'approved_at' => now('UTC')])->save();
    $set = new PayrollPayRule;
    $set->forceFill(['scope' => 'st-set', 'effective_from' => '2026-01-01', 'reason' => 'Set', 'params' => prulesFigures(['hourly_rate.hours' => 8.0, 'rounding.decimals' => 2, 'overtime.seaport_rate' => 1.5]), 'status' => PayrollPayRule::APPROVED, 'approved_at' => now('UTC')])->save();

    $migration = prulesRateFiguresMigration();
    $migration->up();
    $migration->up();

    $read = $legacy->fresh()->params;
    expect($read['hourly_rate'])->toBe(['hours' => null])
        ->and($read['rounding'])->toBe(['decimals' => null])
        ->and($read['overtime'])->toEqual(['rate' => 1.25, 'max_hours' => 8.0, 'seaport_rate' => null])
        ->and($read)->toEqual(PayRules::fromFormulas()->toArray())
        ->and($follows->fresh()->params)->toBeNull()
        // A figure already set is kept.
        ->and($set->fresh()->params['hourly_rate']['hours'])->toEqual(8)
        ->and($set->fresh()->params['rounding']['decimals'])->toBe(2)
        ->and($set->fresh()->params['overtime']['seaport_rate'])->toEqual(1.5);

    $migration->down();
    $down = $set->fresh()->params;
    expect(array_key_exists('hourly_rate', $down))->toBeFalse()
        ->and(array_key_exists('rounding', $down))->toBeFalse()
        ->and(array_key_exists('seaport_rate', $down['overtime']))->toBeFalse()
        // Read without the keys, the version is blank on all three — the reference.
        ->and(PayRules::fromArray($down)->hourlyHours)->toBeNull();
});
