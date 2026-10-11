<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\PayrollFormula;

/**
 * The payroll formulas from seed.ts:1599-1649 with the engine defaults:
 * OT 1.25 over max 8h, NSD 0.10 over max 8h, REST_DAY 1.30 over max 8h.
 *
 * `expression` is DISPLAY TEXT ONLY — the calculation shape lives in code,
 * keyed by `key`, and only `params` is adjustable. Keyed on `key` (the natural
 * unique); explicit codes preserve each formula's identity independently
 * of the list's position.
 */
final class PayrollFormulaSeeder extends PortSeeder
{
    public function run(): void
    {
        $formulas = [
            [
                'fml-ot', '1', 'OT', 'Overtime (OT)',
                '<Hourly Rate> * <Percentage> * <Max OT>',
                [
                    ['key' => 'percentage', 'label' => 'Percentage', 'value' => 1.25],
                    ['key' => 'max_ot', 'label' => 'Max OT', 'value' => 8],
                ],
            ],
            [
                'fml-nsd', '3', 'NSD', 'Night Shift Differential (NSD)',
                '<Hourly Rate> * <Percentage> * <Max NSD>',
                [
                    ['key' => 'percentage', 'label' => 'Percentage', 'value' => 0.1],
                    ['key' => 'max_nsd', 'label' => 'Max NSD', 'value' => 8],
                ],
            ],
            [
                'fml-rest_day', '4', 'REST_DAY', 'Rest Day',
                '<Hourly Rate> * <Percentage> * <Max Hours>',
                [
                    ['key' => 'percentage', 'label' => 'Percentage', 'value' => 1.3],
                    ['key' => 'max_hours', 'label' => 'Max Hours', 'value' => 8],
                ],
            ],
        ];

        foreach ($formulas as [$id, $code, $key, $name, $expression, $params]) {
            $this->upsert(PayrollFormula::class, ['key' => $key], [
                'id' => $id,
                'code' => $code,
                'name' => $name,
                'expression' => $expression,
                'params' => $params,
                'created_at' => $this->at(300, 9, 0),
            ]);
        }
    }
}
