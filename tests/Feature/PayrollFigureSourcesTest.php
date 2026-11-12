<?php

declare(strict_types=1);

use App\Livewire\Payroll\RunWorkspace;
use App\Models\PayrollDeduction;
use App\Modules\Payroll\Application\Commands\SaveRecurringLine;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Support\PayrollEvidenceFixture;

/**
 * A draft payroll's grid marks every figure that is not simply the company
 * settings' — the employee's own pay settings (a line switched off or a
 * fixed share), a recurring line, a figure typed on this payroll — and
 * "Why this amount?" lists payroll's checks for a marked figure in order,
 * the one it used first. Read from the draft's own calculation; nothing is
 * written and the payroll's evidence is unchanged.
 */

/* |---| The marks and their explanation |---| */

it('marks the figures that are not the company settings, and none when every figure is', function () {
    ['actor' => $actor, 'employee' => $employee, 'run' => $run] = PayrollEvidenceFixture::make();
    PayrollEvidenceFixture::sourceFacts($employee);

    Livewire::test(RunWorkspace::class, ['id' => $run->id])
        ->assertDontSeeHtml('data-figure-key')
        ->assertDontSeeHtml('data-figure-mark');

    $employee->forceFill(['allow_sss' => false, 'fixed_pagibig_ee' => 200, 'fixed_pagibig_er' => 200])->save();
    $loan = PayrollDeduction::create(['code' => '7', 'name' => 'SSS Loan']);
    app(SaveRecurringLine::class)->handle($actor, null, $employee->id, $loan->id, ['kind' => 'DEDUCTION', 'amount' => 500.0, 'total' => 750.0, 'frequency' => 'EVERY', 'starts_on' => '2026-09-01', 'ends_on' => null], 'Loan 42');

    Livewire::test(RunWorkspace::class, ['id' => $run->id])
        ->assertSeeHtml('data-figure-key')
        ->assertSeeHtml('data-figure-mark="sss"')
        ->assertSee('Own · off')
        ->assertSeeHtml('data-figure-mark="pagibig"')
        ->assertSeeHtml('data-figure-mark="row:'.$loan->id.'"')
        ->assertDontSeeHtml('data-figure-mark="philhealth"')
        ->call('updateDeduction', $employee->id, 'philhealth', '50')
        ->assertSeeHtml('data-figure-mark="philhealth"');
});

it('explains a marked figure: the checks in order and the one payroll used', function () {
    ['actor' => $actor, 'employee' => $employee, 'run' => $run] = PayrollEvidenceFixture::make();
    PayrollEvidenceFixture::sourceFacts($employee);
    $employee->forceFill(['fixed_pagibig_ee' => 200, 'fixed_pagibig_er' => 200])->save();
    $loan = PayrollDeduction::create(['code' => '7', 'name' => 'SSS Loan']);
    app(SaveRecurringLine::class)->handle($actor, null, $employee->id, $loan->id, ['kind' => 'DEDUCTION', 'amount' => 500.0, 'total' => 750.0, 'frequency' => 'EVERY', 'starts_on' => '2026-09-01', 'ends_on' => null], 'Loan 42');

    $page = Livewire::test(RunWorkspace::class, ['id' => $run->id])
        ->call('openWhy', $employee->id, 'pagibig')
        ->assertSet('whyOpen', true)
        ->assertSee('Pag-IBIG · Synthetic Evidence')
        ->assertSee('₱100.00')
        ->assertSee('1. Typed on this payroll')
        ->assertSee('Nothing typed in this cell.')
        ->assertSee('2. Their own pay settings')
        ->assertSee('₱200.00 a month, employee share, ₱200.00 employer share, half on each cut-off.')
        ->assertSee('3. Company: the Pag-IBIG table')
        ->assertSee('The version from 01 Jan 2025.')
        ->assertSee('Change it in their pay settings');
    $html = $page->html();
    $dialog = (string) strstr($html, 'data-why-dialog');
    expect(substr_count($dialog, '>Used<'))->toBe(1)
        ->and(strpos($dialog, '>Used<'))->toBeGreaterThan((int) strpos($dialog, 'Their own pay settings'))
        ->and(strpos($dialog, '>Used<'))->toBeLessThan((int) strpos($dialog, 'Company: the Pag-IBIG table'));

    // A typed figure comes first, ahead of their own settings.
    $page->call('updateDeduction', $employee->id, 'pagibig', '150')
        ->call('openWhy', $employee->id, 'pagibig')
        ->assertSee('₱150.00 typed in the cell.');

    $page->call('openWhy', $employee->id, 'row:'.$loan->id)
        ->assertSee('SSS Loan · Synthetic Evidence')
        ->assertSee('2. Their recurring deduction or allowance')
        ->assertSee('₱500.00 a payroll, ₱750.00 balance before this one.');
});

it('opens no explanation for someone off the payroll or a cell it does not know', function () {
    ['employee' => $employee, 'run' => $run] = PayrollEvidenceFixture::make();

    Livewire::test(RunWorkspace::class, ['id' => $run->id])
        ->call('openWhy', (string) Str::ulid(), 'sss')
        ->assertSet('whyOpen', false)
        ->call('openWhy', $employee->id, 'net_pay')
        ->assertSet('whyOpen', false)
        ->call('openWhy', $employee->id, "row:'); alert(1")
        ->assertSet('whyOpen', false)
        ->assertSet('whyCell', null);
});
