<?php

declare(strict_types=1);

use App\Livewire\Payroll\IndividualPayslip;
use App\Livewire\Payroll\RunWorkspace;
use App\Models\AttendanceLog;
use App\Models\EmployeeProfile;
use App\Models\PayrollDeduction;
use App\Models\PayrollFinalizationSnapshot;
use App\Models\PayrollRun;
use App\Models\PayrollRunMember;
use App\Models\Role;
use App\Models\RoleModuleGrant;
use App\Models\User;
use App\Modules\Payroll\Application\Catalogs\Deductions;
use App\Support\Access;
use Illuminate\Support\Str;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

/**
 * Every Payroll Deductions row is a column of the Basic Payroll grid:
 * automatic rows head the computed cells, typed rows are typed per employee
 * in their own column, Other Deductions is the one free-form cell, and
 * nothing is hidden behind a popup.
 */
function deductionPageActor(bool $allData = true): void
{
    $role = new Role;
    $role->forceFill(['name' => 'Deduction page officer '.Str::random(6), 'all_data' => $allData, 'is_system' => false])->save();
    $grant = new RoleModuleGrant;
    $grant->forceFill(['role_id' => $role->getKey(), 'module' => 'PAYROLL'])->save();
    test()->actingAs(User::factory()->create(['role_id' => $role->getKey()]));
    app()->forgetInstance(Access::class);
}

/** An all-data RECORDS actor, for the catalog writes the payroll officer cannot make. */
function pdcActorForPage(): Access
{
    $role = new Role;
    $role->forceFill(['name' => 'Deduction page records '.Str::random(6), 'all_data' => true, 'is_system' => false])->save();
    $grant = new RoleModuleGrant;
    $grant->forceFill(['role_id' => $role->getKey(), 'module' => 'RECORDS'])->save();
    test()->actingAs(User::factory()->create(['role_id' => $role->getKey()]));
    app()->forgetInstance(Access::class);

    return app(Access::class);
}

/** @return array{0: EmployeeProfile, 1: PayrollRun, 2: PayrollDeduction} */
function deductionPageRun(): array
{
    $employee = new EmployeeProfile;
    $employee->forceFill([
        'employee_code' => 'DED-PAGE-1', 'first_name' => 'Deduction', 'last_name' => 'Example',
        'employment_status' => 'Regular', 'date_hired' => '2024-01-01',
        'monthly_rate' => 30000, 'is_active' => true,
    ])->save();

    foreach (['03', '04', '05', '06', '07', '10', '11', '12', '13', '14'] as $day) {
        foreach ([['TIME_IN', '00:00:00'], ['TIME_OUT', '09:00:00']] as [$type, $at]) {
            AttendanceLog::query()->create([
                'employee_id' => $employee->id, 'log_type' => $type, 'source' => 'MANUAL',
                'is_geofenced' => true, 'is_anomaly' => false, 'logged_at' => "2026-08-{$day} {$at}",
            ]);
        }
    }

    $run = new PayrollRun;
    $run->forceFill([
        'code' => 'PR-DED-PAGE-1', 'name' => 'Deduction page draft',
        'period_start' => '2026-08-01', 'period_end' => '2026-08-15', 'status' => 'DRAFT',
    ])->save();
    PayrollRunMember::create([
        'run_id' => $run->id, 'employee_id' => $employee->id, 'position' => 0,
        'period_start' => '2026-08-01', 'period_end' => '2026-08-15',
    ]);

    $type = new PayrollDeduction;
    $type->forceFill(['code' => '20', 'name' => 'Cash Advance'])->save();

    return [$employee, $run, $type];
}

it('types a named deduction in its own column, saves it and posts it', function () {
    $this->travelTo(new DateTimeImmutable('2026-09-05T12:00:00+08:00'));
    [$employee, $run, $type] = deductionPageRun();
    deductionPageActor();

    $page = Livewire::test(RunWorkspace::class, ['id' => $run->id]);
    $html = $page->html();
    // One column per list row, in code order (the Late / Undertime row heads
    // none), then the free-form line, the two DTR-priced lines and the footing.
    expect($html)->toContain('data-deduction-column="SSS"')
        ->not->toContain('data-deduction-column="LATE_UNDERTIME"')
        ->not->toContain('>Late / Undertime</th>')
        ->toContain('data-deduction-column="row:20"')
        ->toContain('>Cash Advance</th>')
        ->toContain('colspan="9" class="text-center">Deductions</th>')
        ->toContain('data-deduction-cell="row:20"')
        ->toContain('>Other Deductions</th>')
        ->toContain('>Undertimes</th>')
        ->toContain('>Late</th>')
        ->not->toContain('Edit deductions')
        ->not->toContain('data-custom-deductions')
        ->not->toContain('>WHT</th>');
    $other = strpos($html, '>Other Deductions</th>');
    $undertimes = strpos($html, '>Undertimes</th>');
    $late = strpos($html, '>Late</th>');
    expect($other !== false && $undertimes !== false && $late !== false && $other < $undertimes && $undertimes < $late)->toBeTrue();
    $tax = strpos($html, 'data-deduction-column="WITHHOLDING_TAX"');
    $loan = strpos($html, 'data-deduction-column="row:20"');
    expect($tax !== false && $loan !== false && $tax < $loan)->toBeTrue();

    $page->call('updateCustomDeduction', $employee->id, $type->id, '1,250.00');
    expect($page->get('draftCustomDeductions'))->toBe([$employee->id => [$type->id => 1250.0]])
        ->and($page->html())->toContain('₱1,250.00');

    $this->travel(1)->seconds();
    $page->call('save');
    expect(Livewire::test(RunWorkspace::class, ['id' => $run->id])->get('draftCustomDeductions'))->toBe([$employee->id => [$type->id => 1250.0]]);

    Livewire::test(RunWorkspace::class, ['id' => $run->id])->call('finalize');
    expect($run->refresh()->status)->toBe('FINALIZED');
    $posted = Livewire::test(RunWorkspace::class, ['id' => $run->id]);
    expect($posted->html())->toContain('>Cash Advance</th>')
        ->toContain('data-deduction-cell="row:20"')
        ->toContain('₱1,250.00');
    Livewire::test(IndividualPayslip::class, ['id' => $run->id, 'employeeId' => $employee->id])
        ->assertSee('Cash Advance')
        ->assertSee('Other earnings')
        ->assertSee('Other deductions')
        ->assertSee('Undertimes')
        ->assertSee('Late')
        ->assertDontSee('Late / Undertime')
        ->assertSee('1,250.00');
});

it('keeps Other Deductions as one typed figure and exports each typed row as its own CSV column', function () {
    $this->travelTo(new DateTimeImmutable('2026-09-05T12:00:00+08:00'));
    [$employee, $run, $type] = deductionPageRun();
    deductionPageActor();

    $page = Livewire::test(RunWorkspace::class, ['id' => $run->id])
        ->call('updateDeduction', $employee->id, 'other_deductions', '40')
        ->call('updateCustomDeduction', $employee->id, $type->id, '1,250.00');

    expect($page->html())->toContain('>Other Earnings</th>')
        ->toContain('>Other Deductions</th>')
        ->toContain('₱1,250.00')
        ->toContain('₱40.00')
        ->not->toContain('₱1,290.00')
        ->not->toContain('>Additional</th>');

    $export = $page->call('exportCsv')->assertFileDownloaded('cyberonions-payroll-pr-ded-page-1.csv');
    $bytes = (string) base64_decode((string) data_get($export->effects, 'download.content'), true);
    $csv = (string) preg_replace('/^\\x{FEFF}/u', '', $bytes);
    $rows = array_map('str_getcsv', array_slice(explode("\r\n", $csv), 0, 2));
    $headers = $rows[0];
    $amounts = $rows[1];

    expect(count($headers))->toBe(count($amounts))
        ->and(in_array('Other deduction lines', $headers, true))->toBeFalse()
        ->and($headers[array_search('Other deductions', $headers, true) - 1])->toBe('Withholding Tax')
        ->and($amounts[array_search('Other deductions', $headers, true)])->toBe('40')
        ->and($amounts[array_search('Cash Advance', $headers, true)])->toBe('1250');

    // Under the frozen clock an unchanged run row would update 0 rows on
    // MariaDB and the DRAFT guard would refuse the save.
    $this->travel(1)->seconds();
    $page->call('save');
    Livewire::test(RunWorkspace::class, ['id' => $run->id])->call('finalize');
    expect($run->refresh()->status)->toBe('FINALIZED');
    $posted = Livewire::test(RunWorkspace::class, ['id' => $run->id]);
    expect($posted->html())->toContain('₱40.00')
        ->toContain('₱1,250.00')
        ->not->toContain('₱1,290.00');
});

it('switches a computed deduction off on drafts when its row is deleted and back on when a row of that name is added', function () {
    $this->travelTo(new DateTimeImmutable('2026-09-05T12:00:00+08:00'));
    [$employee, $run] = deductionPageRun();
    deductionPageActor();

    $csvValue = static function (Testable $page, string $header): string {
        $export = $page->call('exportCsv')->assertFileDownloaded('cyberonions-payroll-pr-ded-page-1.csv');
        $bytes = (string) base64_decode((string) data_get($export->effects, 'download.content'), true);
        $rows = array_map('str_getcsv', array_slice(explode("\r\n", (string) preg_replace('/^\\x{FEFF}/u', '', $bytes)), 0, 2));

        return (string) $rows[1][array_search($header, $rows[0], true)];
    };

    // With the list as seeded, SSS is computed (the reference's 750 on 30,000 a month).
    $page = Livewire::test(RunWorkspace::class, ['id' => $run->id]);
    expect($page->html())->toContain('data-deduction-column="SSS"')->toContain('>Undertimes</th>')
        ->and($csvValue($page, 'SSS'))->toBe('750');

    // Deleting the SSS row: no column, nothing deducted, the tax re-derived
    // from what is actually deducted — and the Late / Undertime row gone
    // takes the two attendance columns with it.
    PayrollDeduction::query()->whereKey('ded-1')->delete();
    PayrollDeduction::query()->whereKey('ded-5')->delete();
    $page = Livewire::test(RunWorkspace::class, ['id' => $run->id]);
    expect($page->html())->not->toContain('data-deduction-column="SSS"')
        ->toContain('data-deduction-column="PHILHEALTH"')
        ->not->toContain('>Undertimes</th>')
        ->not->toContain('>Late</th>')
        ->and($csvValue($page, 'SSS'))->toBe('0')
        ->and($csvValue($page, 'Late deduction'))->toBe('0');

    // A new row named exactly as the computed deduction is known brings it back.
    $records = pdcActorForPage();
    app(Deductions::class)->save($records, null, 'sss', '');
    expect(PayrollDeduction::query()->where('name', 'sss')->sole()->calculation)->toBe('SSS');
    deductionPageActor();
    $page = Livewire::test(RunWorkspace::class, ['id' => $run->id]);
    expect($page->html())->toContain('data-deduction-column="SSS"')->toContain('>sss</th>')
        ->and($csvValue($page, 'sss'))->toBe('750');
});

it('refuses an automatic, unknown or zero cell and removes an emptied one', function () {
    $this->travelTo(new DateTimeImmutable('2026-09-05T12:00:00+08:00'));
    [$employee, $run, $type] = deductionPageRun();
    deductionPageActor();

    $page = Livewire::test(RunWorkspace::class, ['id' => $run->id])
        ->call('updateCustomDeduction', $employee->id, 'ded-1', '900')
        ->call('updateCustomDeduction', $employee->id, 'unknown-type', '900')
        ->call('updateCustomDeduction', $employee->id, $type->id, '0.001');
    expect($page->get('draftCustomDeductions'))->toBe([]);

    $page->call('updateCustomDeduction', $employee->id, $type->id, '50')
        ->call('updateCustomDeduction', $employee->id, $type->id, '');
    expect($page->get('draftCustomDeductions'))->toBe([]);
});

it('keeps typed deduction cells closed to actors without all-data payroll access', function () {
    $this->travelTo(new DateTimeImmutable('2026-09-05T12:00:00+08:00'));
    [$employee, $run, $type] = deductionPageRun();
    deductionPageActor(allData: false);

    Livewire::test(RunWorkspace::class, ['id' => $run->id])
        ->call('updateCustomDeduction', $employee->id, $type->id, '50')
        ->assertSet('draftCustomDeductions', []);
});

it('freezes a finalized payroll while a draft follows the list', function () {
    $this->travelTo(new DateTimeImmutable('2026-09-05T12:00:00+08:00'));
    [$employee, $run, $type] = deductionPageRun();
    deductionPageActor();

    $page = Livewire::test(RunWorkspace::class, ['id' => $run->id])
        ->call('updateCustomDeduction', $employee->id, $type->id, '1,250.00');
    $this->travel(1)->seconds();
    $page->call('save');
    Livewire::test(RunWorkspace::class, ['id' => $run->id])->call('finalize');
    expect($run->refresh()->status)->toBe('FINALIZED');

    // A second, open draft over the same list.
    $draft = new PayrollRun;
    $draft->forceFill(['code' => 'PR-DED-PAGE-2', 'name' => 'Deduction page draft two',
        'period_start' => '2026-08-16', 'period_end' => '2026-08-31', 'status' => 'DRAFT'])->save();
    PayrollRunMember::create(['run_id' => $draft->id, 'employee_id' => $employee->id, 'position' => 0,
        'period_start' => '2026-08-16', 'period_end' => '2026-08-31']);

    // The list changes after finalization: a statutory row renamed, a typed
    // row renamed, a new typed row, and (the free row) the typed row deleted.
    PayrollDeduction::query()->whereKey('ded-1')->update(['name' => 'SSS Premium']);
    $type->update(['name' => 'Salary Advance']);
    $uniform = new PayrollDeduction;
    $uniform->forceFill(['code' => '21', 'name' => 'Uniform'])->save();

    $finalized = Livewire::test(RunWorkspace::class, ['id' => $run->id])->html();
    expect($finalized)->toContain('>SSS</th>')
        ->not->toContain('>SSS Premium</th>')
        ->toContain('>Cash Advance</th>')
        ->not->toContain('>Salary Advance</th>')
        ->not->toContain('>Uniform</th>')
        ->toContain('₱1,250.00');
    Livewire::test(IndividualPayslip::class, ['id' => $run->id, 'employeeId' => $employee->id])
        ->assertSee('SSS')
        ->assertDontSee('SSS Premium')
        ->assertSee('Cash Advance')
        ->assertDontSee('Salary Advance');

    $open = Livewire::test(RunWorkspace::class, ['id' => $draft->id])->html();
    expect($open)->toContain('>SSS Premium</th>')
        ->toContain('>Salary Advance</th>')
        ->toContain('>Uniform</th>')
        ->not->toContain('>Cash Advance</th>');

    // Deleting the typed row (nothing finalized charges Uniform) leaves the
    // finalized run exactly as posted and drops the column from the draft.
    $uniform->delete();
    expect(Livewire::test(RunWorkspace::class, ['id' => $draft->id])->html())->not->toContain('>Uniform</th>');
    expect(Livewire::test(RunWorkspace::class, ['id' => $run->id])->html())->toContain('>Cash Advance</th>')->toContain('₱1,250.00');
});

it('shows a payroll finalized before the evidence recorded its columns under the labels it was issued with', function () {
    $this->travelTo(new DateTimeImmutable('2026-09-05T12:00:00+08:00'));
    [$employee, $run, $type] = deductionPageRun();
    deductionPageActor();

    $page = Livewire::test(RunWorkspace::class, ['id' => $run->id])
        ->call('updateCustomDeduction', $employee->id, $type->id, '1,250.00');
    $this->travel(1)->seconds();
    $page->call('save');
    Livewire::test(RunWorkspace::class, ['id' => $run->id])->call('finalize');

    // An older run's evidence has no `deduction_columns` key.
    $snapshot = PayrollFinalizationSnapshot::query()->where('run_id', $run->id)->sole();
    $payload = json_decode($snapshot->payload, true, 512, JSON_THROW_ON_ERROR);
    unset($payload['deduction_columns']);
    PayrollFinalizationSnapshot::query()->whereKey($snapshot->id)->update(['payload' => json_encode($payload, JSON_THROW_ON_ERROR)]);
    PayrollDeduction::query()->whereKey('ded-4')->update(['name' => 'BIR Tax']);

    $html = Livewire::test(RunWorkspace::class, ['id' => $run->id])->html();
    expect($html)->toContain('>Withholding Tax</th>')
        ->not->toContain('>BIR Tax</th>')
        ->toContain('>Cash Advance</th>')
        ->toContain('₱1,250.00');
});
