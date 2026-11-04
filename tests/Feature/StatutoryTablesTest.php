<?php

declare(strict_types=1);

use App\Domain\Payroll\PayslipDraft;
use App\Domain\Payroll\Statutory;
use App\Domain\Payroll\StatutorySchedule;
use App\Models\AuditLog;
use App\Models\PayrollFinalizationSnapshot;
use App\Models\PayrollRun;
use App\Models\PayrollStatutoryTable;
use App\Models\Payslip;
use App\Models\Role;
use App\Models\RoleModuleGrant;
use App\Models\User;
use App\Modules\Payroll\Application\Commands\AddStatutoryTableVersion;
use App\Modules\Payroll\Application\Commands\ConfirmStatutoryTable;
use App\Modules\Payroll\Application\Commands\FinalizePayroll;
use App\Modules\Payroll\Application\Commands\UpdateStatutoryTableVersion;
use App\Modules\Payroll\Application\Commands\WithdrawStatutoryTableVersion;
use App\Modules\Payroll\Application\PayrollData;
use App\Modules\Payroll\Application\PayrollInputs;
use App\Modules\Payroll\Application\StatutoryTables;
use App\Modules\Payroll\Application\ThirteenthMonthData;
use App\Modules\Payroll\Application\ThirteenthMonthInputs;
use App\Support\Access;
use Illuminate\Support\Str;
use Tests\Support\PayrollEvidenceFixture;
use Tests\Support\PayrollReviewFixture;

/**
 * The Contribution & Tax Tables are the figures payroll computes from: the
 * migration seeds the engine's former constants so no money moves, a period
 * is priced by the versions in force on its first day — the latest version
 * never expires, a period past its confirmed-through date only gets a
 * reminder — and refused only when no version is in force or a later one
 * starts inside it, every version is captured in the evidence (so a new one
 * stales a preview and a finalized run never changes), and the commands
 * that add, confirm, correct or withdraw a version are gated, validated and
 * audited, a version in force being history nothing corrects.
 */
function sttRecordsAccess(): Access
{
    $role = new Role;
    $role->forceFill(['name' => 'Tables '.Str::random(6), 'all_data' => true, 'is_system' => false])->save();
    foreach (['RECORDS', 'PAYROLL'] as $module) {
        $grant = new RoleModuleGrant;
        $grant->forceFill(['role_id' => $role->id, 'module' => $module])->save();
    }

    return Access::forActor(User::factory()->create(['role_id' => $role->id, 'employee_id' => null]));
}

function sttLimitedAccess(): Access
{
    $role = new Role;
    $role->forceFill(['name' => 'Tables viewer '.Str::random(6), 'all_data' => false, 'is_system' => false])->save();
    $grant = new RoleModuleGrant;
    $grant->forceFill(['role_id' => $role->id, 'module' => 'RECORDS'])->save();

    return Access::forActor(User::factory()->create(['role_id' => $role->id, 'employee_id' => null]));
}

/** @return array<string, mixed> */
function sttPhilhealth(float $employeeRate = 0.03): array
{
    return ['floor' => 10000.0, 'cap' => 100000.0, 'ee_rate' => $employeeRate, 'er_rate' => 0.025];
}

function sttSeededSchedule(): StatutorySchedule
{
    return StatutorySchedule::fromArrays(
        PayrollStatutoryTable::query()->get()->mapWithKeys(static fn (PayrollStatutoryTable $row): array => [$row->kind => $row->params])->all(),
    );
}

/**
 * The fifteen golden fields, through one schedule.
 *
 * @return list<float>
 */
function sttRow(float $input, StatutorySchedule $schedule): array
{
    $sss = Statutory::sss($input, $schedule);
    $ph = Statutory::philhealth($input, $schedule);
    $pi = Statutory::pagibig($input, $schedule);

    return [
        $sss['employee'], $sss['employer'], $ph['employee'], $ph['employer'], $pi['employee'], $pi['employer'],
        Statutory::semiMonthlyWithholdingTax($input, $schedule), Statutory::annualIncomeTax($input, $schedule),
        Statutory::annualizedSemiMonthlyWithholdingTax($input, $schedule),
        Statutory::semiMonthlyShare($sss['employee']), Statutory::semiMonthlyShare($sss['employer']),
        Statutory::semiMonthlyShare($ph['employee']), Statutory::semiMonthlyShare($ph['employer']),
        Statutory::semiMonthlyShare($pi['employee']), Statutory::semiMonthlyShare($pi['employer']),
    ];
}

/*
|--------------------------------------------------------------------------
| The seeded versions
|--------------------------------------------------------------------------
*/

it('seeds one built-in version per kind and replays every golden statutory vector through them bit for bit', function () {
    $rows = PayrollStatutoryTable::query()->orderBy('kind')->get();
    expect($rows->pluck('kind')->all())->toBe(['PAGIBIG', 'PHILHEALTH', 'SSS', 'WITHHOLDING_TAX'])
        ->and($rows->pluck('effective_from')->map->format('Y-m-d')->unique()->all())->toBe(['2025-01-01'])
        ->and($rows->pluck('covered_through')->map->format('Y-m-d')->unique()->all())->toBe(['2026-12-31'])
        ->and($rows->pluck('created_by')->unique()->all())->toBe([null]);
    $schedule = sttSeededSchedule();
    expect($schedule->toArray())->toEqual(StatutorySchedule::BUILT_IN);

    $handle = fopen(__DIR__.'/../fixtures/golden-statutory.ndjson', 'rb');
    if ($handle === false) {
        throw new RuntimeException('Fixture missing.');
    }
    $count = 0;
    $mismatches = 0;
    while (($line = fgets($handle)) !== false) {
        $line = trim($line);
        if ($line === '') {
            continue;
        }
        $row = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
        $input = (float) array_shift($row);
        $count++;
        foreach (sttRow($input, $schedule) as $i => $actual) {
            if ($actual !== (float) $row[$i]) {
                $mismatches++;
            }
        }
    }
    fclose($handle);
    expect($count)->toBeGreaterThan(50_000)->and($mismatches)->toBe(0);
});

/*
|--------------------------------------------------------------------------
| Resolution and the cut-off check
|--------------------------------------------------------------------------
*/

it('prices a period by the versions in force on its first day, refuses one before the first version or split by a later one, and reminds past the confirmed date', function () {
    $tables = app(StatutoryTables::class);
    $seededSss = (string) PayrollStatutoryTable::query()->where('kind', StatutorySchedule::SSS)->value('id');
    $sssReminder = 'The SSS table has not been confirmed past 31 Dec 2026. Check the current figures, then confirm the table in Contribution & Tax Tables.';

    $resolved = $tables->resolve('2026-01-01', '2026-01-15');
    expect($resolved->error)->toBeNull()
        ->and($resolved->warning)->toBeNull()
        ->and($resolved->tables[StatutorySchedule::SSS]['version_id'])->toBe($seededSss)
        ->and($resolved->tables[StatutorySchedule::SSS]['effective_from'])->toBe('2025-01-01')
        ->and($resolved->tables[StatutorySchedule::SSS]['covered_through'])->toBe('2026-12-31')
        ->and($resolved->schedule->toArray())->toEqual(StatutorySchedule::BUILT_IN)
        ->and($tables->cutoffError('2026-12-16', '2026-12-31'))->toBeNull()
        ->and($tables->cutoffWarning('2026-12-16', '2026-12-31'))->toBeNull()
        // The latest version stays in force past its confirmed date: a reminder, never a refusal.
        ->and($tables->cutoffError('2027-01-01', '2027-01-15'))->toBeNull()
        ->and($tables->cutoffWarning('2027-01-01', '2027-01-15'))->toBe($sssReminder)
        ->and($tables->cutoffError('2026-12-16', '2027-01-05'))->toBeNull()
        ->and($tables->cutoffWarning('2026-12-16', '2027-01-05'))->toBe($sssReminder)
        ->and($tables->resolve('2027-01-01', '2027-01-15')->tables[StatutorySchedule::SSS]['version_id'])->toBe($seededSss)
        // Before the first version there is nothing to price from.
        ->and($tables->cutoffError('2024-12-16', '2024-12-31'))->toBe('No SSS table is in force on 16 Dec 2024. Add a version that starts on or before it in Contribution & Tax Tables.')
        ->and($tables->cutoffWarning('2024-12-16', '2024-12-31'))->toBeNull()
        ->and($tables->cutoffError('2026-13-01', '2026-13-05'))->toBe('Choose valid start and end dates.')
        ->and($tables->yearlyCutoffError(2026))->toBeNull()
        ->and($tables->yearlyCutoffWarning(2026))->toBeNull()
        ->and($tables->yearlyCutoffError(2027))->toBeNull()
        ->and($tables->yearlyCutoffWarning(2027))->toBe('The Withholding Tax table has not been confirmed past 31 Dec 2026. Check the current figures, then confirm the table in Contribution & Tax Tables.')
        ->and($tables->yearlyCutoffError(2024))->toBe('No Withholding Tax table is in force on 31 Dec 2024. Add a version that starts on or before it in Contribution & Tax Tables.')
        ->and($tables->confirmedThrough())->toBe('2026-12-31')
        ->and($tables->confirmationEndsWith())->toBe(StatutorySchedule::KINDS);

    // A PhilHealth version from 1 July, confirmed through 2027: the seeded
    // one keeps its own confirmed date and stays in force until then, and
    // the period that straddles the change is refused.
    app(AddStatutoryTableVersion::class)->handle(sttRecordsAccess(), StatutorySchedule::PHILHEALTH, '2026-07-01', '2027-12-31', '', sttPhilhealth());
    $seededPhilhealth = PayrollStatutoryTable::query()->where('kind', StatutorySchedule::PHILHEALTH)->orderBy('effective_from')->first();
    $newPhilhealth = PayrollStatutoryTable::query()->where('kind', StatutorySchedule::PHILHEALTH)->orderByDesc('effective_from')->first();
    expect($seededPhilhealth->covered_through->format('Y-m-d'))->toBe('2026-12-31')
        ->and($tables->cutoffError('2026-06-16', '2026-07-05'))->toBe('The PhilHealth table changes on 01 Jul 2026. End this payroll before that date, or start it on that date.')
        ->and($tables->cutoffWarning('2026-06-16', '2026-07-05'))->toBeNull()
        ->and($tables->cutoffError('2026-06-16', '2026-06-30'))->toBeNull()
        ->and($tables->cutoffError('2026-07-01', '2026-07-15'))->toBeNull()
        ->and($tables->resolve('2026-06-16', '2026-06-30')->tables[StatutorySchedule::PHILHEALTH]['version_id'])->toBe($seededPhilhealth->id)
        ->and($tables->resolve('2026-07-01', '2026-07-15')->tables[StatutorySchedule::PHILHEALTH]['version_id'])->toBe($newPhilhealth->id)
        ->and($tables->resolve('2026-07-01', '2026-07-15')->schedule->philhealth['ee_rate'])->toBe(0.03)
        // The whole-period confirmation is the earliest of the latest versions'; PhilHealth now reaches further.
        ->and($tables->confirmedThrough())->toBe('2026-12-31')
        ->and($tables->confirmationEndsWith())->toBe([StatutorySchedule::SSS, StatutorySchedule::PAGIBIG, StatutorySchedule::WITHHOLDING_TAX])
        ->and($tables->cutoffWarning('2027-01-01', '2027-01-15'))->toBe($sssReminder);
});

/*
|--------------------------------------------------------------------------
| The commands
|--------------------------------------------------------------------------
*/

it('adds a version only for a PAYROLL all-data holder, validates it, supersedes the current one and audits it', function () {
    $before = PayrollStatutoryTable::query()->count();
    expect(fn () => app(AddStatutoryTableVersion::class)->handle(sttLimitedAccess(), StatutorySchedule::PHILHEALTH, '2026-07-01', '2026-12-31', '', sttPhilhealth()))
        ->toThrow(RuntimeException::class, 'Your role cannot manage organization-wide data.');
    expect(PayrollStatutoryTable::query()->count())->toBe($before)->and(AuditLog::query()->count())->toBe(0);

    $access = sttRecordsAccess();
    $add = fn (string $kind, string $from, string $through, array $figures, string $reference = ''): string => app(AddStatutoryTableVersion::class)->handle($access, $kind, $from, $through, $reference, $figures);
    expect(fn () => $add('GSIS', '2026-07-01', '2026-12-31', []))->toThrow(RuntimeException::class, 'Choose which table this is.');
    expect(fn () => $add(StatutorySchedule::PHILHEALTH, '2026-07-00', '2026-12-31', sttPhilhealth()))->toThrow(RuntimeException::class, 'Enter the date it takes effect and the date its figures are confirmed through.');
    expect(fn () => $add(StatutorySchedule::PHILHEALTH, '2026-07-01', '2026-06-30', sttPhilhealth()))->toThrow(RuntimeException::class, 'The confirmed-through date cannot fall before the effective date.');
    expect(fn () => $add(StatutorySchedule::PHILHEALTH, '2026-07-01', '2026-12-31', sttPhilhealth(1.5)))->toThrow(RuntimeException::class, 'The ee_rate must be between 0% and 100%.');
    expect(fn () => $add(StatutorySchedule::PHILHEALTH, '2026-07-01', '2026-12-31', sttPhilhealth(), str_repeat('x', 161)))->toThrow(RuntimeException::class, 'The reference must be at most 160 characters.');
    expect(fn () => $add(StatutorySchedule::PHILHEALTH, '2025-01-01', '2026-12-31', sttPhilhealth()))->toThrow(RuntimeException::class, 'A PhilHealth table version already starts on 01 Jan 2025. Choose a later date.');
    expect(PayrollStatutoryTable::query()->count())->toBe($before)->and(AuditLog::query()->count())->toBe(0);

    expect($add(StatutorySchedule::PHILHEALTH, '2026-07-01', '2026-12-31', sttPhilhealth(), ' Memo 7 '))->toBe('PhilHealth table version from 01 Jul 2026 saved.');
    $versions = PayrollStatutoryTable::query()->where('kind', StatutorySchedule::PHILHEALTH)->orderBy('effective_from')->get();
    expect($versions)->toHaveCount(2)
        // The superseded version keeps its own confirmed date: nothing is rewritten under it.
        ->and($versions[0]->covered_through->format('Y-m-d'))->toBe('2026-12-31')
        ->and($versions[1]->params)->toEqual(sttPhilhealth())
        ->and($versions[1]->reference)->toBe('Memo 7')
        ->and($versions[1]->created_by)->toBe((int) $access->userId)
        ->and($versions[1]->created_by_name)->toBe($access->fullName);
    $audit = AuditLog::query()->where('action', 'STATUTORY_TABLE_ADDED')->sole();
    expect($audit->entity_id)->toBe($versions[1]->id)
        ->and($audit->details)->toBe('PhilHealth table version from 01 Jul 2026 added, confirmed through 31 Dec 2026 (Memo 7)');

    // The next version must start after the latest; a gap after the confirmed date is no obstacle.
    expect(fn () => $add(StatutorySchedule::PHILHEALTH, '2026-07-01', '2026-12-31', sttPhilhealth()))->toThrow(RuntimeException::class, 'A PhilHealth table version already starts on 01 Jul 2026. Choose a later date.');

    // Only the latest version can be confirmed, never before its start, and only to a later date.
    $confirm = fn (string $id, string $through): string => app(ConfirmStatutoryTable::class)->handle($access, $id, $through);
    expect(fn () => $confirm($versions[0]->id, '2027-12-31'))->toThrow(RuntimeException::class, 'A later PhilHealth table version already starts; confirm that one.');
    expect(fn () => $confirm($versions[1]->id, '2026-06-30'))->toThrow(RuntimeException::class, 'The confirmed-through date cannot fall before the effective date.');
    expect(fn () => $confirm($versions[1]->id, '2026-12-31'))->toThrow(RuntimeException::class, 'The PhilHealth table is already confirmed through 31 Dec 2026. Choose a later date.');
    expect(fn () => $confirm('missing', '2027-12-31'))->toThrow(RuntimeException::class, 'That table version no longer exists.');
    expect(fn () => app(ConfirmStatutoryTable::class)->handle(sttLimitedAccess(), $versions[1]->id, '2027-12-31'))->toThrow(RuntimeException::class, 'Your role cannot manage organization-wide data.');
    expect($confirm($versions[1]->id, '2027-12-31'))->toBe('PhilHealth table confirmed through 31 Dec 2027.');
    expect($versions[1]->fresh()->covered_through->format('Y-m-d'))->toBe('2027-12-31')
        ->and(AuditLog::query()->where('action', 'STATUTORY_TABLE_CONFIRMED')->sole()->details)
        ->toBe('PhilHealth table version from 01 Jul 2026 confirmed through 31 Dec 2027 (was 31 Dec 2026)');

    // A version may start well after the confirmed date: the one before it simply stays in force until then.
    expect($add(StatutorySchedule::PHILHEALTH, '2029-06-01', '2029-12-31', sttPhilhealth(0.035)))->toBe('PhilHealth table version from 01 Jun 2029 saved.');
    expect(app(StatutoryTables::class)->resolve('2028-03-01', '2028-03-15')->tables[StatutorySchedule::PHILHEALTH]['version_id'])->toBe($versions[1]->id);
});

it('corrects or withdraws a version only before it takes effect and only for a PAYROLL all-data holder, and audits both', function () {
    $this->travelTo(new DateTimeImmutable('2026-10-12T09:00:00+08:00'));
    $access = sttRecordsAccess();
    $seeded = PayrollStatutoryTable::query()->where('kind', StatutorySchedule::PHILHEALTH)->sole();
    $add = fn (string $from, string $through, string $reference, array $figures): string => app(AddStatutoryTableVersion::class)->handle($access, StatutorySchedule::PHILHEALTH, $from, $through, $reference, $figures);
    $add('2027-01-01', '2027-12-31', 'Memo 1', sttPhilhealth(0.03));
    $future = PayrollStatutoryTable::query()->where('kind', StatutorySchedule::PHILHEALTH)->orderByDesc('effective_from')->first();
    $add('2027-07-01', '2027-12-31', '', sttPhilhealth(0.035));
    $later = PayrollStatutoryTable::query()->where('kind', StatutorySchedule::PHILHEALTH)->orderByDesc('effective_from')->first();
    $update = fn (string $id, string $from, string $through, string $reference, array $figures): string => app(UpdateStatutoryTableVersion::class)->handle($access, $id, $from, $through, $reference, $figures);
    $withdraw = fn (string $id): string => app(WithdrawStatutoryTableVersion::class)->handle($access, $id);
    $inForce = 'The PhilHealth table version from 01 Jan 2025 has taken effect and stays on record. Add a new version to change the figures.';

    expect(fn () => app(UpdateStatutoryTableVersion::class)->handle(sttLimitedAccess(), $future->id, '2027-02-01', '2027-12-31', '', sttPhilhealth(0.03)))->toThrow(RuntimeException::class, 'Your role cannot manage organization-wide data.');
    expect(fn () => app(WithdrawStatutoryTableVersion::class)->handle(sttLimitedAccess(), $future->id))->toThrow(RuntimeException::class, 'Your role cannot manage organization-wide data.');
    // A version in force is history.
    expect(fn () => $update($seeded->id, '2027-02-01', '2027-12-31', '', sttPhilhealth(0.03)))->toThrow(RuntimeException::class, $inForce);
    expect(fn () => $withdraw($seeded->id))->toThrow(RuntimeException::class, $inForce);
    // A correction keeps the version upcoming and between its neighbours, and is validated like an addition.
    expect(fn () => $update($future->id, '2026-10-12', '2027-12-31', '', sttPhilhealth(0.03)))->toThrow(RuntimeException::class, 'The effective date must be after today. A version that takes effect today or earlier is added as a new version.');
    expect(fn () => $update($future->id, '2027-07-01', '2027-12-31', '', sttPhilhealth(0.03)))->toThrow(RuntimeException::class, 'The next PhilHealth table version starts on 01 Jul 2027. Choose an earlier date.');
    expect(fn () => $update($later->id, '2027-01-01', '2027-12-31', '', sttPhilhealth(0.035)))->toThrow(RuntimeException::class, 'A PhilHealth table version already starts on 01 Jan 2027. Choose a later date.');
    expect(fn () => $update($future->id, '2027-02-01', '2027-01-31', '', sttPhilhealth(0.03)))->toThrow(RuntimeException::class, 'The confirmed-through date cannot fall before the effective date.');
    expect(fn () => $update($future->id, '2027-02-01', '2027-12-31', '', sttPhilhealth(1.5)))->toThrow(RuntimeException::class, 'The ee_rate must be between 0% and 100%.');
    expect(fn () => $update('missing', '2027-02-01', '2027-12-31', '', sttPhilhealth(0.03)))->toThrow(RuntimeException::class, 'That table version no longer exists.');
    expect(AuditLog::query()->whereIn('action', ['STATUTORY_TABLE_UPDATED', 'STATUTORY_TABLE_WITHDRAWN'])->count())->toBe(0)
        ->and($future->fresh()->effective_from->format('Y-m-d'))->toBe('2027-01-01')
        ->and(PayrollStatutoryTable::query()->where('kind', StatutorySchedule::PHILHEALTH)->count())->toBe(3);

    expect($update($future->id, '2027-02-01', '2028-06-30', ' Memo 2 ', sttPhilhealth(0.04)))->toBe('PhilHealth table version from 01 Feb 2027 saved.');
    $corrected = $future->fresh();
    expect($corrected->effective_from->format('Y-m-d'))->toBe('2027-02-01')
        ->and($corrected->covered_through->format('Y-m-d'))->toBe('2028-06-30')
        ->and($corrected->reference)->toBe('Memo 2')
        ->and($corrected->params)->toEqual(sttPhilhealth(0.04))
        ->and($corrected->created_by)->toBe($future->created_by)
        ->and(AuditLog::query()->where('action', 'STATUTORY_TABLE_UPDATED')->sole()->details)
        ->toBe('PhilHealth table version from 01 Jan 2027 corrected: from 01 Feb 2027, confirmed through 30 Jun 2028 (Memo 2)')
        ->and(app(StatutoryTables::class)->resolve('2027-02-01', '2027-02-15')->schedule->philhealth['ee_rate'])->toBe(0.04);

    // A finalized payroll reaching a version's dates holds it; one ending before them does not.
    PayrollRun::create(['code' => 'PR-F', 'name' => 'Posted', 'period_start' => '2027-06-16', 'period_end' => '2027-06-30', 'status' => 'FINALIZED']);
    expect(fn () => $withdraw($future->id))->toThrow(RuntimeException::class, 'A finalized payroll already reaches 01 Feb 2027, so the PhilHealth table version from 01 Feb 2027 stays on record.');
    expect(fn () => $update($future->id, '2027-03-01', '2028-06-30', '', sttPhilhealth(0.04)))->toThrow(RuntimeException::class, 'A finalized payroll already reaches 01 Feb 2027, so the PhilHealth table version from 01 Feb 2027 stays on record.');
    expect($withdraw($later->id))->toBe('PhilHealth table version from 01 Jul 2027 withdrawn.');
    expect(PayrollStatutoryTable::query()->whereKey($later->id)->exists())->toBeFalse()
        ->and(AuditLog::query()->where('action', 'STATUTORY_TABLE_WITHDRAWN')->sole()->details)->toBe('PhilHealth table version from 01 Jul 2027 withdrawn before taking effect')
        ->and(app(StatutoryTables::class)->resolve('2027-07-01', '2027-07-15')->tables[StatutorySchedule::PHILHEALTH]['version_id'])->toBe($future->id);
    expect(fn () => $withdraw($later->id))->toThrow(RuntimeException::class, 'That table version no longer exists.');
});

/*
|--------------------------------------------------------------------------
| Evidence
|--------------------------------------------------------------------------
*/

it('captures the versions in the evidence, stales a preview when a version lands, and leaves a finalized run alone', function () {
    ['actor' => $actor, 'employee' => $employee, 'run' => $run] = PayrollEvidenceFixture::make();
    PayrollEvidenceFixture::sourceFacts($employee);
    $seededPhilhealth = (string) PayrollStatutoryTable::query()->where('kind', StatutorySchedule::PHILHEALTH)->value('id');

    $first = PayrollData::calculateDrafts([$employee->id], '2026-09-01', '2026-09-15', actor: $actor);
    $inputs = $first['inputs'];
    expect($inputs->statutoryTables[StatutorySchedule::PHILHEALTH]['version_id'])->toBe($seededPhilhealth)
        ->and(array_keys($inputs->statutoryTables))->toBe(StatutorySchedule::KINDS)
        ->and($inputs->toArray()['schema_version'])->toBe(PayrollInputs::SCHEMA_VERSION)
        ->and($inputs->toArray()['statutory_tables'][StatutorySchedule::SSS]['figures'])->toEqual(StatutorySchedule::BUILT_IN[StatutorySchedule::SSS]);

    // Round trip, and the older shapes restore to the built-in figures.
    $restored = PayrollInputs::restore(PayrollInputs::json($inputs->toArray()));
    expect($restored->fingerprint())->toBe($inputs->fingerprint())
        ->and($restored->statutoryTables)->toEqual($inputs->statutoryTables);
    $seven = $inputs->toArray();
    unset($seven['statutory_tables'], $seven['pay_rules'], $seven['benefit_rules'], $seven['deduction_rules'], $seven['recurring_lines'], $seven['skipped_recurring_lines'], $seven['attendance_through'], $seven['settlements'], $seven['leave_charges'], $seven['leave_cash_outs'], $seven['cash_out_type'], $seven['gov_policies'], $seven['adjustments']);
    $seven['schema_version'] = 7;
    $seven = PayrollEvidenceFixture::beforeSchema17($seven);
    $restoredSeven = PayrollInputs::restore(PayrollInputs::json($seven));
    expect($restoredSeven->statutoryTables)->toBe([])
        ->and($restoredSeven->schedule()->toArray())->toEqual(StatutorySchedule::BUILT_IN)
        ->and($restoredSeven->fingerprint())->toBe(hash('sha256', PayrollInputs::json($seven)));
    $broken = $inputs->toArray();
    $broken['statutory_tables'][StatutorySchedule::SSS]['figures']['ee_rate'] = 2;
    expect(fn () => PayrollInputs::restore(PayrollInputs::json($broken)))->toThrow(RuntimeException::class, 'Invalid payroll statutory table evidence.');
    $missing = $inputs->toArray();
    unset($missing['statutory_tables'][StatutorySchedule::PAGIBIG]);
    expect(fn () => PayrollInputs::restore(PayrollInputs::json($missing)))->toThrow(RuntimeException::class, 'Invalid payroll statutory table evidence.');

    // Finalized with the seeded figures.
    app(FinalizePayroll::class)->handle($actor, $run->id, PayrollReviewFixture::token($actor, $run->id));
    $posted = Payslip::query()->sole()->toArray();
    $snapshot = PayrollFinalizationSnapshot::query()->sole();
    expect($posted['philhealth'])->toBe(375.0);

    // A PhilHealth version from the period's first day: the same draft now
    // prices PhilHealth at 3%, its fingerprint moves, the posted payslip
    // and its evidence do not, and the evidence replays the seeded version.
    app(AddStatutoryTableVersion::class)->handle(sttRecordsAccess(), StatutorySchedule::PHILHEALTH, '2026-09-01', '2026-12-31', '', sttPhilhealth());
    $second = PayrollData::calculateDrafts([$employee->id], '2026-09-01', '2026-09-15', actor: $actor);
    expect($second['inputs']->fingerprint())->not->toBe($inputs->fingerprint())
        ->and($second['inputs']->statutoryTables[StatutorySchedule::PHILHEALTH]['version_id'])->not->toBe($seededPhilhealth)
        ->and($second['drafts'][0]->philhealth)->toBe(450.0)
        ->and($first['drafts'][0]->philhealth)->toBe(375.0)
        ->and(Payslip::query()->sole()->toArray())->toBe($posted)
        ->and($snapshot->fresh()->payload)->toBe($snapshot->payload);
    $payload = json_decode($snapshot->payload, true, 512, JSON_THROW_ON_ERROR);
    $replayed = PayrollInputs::restore(PayrollInputs::json($payload['inputs']));
    expect($replayed->statutoryTables[StatutorySchedule::PHILHEALTH]['version_id'])->toBe($seededPhilhealth)
        ->and(array_map(fn (PayslipDraft $draft) => $draft->toArray(), PayrollData::calculateInputs($replayed, strict: true)['drafts']))->toEqual($payload['results']);

    // A period before any version is refused before it computes; one past the confirmed date is not.
    expect(fn () => PayrollData::calculateDrafts([$employee->id], '2024-12-16', '2024-12-31', actor: $actor))
        ->toThrow(RuntimeException::class, 'No SSS table is in force on 16 Dec 2024. Add a version that starts on or before it in Contribution & Tax Tables.');
    expect(PayrollData::calculateDrafts([$employee->id], '2027-01-01', '2027-01-15', actor: $actor)['inputs']->statutoryTables[StatutorySchedule::SSS]['version_id'])
        ->toBe((string) PayrollStatutoryTable::query()->where('kind', StatutorySchedule::SSS)->value('id'));
});

it('captures the withholding table in the 13th month evidence and restores the older shape with the built-in figures', function () {
    ['actor' => $actor, 'employee' => $employee] = PayrollEvidenceFixture::make();
    $seededWithholding = (string) PayrollStatutoryTable::query()->where('kind', StatutorySchedule::WITHHOLDING_TAX)->value('id');

    $inputs = ThirteenthMonthData::calculateDrafts([$employee->id], 2026, actor: $actor)['inputs'];
    expect($inputs->withholdingTable[StatutorySchedule::WITHHOLDING_TAX]['version_id'])->toBe($seededWithholding)
        ->and($inputs->toArray()['schema_version'])->toBe(5)
        ->and(array_keys($inputs->toArray()['withholding_table']))->toBe([StatutorySchedule::WITHHOLDING_TAX]);
    $restored = ThirteenthMonthInputs::restore(PayrollInputs::json($inputs->toArray()));
    expect($restored->fingerprint())->toBe($inputs->fingerprint())
        ->and($restored->schedule()->toArray())->toEqual(StatutorySchedule::BUILT_IN);

    $two = $inputs->toArray();
    unset($two['withholding_table'], $two['benefit_ceiling']);
    // Schema 2 carried no salary segments (4) or whole-year keys (5) on an employee.
    $two['employees'] = array_map(static fn (array $e): array => array_diff_key($e, ['salary_rates' => true, 'whole_year_tax' => true, 'tax_spread' => true]), $two['employees']);
    $two['schema_version'] = 2;
    $restoredTwo = ThirteenthMonthInputs::restore(PayrollInputs::json($two));
    expect($restoredTwo->withholdingTable)->toBe([])
        ->and($restoredTwo->schedule()->toArray())->toEqual(StatutorySchedule::BUILT_IN)
        ->and($restoredTwo->fingerprint())->toBe(hash('sha256', PayrollInputs::json($two)));

    expect(fn () => ThirteenthMonthData::calculateDrafts([$employee->id], 2024, actor: $actor))
        ->toThrow(RuntimeException::class, 'No Withholding Tax table is in force on 31 Dec 2024. Add a version that starts on or before it in Contribution & Tax Tables.');
});
