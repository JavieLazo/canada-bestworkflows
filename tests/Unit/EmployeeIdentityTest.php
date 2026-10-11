<?php

declare(strict_types=1);

use App\Support\EmployeeIdentity;

/**
 * How the two identifiers are spelled, pinned in one place.
 *
 * The minted row id is NOT NULL, so the idioms that print it assume a value
 * is there — `!== ''`, or a bare echo with a ' · ' after it. The hand-typed
 * Employee ID is nullable, and those idioms print an empty cell or a
 * dangling separator for the people who have no company number yet. These pin
 * the answers so no surface has to invent one.
 *
 * The rule the rest of the app leans on is `besideName()`: an identifier shown
 * next to a NAME is the Employee ID or nothing at all. It never falls back to
 * the row id, so nothing here may make it do so.
 */
/** @param array<string, mixed> $overrides */
function identityRow(array $overrides = []): object
{
    return (object) array_merge([
        'employee_code' => 'COP-00042',
        'employee_id_no' => '2024-118',
    ], $overrides);
}

it('shows the hand-typed number on its own, and an em dash when there is none', function () {
    expect(EmployeeIdentity::number(identityRow()))->toBe('2024-118')
        ->and(EmployeeIdentity::number(identityRow(['employee_id_no' => null])))->toBe('—')
        ->and(EmployeeIdentity::number(identityRow(['employee_id_no' => ''])))->toBe('—')
        ->and(EmployeeIdentity::number(null))->toBe('—');
});

it('shows the row id only where a surface asks for it by name', function () {
    expect(EmployeeIdentity::index(identityRow()))->toBe('COP-00042')
        ->and(EmployeeIdentity::index(identityRow(['employee_code' => ''])))->toBe('—');
});

it('puts NOTHING beside a name when the company has not numbered someone', function () {
    // Not the row id, and not an em dash: the name stands alone. A surface that
    // wants the em dash asks for number() instead, and one that wants the row
    // id asks for index() — neither is reachable from here by accident.
    expect(EmployeeIdentity::besideName(identityRow()))->toBe('2024-118')
        ->and(EmployeeIdentity::besideName(identityRow(['employee_id_no' => null])))->toBe('')
        ->and(EmployeeIdentity::besideName(identityRow(['employee_id_no' => '   '])))->toBe('')
        ->and(EmployeeIdentity::besideName(null))->toBe('');
});

it('joins a summary line out of only the parts that are there', function () {
    expect(EmployeeIdentity::meta('2024-118', 'Driver', 'inactive'))->toBe('2024-118 · Driver · inactive')
        // The missing Employee ID takes its separator with it, so the line
        // never opens with " · Driver".
        ->and(EmployeeIdentity::meta('', 'Driver'))->toBe('Driver')
        ->and(EmployeeIdentity::meta(null, 'Driver', null))->toBe('Driver')
        ->and(EmployeeIdentity::meta('2024-118', null))->toBe('2024-118')
        ->and(EmployeeIdentity::meta('', null, '  '))->toBe('')
        ->and(EmployeeIdentity::meta())->toBe('');
});

it('spells a select option as the number and the name, or the name alone', function () {
    expect(EmployeeIdentity::option(identityRow(), 'Juan Dela Cruz'))->toBe('2024-118 — Juan Dela Cruz')
        ->and(EmployeeIdentity::option(identityRow(['employee_id_no' => null]), 'Juan Dela Cruz'))
        ->toBe('Juan Dela Cruz')
        ->and(EmployeeIdentity::option(null, 'Juan Dela Cruz'))->toBe('Juan Dela Cruz');
});

it('reads a model, a projection, a raw select row or an array alike', function () {
    expect(EmployeeIdentity::besideName(['employee_id_no' => 'X-9']))->toBe('X-9')
        ->and(EmployeeIdentity::index(['employee_code' => 'COP-1']))->toBe('COP-1')
        // A row that selected neither column must not fatal on the missing key.
        ->and(EmployeeIdentity::number([]))->toBe('—')
        ->and(EmployeeIdentity::besideName([]))->toBe('')
        ->and(EmployeeIdentity::index((object) []))->toBe('—');
});

it('trims what it is given, so a padded value never renders padded', function () {
    expect(EmployeeIdentity::number(identityRow(['employee_id_no' => '  2024-118  '])))->toBe('2024-118')
        ->and(EmployeeIdentity::index(identityRow(['employee_code' => ' COP-1 '])))->toBe('COP-1')
        ->and(EmployeeIdentity::meta(' 2024-118 ', ' Driver '))->toBe('2024-118 · Driver');
});
