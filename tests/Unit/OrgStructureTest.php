<?php

declare(strict_types=1);

use App\Modules\Organization\Domain\OrgStructure;

/**
 * The two fixed levels in HR's order: each department followed by its
 * units, siblings by sort_order then name then id; a department filter
 * selects the department and its units, a unit only itself, an unknown id
 * nothing; a corrupt unit (no parent, or a unit for a parent) is left out of
 * every walk; Move up / Move down swaps with a neighbour and does nothing at
 * an end.
 */

/** @return list<array{id: string, kind: string, parent_id: string|null, name: string, sort_order: int}> */
function orgunitRows(): array
{
    return [
        ['id' => 'eng', 'kind' => 'DEPARTMENT', 'parent_id' => null, 'name' => 'Engineering', 'sort_order' => 1],
        ['id' => 'pres', 'kind' => 'DEPARTMENT', 'parent_id' => null, 'name' => 'Office of the President', 'sort_order' => 0],
        ['id' => 'hr', 'kind' => 'DEPARTMENT', 'parent_id' => null, 'name' => 'HR', 'sort_order' => 1],
        ['id' => 'qa', 'kind' => 'UNIT', 'parent_id' => 'eng', 'name' => 'Quality', 'sort_order' => 1],
        ['id' => 'dev', 'kind' => 'UNIT', 'parent_id' => 'eng', 'name' => 'Software Development', 'sort_order' => 0],
        ['id' => 'people', 'kind' => 'UNIT', 'parent_id' => 'hr', 'name' => 'People Operations', 'sort_order' => 0],
        ['id' => 'orphan', 'kind' => 'UNIT', 'parent_id' => 'gone', 'name' => 'Orphan', 'sort_order' => 0],
        ['id' => 'nested', 'kind' => 'UNIT', 'parent_id' => 'dev', 'name' => 'Nested', 'sort_order' => 0],
    ];
}

it('lists each department followed by its units, in HR order with name as the tie-break', function () {
    $structure = new OrgStructure(orgunitRows());

    // Engineering and HR share sort_order 1, so the name decides.
    expect($structure->departments())->toBe(['pres', 'eng', 'hr'])
        ->and($structure->ordered())->toBe(['pres', 'eng', 'dev', 'qa', 'hr', 'people'])
        ->and($structure->unitsOf('eng'))->toBe(['dev', 'qa'])
        ->and($structure->unitsOf('dev'))->toBe([]);
});

it('leaves a unit with a missing or non-department parent out of every walk', function () {
    $structure = new OrgStructure(orgunitRows());

    expect($structure->isUnit('orphan'))->toBeFalse()
        ->and($structure->isUnit('nested'))->toBeFalse()
        ->and($structure->departmentOf('orphan'))->toBeNull()
        ->and($structure->siblingsOf('nested'))->toBe([])
        ->and($structure->ordered())->not->toContain('orphan')
        ->and($structure->ordered())->not->toContain('nested');
});

it('names the department a row belongs to', function () {
    $structure = new OrgStructure(orgunitRows());

    expect($structure->departmentOf('eng'))->toBe('eng')
        ->and($structure->departmentOf('qa'))->toBe('eng')
        ->and($structure->departmentOf('nowhere'))->toBeNull()
        ->and($structure->name('people'))->toBe('People Operations');
});

it('selects a department with its units, a unit alone, nothing for an unknown id, and no list for ALL or NONE', function () {
    $structure = new OrgStructure(orgunitRows());

    expect($structure->selection('eng'))->toBe(['eng', 'dev', 'qa'])
        ->and($structure->selection('qa'))->toBe(['qa'])
        ->and($structure->selection('nowhere'))->toBe([])
        ->and($structure->selection(OrgStructure::ALL))->toBeNull()
        ->and($structure->selection(OrgStructure::NONE))->toBeNull();
});

it('gives a unit only its own department\'s units as siblings', function () {
    $structure = new OrgStructure(orgunitRows());

    expect($structure->siblingsOf('qa'))->toBe(['dev', 'qa'])
        ->and($structure->siblingsOf('people'))->toBe(['people'])
        ->and($structure->siblingsOf('hr'))->toBe(['pres', 'eng', 'hr']);
});

it('swaps with the neighbour and does nothing at an end or for an unknown id', function () {
    expect(OrgStructure::swap(['a', 'b', 'c'], 'b', 'up'))->toBe(['b', 'a', 'c'])
        ->and(OrgStructure::swap(['a', 'b', 'c'], 'b', 'down'))->toBe(['a', 'c', 'b'])
        ->and(OrgStructure::swap(['a', 'b', 'c'], 'a', 'up'))->toBeNull()
        ->and(OrgStructure::swap(['a', 'b', 'c'], 'c', 'down'))->toBeNull()
        ->and(OrgStructure::swap(['a', 'b', 'c'], 'z', 'up'))->toBeNull()
        ->and(OrgStructure::swap(['a', 'b', 'c'], 'b', 'sideways'))->toBeNull();
});

it('orders the rows of one place by sort_order, then name ignoring case, then id', function () {
    expect(OrgStructure::order([
        ['id' => '3', 'name' => 'beta', 'sort_order' => 0],
        ['id' => '1', 'name' => 'Alpha', 'sort_order' => 0],
        ['id' => '9', 'name' => 'Zed', 'sort_order' => -1],
        ['id' => '2', 'name' => 'alpha', 'sort_order' => 0],
    ]))->toBe(['9', '1', '2', '3']);
});
