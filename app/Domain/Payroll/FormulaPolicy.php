<?php

declare(strict_types=1);

namespace App\Domain\Payroll;

/**
 * A literal port of copems/src/lib/payroll-formula-policy.ts.
 *
 * Protects the fixed calculation contract from duplicate, missing or unsafe
 * parameters. Only a formula's `params` are editable — which calculation a row
 * drives is fixed by its `key`, and its `expression` is display text that is
 * never evaluated — so this is the whole of what a user can change about how
 * payroll computes.
 */
final class FormulaPolicy
{
    /**
     * TS: RULES. Key order is load-bearing: it is what the "must be exactly"
     * message lists, in this order.
     *
     * A `min` of PHP_FLOAT_EPSILON is the reference's Number.EPSILON — the
     * smallest step above 1.0, used here as "any positive number", and rendered
     * in the message as "more than 0" rather than as 2.22e-16.
     *
     * @var array<string, array<string, array{min: int|float, max: int|float}>>
     */
    private const RULES = [
        'OT' => [
            'working_hours' => ['min' => PHP_FLOAT_EPSILON, 'max' => 24],
            'percentage' => ['min' => 0, 'max' => 10],
            'max_ot' => ['min' => 0, 'max' => 24],
        ],
        'SIL' => [
            'max_leave' => ['min' => 0, 'max' => 366],
            'months' => ['min' => PHP_FLOAT_EPSILON, 'max' => 120],
            'days_per_month' => ['min' => PHP_FLOAT_EPSILON, 'max' => 31],
        ],
    ];

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
