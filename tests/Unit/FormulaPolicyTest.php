<?php

declare(strict_types=1);

use App\Domain\Payroll\FormulaPolicy;

/** Editable formulas accept only the engine's bounded active parameters. */
it('accepts active formula parameters at their inclusive bounds', function (string $key, string $cap) {
    foreach ([[0, 0], [10, 24], [1.25, 7.5]] as [$rate, $hours]) {
        expect(FormulaPolicy::paramsError($key, [
            ['key' => 'percentage', 'value' => $rate],
            ['key' => $cap, 'value' => $hours],
        ]))->toBeNull();
    }
})->with([
    'overtime' => ['OT', 'max_ot'],
    'night differential' => ['NSD', 'max_nsd'],
    'rest day' => ['REST_DAY', 'max_hours'],
]);

it('accepts bounded compatibility working hours without requiring them', function () {
    $params = [['key' => 'percentage', 'value' => 1.25], ['key' => 'max_ot', 'value' => 8]];
    expect(FormulaPolicy::paramsError('OT', $params))->toBeNull()
        ->and(FormulaPolicy::paramsError('OT', [...$params, ['key' => 'working_hours', 'value' => 8]]))->toBeNull()
        ->and(FormulaPolicy::paramsError('OT', [...$params, ['key' => 'working_hours', 'value' => 0]]))->toContain('working_hours');
});

it('rejects parameters outside the fixed active contract', function (array $params, string $error) {
    expect(FormulaPolicy::paramsError('OT', array_values($params)))->toContain($error);
})->with([
    'missing cap' => [[['key' => 'percentage', 'value' => 1.25]], 'must be exactly'],
    'unknown key' => [[['key' => 'percentage', 'value' => 1.25], ['key' => 'max_ot', 'value' => 8], ['key' => 'unexpected', 'value' => 1]], 'must be exactly'],
    'duplicate key' => [[['key' => 'percentage', 'value' => 1.25], ['key' => 'percentage', 'value' => 8]], 'must be unique'],
    'unparsed numeric string' => [[['key' => 'percentage', 'value' => '1.25'], ['key' => 'max_ot', 'value' => 8]], 'percentage'],
    'non-finite rate' => [[['key' => 'percentage', 'value' => INF], ['key' => 'max_ot', 'value' => 8]], 'percentage'],
    'invalid rate' => [[['key' => 'percentage', 'value' => NAN], ['key' => 'max_ot', 'value' => 8]], 'percentage'],
    'negative rate' => [[['key' => 'percentage', 'value' => -0.01], ['key' => 'max_ot', 'value' => 8]], 'percentage'],
    'excessive rate' => [[['key' => 'percentage', 'value' => 10.01], ['key' => 'max_ot', 'value' => 8]], 'percentage'],
    'negative cap' => [[['key' => 'percentage', 'value' => 1.25], ['key' => 'max_ot', 'value' => -0.01]], 'max_ot'],
    'excessive cap' => [[['key' => 'percentage', 'value' => 1.25], ['key' => 'max_ot', 'value' => 24.01]], 'max_ot'],
]);

it('refuses unsupported formula edits', function () {
    expect(FormulaPolicy::paramsError('SIL', []))->toContain('not supported')
        ->and(FormulaPolicy::paramsError('UNKNOWN', []))->toContain('not supported');
});
