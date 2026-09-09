<?php

declare(strict_types=1);

use App\Livewire\Employees\EmployeeFields;
use App\Livewire\Payroll\Support\PayrollData;
use App\Livewire\Portal\Support\PortalLabels;
use App\Livewire\Roster\Hris;
use App\Models\EmployeeProfile;
use App\Support\EmployeeName;

/**
 * The suffix was addable on Employee Records and invisible everywhere else,
 * because six places each composed a name from their own copy of the same three
 * lines and only one of them learned about the new column. These pin the one
 * formatter they all now share.
 */
function nameRow(array $overrides = []): stdClass
{
    $row = new stdClass;

    foreach (array_merge([
        'first_name' => 'Juan',
        'middle_name' => 'Santos',
        'last_name' => 'Dela Cruz',
        'suffix' => 'Jr.',
    ], $overrides) as $key => $value) {
        $row->{$key} = $value;
    }

    return $row;
}

it('carries the suffix into the everyday spelling', function () {
    expect(EmployeeName::display(nameRow()))->toBe('Juan Dela Cruz Jr.');
});

it('keeps the middle name out of the everyday spelling', function () {
    // A middle name in a table column costs the width the surname needs.
    expect(EmployeeName::display(nameRow()))->not->toContain('Santos');
});

it('spells the whole legal name for the 201 record', function () {
    expect(EmployeeName::full(nameRow()))->toBe('Juan Santos Dela Cruz Jr.');
});

it('reads the same from a model, a stdClass and an array row', function () {
    $expected = 'Leo Cruz III';
    $parts = ['first_name' => 'Leo', 'last_name' => 'Cruz', 'suffix' => 'III'];

    expect(EmployeeName::display((object) $parts))->toBe($expected)
        ->and(EmployeeName::display($parts))->toBe($expected);
});

it('leaves no trailing space when there is no suffix', function () {
    expect(EmployeeName::display(nameRow(['suffix' => null])))->toBe('Juan Dela Cruz')
        ->and(EmployeeName::display(nameRow(['suffix' => ''])))->toBe('Juan Dela Cruz')
        ->and(EmployeeName::full(nameRow(['suffix' => null, 'middle_name' => null])))->toBe('Juan Dela Cruz');
});

it('keeps the suffix out of initials — an avatar reads JD, never JDJ', function () {
    expect(EmployeeName::initials(nameRow()))->toBe('JD');
});

it('falls back rather than rendering a blank name', function () {
    expect(EmployeeName::display(null))->toBe('—')
        ->and(EmployeeName::display(nameRow(['first_name' => '', 'last_name' => '', 'suffix' => ''])))->toBe('—');
});

it('honours the callers that say something other than an em dash', function () {
    // The reminder sweep says "an employee"; "— filed a leave" would be worse
    // than useless in a notification body.
    expect(EmployeeName::display(null, 'an employee'))->toBe('an employee')
        ->and(EmployeeName::display(nameRow(), 'an employee'))->toBe('Juan Dela Cruz Jr.');
});

it('trims stray whitespace stored in the columns', function () {
    expect(EmployeeName::display(nameRow(['first_name' => ' Juan ', 'suffix' => ' Jr. '])))
        ->toBe('Juan Dela Cruz Jr.');
});

/*
|--------------------------------------------------------------------------
| Every module helper now spells it the same way
|--------------------------------------------------------------------------
|
| Five classes had their own employeeName(). If any one of them stops
| delegating, this catches it — that divergence is the bug being fixed.
*/

it('spells a name identically in every module', function () {
    $employee = nameRow();

    $spellings = [
        'Employees' => EmployeeFields::employeeName(
            tap(new EmployeeProfile, fn ($m) => $m->forceFill((array) $employee))
        ),
        'Roster' => Hris::employeeName(
            tap(new EmployeeProfile, fn ($m) => $m->forceFill((array) $employee))
        ),
        'Payroll' => PayrollData::employeeName($employee),
        'Portal' => PortalLabels::employeeName($employee),
        'eDTR' => App\Livewire\Edtr\Support\Hris::employeeName($employee),
    ];

    expect(array_unique(array_values($spellings)))->toHaveCount(1)
        ->and($spellings['Payroll'])->toBe('Juan Dela Cruz Jr.');
});
