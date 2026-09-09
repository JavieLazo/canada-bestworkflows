<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\PayrollFormula;

/**
 * The payroll formulas from seed.ts:1599-1649 with the engine defaults:
 * OT 1.25 over max 8h, NSD 0.10 over max 8h, REST_DAY 1.30 over max 8h,
 * SIL 6 leaves over 12 months on 24 days per month.
 *
 * `expression` is DISPLAY TEXT ONLY — the calculation shape lives in code,
 * keyed by `key`, and only `params` is adjustable. Keyed on `key` (the natural
 * unique); `code` is derived from position, which is why REST_DAY stays
 * appended after NSD rather than slotted in beside OT.
 */
final class PayrollFormulaSeeder extends PortSeeder
{
    public function run(): void
    {
        $formulas = [
            [
                'fml-ot', 'OT', 'Overtime (OT)',
                '<Hourly Rate> * <Percentage> * <Max OT>',
                [
                    ['key' => 'percentage', 'label' => 'Percentage', 'value' => 1.25],
                    ['key' => 'max_ot', 'label' => 'Max OT', 'value' => 8],
                ],
            ],
            [
                'fml-sil', 'SIL', 'Service Incentive Leave (SIL)',
                '<Basic Pay> / <No. of Days per Month> * ( <Max No. of Leave> / <No. of Months> )',
                [
                    ['key' => 'max_leave', 'label' => 'Max No. of Leave', 'value' => 6],
                    ['key' => 'months', 'label' => 'No. of Months', 'value' => 12],
                    ['key' => 'days_per_month', 'label' => 'No. of Days per Month', 'value' => 24],
                ],
            ],
            [
                'fml-nsd', 'NSD', 'Night Shift Differential (NSD)',
                '<Hourly Rate> * <Percentage> * <Max NSD>',
                [
                    ['key' => 'percentage', 'label' => 'Percentage', 'value' => 0.1],
                    ['key' => 'max_nsd', 'label' => 'Max NSD', 'value' => 8],
                ],
            ],
            [
                'fml-rest_day', 'REST_DAY', 'Rest Day',
                '<Hourly Rate> * <Percentage> * <Max Hours>',
                [
                    ['key' => 'percentage', 'label' => 'Percentage', 'value' => 1.3],
                    ['key' => 'max_hours', 'label' => 'Max Hours', 'value' => 8],
                ],
            ],
        ];

        foreach ($formulas as $i => [$id, $key, $name, $expression, $params]) {
            $this->upsert(PayrollFormula::class, ['key' => $key], [
                'id' => $id,
                'code' => sprintf('FML-%03d', $i + 1),
                'name' => $name,
                'expression' => $expression,
                'params' => $params,
                'created_at' => $this->at(300, 9, 0),
            ]);
        }
    }
}
