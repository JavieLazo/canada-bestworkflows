<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Application;

use App\Models\PayrollFormula;
use App\Modules\Payroll\Contracts\Views\PayrollFormulaView;

/** Uses stored parameter keys and labels; clients supply values only. */
final class FormulaDrafts
{
    /** @param array<string, array<string, string>> $drafts
     * @return list<array{key: string, label: string, value: float}> */
    public static function params(PayrollFormula|PayrollFormulaView $row, array $drafts): array
    {
        $draft = $drafts[$row->key] ?? [];
        $params = [];

        foreach ($row->params as $param) {
            $key = (string) $param['key'];
            $value = array_key_exists($key, $draft) ? $draft[$key] : $param['value'];

            $params[] = [
                'key' => $key,
                'label' => (string) $param['label'],
                // Invalid input must reach FormulaPolicy's finite guard, never
                // silently become a legitimate zero rate or cap.
                'value' => is_numeric($value) ? (float) $value : NAN,
            ];
        }

        return $params;
    }

    /** @param array<string, array<string, string>> $drafts */
    public static function changed(PayrollFormula|PayrollFormulaView $row, array $drafts): bool
    {
        foreach (self::params($row, $drafts) as $i => $param) {
            $stored = $row->params[$i]['value'] ?? null;

            if (! is_numeric($stored) || (float) $stored !== $param['value']) {
                return true;
            }
        }

        return false;
    }
}
