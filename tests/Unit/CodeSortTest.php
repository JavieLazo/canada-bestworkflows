<?php

declare(strict_types=1);

use App\Support\CodeSort;
use App\Support\TextSort;

/**
 * Ids are bare numbers, and bare numbers do not sort as text.
 *
 * The invariant: every list ordered by its ID column reads 1, 2, 3, …, 9, 10,
 * 11 — never 1, 10, 11, 2. TextSort (strcasecmp) gets this right only for
 * zero-padded codes, which is why ID columns sort through this comparator.
 * `SqlText::orderNumeric` is the SQL twin and has to agree with it;
 * `WorkPaginationTest` compares the two.
 */
/**
 * @param  list<string|null>  $values
 * @return list<string|null>
 */
function codeSorted(array $values): array
{
    usort($values, CodeSort::compare(...));

    return $values;
}

it('orders ids as numbers, which is the whole point', function () {
    expect(codeSorted(['10', '2', '1', '11', '9']))->toBe(['1', '2', '9', '10', '11']);
});

it('would have been wrong under the old text comparator', function () {
    // The regression this file exists to catch: TextSort orders ids as
    // text, and a list that sorted them through it would read 1, 10, 11, 2
    // on screen.
    $values = ['10', '2', '1'];
    usort($values, TextSort::compare(...));

    expect($values)->toBe(['1', '10', '2'])
        ->and(codeSorted(['10', '2', '1']))->toBe(['1', '2', '10']);
});

it('reads a padded id and a bare one as the same number', function () {
    expect(CodeSort::compare('007', '7'))->toBe(0)
        ->and(CodeSort::compare('0', '000'))->toBe(0)
        ->and(codeSorted(['0010', '9']))->toBe(['9', '0010']);
});

it('sorts blanks last on an ascending pass, like every other list here', function () {
    expect(codeSorted(['2', '', '1', null]))->toBe(['1', '2', '', null])
        ->and(CodeSort::compare('', ''))->toBe(0)
        ->and(CodeSort::compare(null, null))->toBe(0);
});

it('puts what it cannot read as a number after every number, in text order', function () {
    // A legacy code the conversion refused to touch, or a hand-entered value.
    expect(codeSorted(['PRJ-EXP', '10', 'N/A', '2']))->toBe(['2', '10', 'N/A', 'PRJ-EXP']);
});

it('compares numbers longer than PHP_INT_MAX exactly, with no cast', function () {
    // (int) would saturate both of these to PHP_INT_MAX and call them equal.
    $huge = '99999999999999999999';
    $huger = '99999999999999999999999';

    expect(CodeSort::compare($huge, $huger))->toBeLessThan(0)
        ->and(CodeSort::compare($huger, $huge))->toBeGreaterThan(0)
        ->and(CodeSort::compare($huge, $huge))->toBe(0);
});
