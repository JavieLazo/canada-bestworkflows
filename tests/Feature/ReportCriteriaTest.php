<?php

declare(strict_types=1);

use App\Platform\Reports\ReportCriteria;
use Illuminate\Validation\ValidationException;

/** Employee selections remain bounded, unambiguous and identical through preview and download filters. */
it('preserves every selected employee through the report query string', function () {
    $criteria = ReportCriteria::from([
        'type' => 'payroll-register', 'from' => '2026-09-01', 'to' => '2026-09-30',
        'employeeIds' => ['employee-one', 'employee-two'], 'deductionCodes' => ['loan'],
    ]);
    $query = $criteria->query();

    expect($query['employees'])->toBe('employee-one,employee-two')
        ->and(ReportCriteria::from($query)->employeeIds)->toBe(['employee-one', 'employee-two'])
        ->and(ReportCriteria::from($query)->deductionCodes)->toBe(['loan']);
});

it('accepts a bounded list or legacy employee link without changing the selected identities', function (array $filter, array $expected) {
    $criteria = ReportCriteria::from(array_merge(['type' => 'payroll-register', 'from' => '2026-09-01', 'to' => '2026-09-30'], $filter));

    expect($criteria->employeeIds)->toBe($expected);
})->with([
    'empty draft' => [['employeeIds' => []], []],
    'empty URL' => [['employees' => ''], []],
    'old draft' => [['employeeId' => 'old-one'], ['old-one']],
    'old URL' => [['employee' => 'old-one'], ['old-one']],
    'empty old URL' => [['employee' => ''], []],
    'new draft precedes old link' => [['employeeIds' => [], 'employee' => 'old-one'], []],
    'new URL precedes old link' => [['employees' => 'new-one,new-two', 'employee' => 'old-one'], ['new-one', 'new-two']],
    'maximum employees' => [['employeeIds' => array_map(static fn (int $id): string => 'employee-'.$id, range(1, ReportCriteria::MAX_EMPLOYEES))], array_map(static fn (int $id): string => 'employee-'.$id, range(1, ReportCriteria::MAX_EMPLOYEES))],
    'maximum ID length' => [['employeeIds' => [str_repeat('a', 26)]], [str_repeat('a', 26)]],
]);

it('rejects malformed employee filters instead of falling back to every employee', function (array $filter) {
    expect(fn () => ReportCriteria::from(array_merge(['type' => 'payroll-register', 'from' => '2026-09-01', 'to' => '2026-09-30'], $filter)))
        ->toThrow(ValidationException::class);
})->with([
    'draft string' => [['employeeIds' => 'one,two']],
    'draft null' => [['employeeIds' => null]],
    'draft object shape' => [['employeeIds' => ['selected' => 'one']]],
    'draft sparse list' => [['employeeIds' => [1 => 'one']]],
    'nested employee' => [['employeeIds' => [['one']]]],
    'numeric employee' => [['employeeIds' => [123]]],
    'empty employee' => [['employeeIds' => ['']]],
    'duplicate employees' => [['employeeIds' => ['one', 'one']]],
    'long employee' => [['employeeIds' => [str_repeat('a', 27)]]],
    'comma in draft employee' => [['employeeIds' => ['one,two']]],
    'whitespace employee' => [['employeeIds' => ['one two']]],
    'URL array' => [['employees' => ['one', 'two']]],
    'URL null' => [['employees' => null]],
    'URL number' => [['employees' => 123]],
    'URL empty entry' => [['employees' => 'one,,two']],
    'URL duplicate' => [['employees' => 'one,one']],
    'legacy array' => [['employee' => ['one']]],
    'malformed draft with legacy fallback' => [['employeeIds' => null, 'employee' => 'one']],
    'too many employees' => [['employeeIds' => array_map(static fn (int $id): string => 'employee-'.$id, range(1, ReportCriteria::MAX_EMPLOYEES + 1))]],
]);

it('keeps builder filters columns and numeric ranking order in download criteria', function () {
    $criteria = ReportCriteria::from([
        'type' => 'employee-list', 'filters' => ['search' => 'Engineer', 'active' => 'ACTIVE', 'ageMin' => '0'],
        'columns' => ['employee', 'department', 'age'], 'sort' => 'age', 'direction' => 'desc', 'view' => 'summary',
    ]);
    $copy = ReportCriteria::from($criteria->query());
    expect($copy->filters)->toBe($criteria->filters)
        ->and($copy->columns)->toBe($criteria->columns)
        ->and($copy->sort)->toBe('age')->and($copy->direction)->toBe('desc')->and($copy->view)->toBe('summary');
});

it('omits empty optional query values while retaining zero filter bounds', function () {
    $query = ReportCriteria::from(['type' => 'employee-list', 'filters' => ['search' => '   ', 'ageMin' => '0']])->query();
    expect($query)->not->toHaveKeys(['employees', 'deductions', 'columns', 'sort', 'f_search'])
        ->and($query['f_ageMin'])->toBe('0');
});

it('preserves workforce analysis activity dates and dimensions through a download link', function () {
    $criteria = ReportCriteria::from([
        'type' => 'employee-list', 'view' => 'analysis', 'from' => '2026-01-01', 'to' => '2026-09-30',
        'filters' => ['analysis' => 'development', 'groupBy' => 'department', 'crossTab' => 'sex', 'active' => 'ACTIVE'],
    ]);
    $copy = ReportCriteria::from($criteria->query());

    expect($copy->from)->toBe('2026-01-01')->and($copy->to)->toBe('2026-09-30')
        ->and($copy->view)->toBe('analysis')->and($copy->filters)->toBe($criteria->filters);
});

it('rejects malformed report builder options rather than silently broadening coverage', function (array $input) {
    expect(fn () => ReportCriteria::from(['type' => 'employee-list', ...$input]))->toThrow(ValidationException::class);
})->with([
    'null filters' => [['filters' => null]],
    'nested filters' => [['filters' => ['search' => ['name']]]],
    'numeric filter key' => [['filters' => ['name']]],
    'malformed filter key' => [['filters' => ['search.name' => 'name']]],
    'null columns' => [['columns' => null]],
    'nested columns' => [['columns' => [['employee']]]],
    'duplicate columns' => [['columns' => ['employee', 'employee']]],
    'column injection' => [['columns' => ['employee;drop']]],
    'sort direction' => [['direction' => 'sideways']],
    'null sort' => [['sort' => null]],
    'null direction' => [['direction' => null]],
    'null view' => [['view' => null]],
    'unknown view' => [['view' => 'unbounded']],
]);
