<?php

declare(strict_types=1);

namespace App\Livewire\Payroll\Support;

use App\Modules\Payroll\Application\Queries\PayslipRecords;
use App\Modules\Payroll\Contracts\Views\PayslipView;
use App\Support\Access;

/**
 * Builds the sheet a payslip prints on: every regular cut-off the employee has
 * posted in that payslip's month, or a 13th month payslip alone. Payroll and
 * My Portal open one payslip and both read its month here, through the same
 * scoped payslip queries, so a sheet never shows what its reader could not open.
 */
final class PayslipSheet
{
    /** @return array<string, mixed> */
    public static function issued(Access $actor, PayslipView $payslip, ?object $employee, bool $yearly, string $office, string $payroll = '', bool $own = false): array
    {
        $records = app(PayslipRecords::class);
        $slips = $yearly ? collect([$payslip]) : $records->month($actor, $payslip);
        if ($slips->isEmpty()) {
            $slips = collect([$payslip]);
        }

        $ids = array_values($slips->map(static fn (PayslipView $slip): string => $slip->id)->all());
        $incentives = $records->incentives($actor, $ids);
        $deductions = $records->customDeductions($actor, $ids);
        $settlements = $records->settlements($actor, $ids);

        $documents = [];
        foreach ($slips as $slip) {
            $documents[] = PayslipDocument::issued(
                $slip, $employee, $yearly, $incentives[$slip->id] ?? [], $deductions[$slip->id] ?? [],
                // Per slip: each cut-off lists the deductions as its own run recorded them.
                $records->deductionColumns($actor, [$slip->id]),
                $slip->id === $payslip->id ? $payroll : '', $own, $settlements[$slip->id] ?? [],
            );
        }

        return PayslipDocument::sheet($documents, $office);
    }
}
