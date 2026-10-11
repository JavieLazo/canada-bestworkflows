<?php

declare(strict_types=1);

namespace App\Domain\Payroll;

/**
 * Validates the editable inputs consumed by PayrollRates.
 *
 * Protects the fixed calculation contract from duplicate, missing or unsafe
 * parameters. Only a formula's `params` are editable — which calculation a row
 * drives is fixed by its `key`, and its `expression` is display text that is
 * never evaluated.
 */
final class FormulaPolicy
{
    /**
     * DELIBERATE DIVERGENCE from payroll-formula-policy.ts: validate the active
     * engine inputs, including NSD and rest-day pay. Rates remain multipliers;
     * zero legitimately disables a rate or cap.
     *
     * @var array<string, array<string, array{min: int|float, max: int|float}>>
     */
    private const RULES = [
        'OT' => [
            'percentage' => ['min' => 0, 'max' => 10],
            'max_ot' => ['min' => 0, 'max' => 24],
        ],
        'NSD' => [
            'percentage' => ['min' => 0, 'max' => 10],
            'max_nsd' => ['min' => 0, 'max' => 24],
        ],
        'REST_DAY' => [
            'percentage' => ['min' => 0, 'max' => 10],
            'max_hours' => ['min' => 0, 'max' => 24],
        ],
    ];

    /** @return list<string> */
    public static function keys(): array
    {
        return array_keys(self::RULES);
    }

    /**
     * TS: formulaParamsError(). Why this parameter set may not be saved, or null.
     *
     * Each param is `['key' => string, 'value' => int|float]`; any other keys the
     * form carries (the label) are ignored here, exactly as they are in the
     * reference.
     *
     * PORTED QUIRK: the finite test is `Number.isFinite`, which does NOT coerce —
     * a numeric STRING is rejected there, so it is rejected here too. Upstream
     * validation parses the value to a number first, so this only ever fires on a
     * value the request layer let through unparsed.
     *
     * @param  list<array{key: string, value: mixed}>  $params
     */
    public static function paramsError(string $formulaKey, array $params): ?string
    {
        $rules = self::RULES[$formulaKey] ?? null;
        if ($rules === null) {
            return 'That formula is not supported by the payroll engine.';
        }

        $keys = array_map(static fn (array $param): string => (string) $param['key'], $params);
        if (count(array_unique($keys, SORT_STRING)) !== count($keys)) {
            return 'Formula parameter keys must be unique.';
        }

        $expected = array_keys($rules);
        // Preserve a stored compatibility parameter without requiring it on
        // active OT rows. Payroll derives working hours from the shift roster.
        if ($formulaKey === 'OT' && in_array('working_hours', $keys, true)) {
            $rules['working_hours'] = ['min' => PHP_FLOAT_EPSILON, 'max' => 24];
            $expected[] = 'working_hours';
        }
        $missing = false;
        foreach ($expected as $key) {
            if (! in_array($key, $keys, true)) {
                $missing = true;
                break;
            }
        }
        if (count($keys) !== count($expected) || $missing) {
            return 'Formula parameters must be exactly: '.implode(', ', $expected).'.';
        }

        foreach ($params as $param) {
            $rule = $rules[(string) $param['key']];
            $value = $param['value'];
            $isFinite = (is_int($value) || is_float($value)) && is_finite((float) $value);

            if (! $isFinite || $value < $rule['min'] || $value > $rule['max']) {
                $min = $rule['min'] === PHP_FLOAT_EPSILON ? 'more than 0' : $rule['min'];

                return "{$param['key']} must be between {$min} and {$rule['max']}.";
            }
        }

        return null;
    }
}
