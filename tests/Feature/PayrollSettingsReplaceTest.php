<?php

declare(strict_types=1);

use App\Models\PayrollBenefitCeiling;
use App\Models\PayrollFinalizationSnapshot;
use App\Modules\Payroll\Application\Commands\FinalizePayroll;
use App\Modules\Payroll\Application\Commands\ReplaceBenefitCeiling;
use App\Modules\Payroll\Application\PayrollData;
use Tests\Support\PayrollEvidenceFixture;
use Tests\Support\PayrollReviewFixture;

it('replaces the tax-free ceiling at once, in one row, without touching what a finalized payroll captured', function () {
    ['actor' => $actor, 'employee' => $employee, 'run' => $run] = PayrollEvidenceFixture::make();
    PayrollEvidenceFixture::sourceFacts($employee);
    app(FinalizePayroll::class)->handle($actor, $run->id, PayrollReviewFixture::token($actor, $run->id));
    $evidence = PayrollFinalizationSnapshot::query()->sole()->toArray();

    expect(app(ReplaceBenefitCeiling::class)->handle($actor, '120,000'))->toBe('Tax-exempt ceiling set to 120,000.00.');

    $row = PayrollBenefitCeiling::query()->sole();
    $draft = PayrollData::calculateDrafts([$employee->id], '2026-08-16', '2026-08-31', actor: $actor);
    expect($row->ceiling)->toBe(120000.0)
        ->and($row->effective_from->format('Y-m-d'))->toBe('2025-01-01')
        ->and($draft['inputs']->ceiling())->toBe(120000.0)
        ->and(PayrollFinalizationSnapshot::query()->sole()->toArray())->toBe($evidence);
});
