<?php

declare(strict_types=1);

use App\Livewire\Payroll\RunWorkspace;
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
