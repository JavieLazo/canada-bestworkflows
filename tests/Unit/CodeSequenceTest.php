<?php

declare(strict_types=1);

use App\Livewire\Employees\EmployeeFields;
use App\Livewire\Payroll\Support\PayrollData;
use App\Livewire\Portal\Support\PortalLabels;
use App\Livewire\Projects\ProjectsData;
use App\Modules\WorkManagement\Application\WorkRules;
use App\Support\CodeSequence;

/**
 * CodeSequence is the ONE id mint, and every module mint forwards to it. The
 * Records pages' private mints query and forward too, but need a database, so
 * RecordsModuleTest covers those.
 *
 * Ids are bare numbers on ELEVEN of the thirteen coded columns. `next()`
 * takes a prefix argument anyway: it names the LEGACY prefix of the column,
 * so a table the conversion migration has not reached yet still mints above
 * its unconverted rows instead of handing out a number one of them already
 * holds.
 *
 * PROJECT MANAGEMENT KEEPS ITS PREFIX, so `projects.code` and
 * `project_tasks.code` mint through `nextPrefixed()` — `PRJ-008`, padded to
 * three. Both spellings share one scan, which is what keeps "one mint" true;
 * the last two cases below pin that the two disagree only about spelling.
 *
 * Pure PHP; no container, so this runs under tests/Unit.
 */
it('mints one past the highest number in use, ignoring strangers', function () {
    expect(CodeSequence::next('PRJ', ['1', ' 7 ', null, 'PRJ-x', '']))->toBe('8')
        ->and(CodeSequence::next('PRJ', []))->toBe('1')
        ->and(CodeSequence::next('PRJ', ['999']))->toBe('1000')
        ->and(CodeSequence::next('COP', ['42']))->toBe('43');
});

it('counts a legacy prefixed code as its number, so a half-converted table is safe', function () {
    // The conversion runs from a cron on a host with no shell; a row inserted
    // between two of its statements must not collide with an unconverted one.
    expect(CodeSequence::next('COP', ['COP-00042']))->toBe('43')
        ->and(CodeSequence::next('COP', ['COP-00007', '8']))->toBe('9')
        ->and(CodeSequence::next('PRJ', [' prj-7 ', '3']))->toBe('8')
        // Only the column's own prefix counts; another column's is a stranger.
        ->and(CodeSequence::next('PRJ', ['TSK-999', '2']))->toBe('3');
});

it('drops the leading zeros a padded code carried', function () {
    expect(CodeSequence::next('COP', ['COP-00001']))->toBe('2')
        ->and(CodeSequence::next('PR', ['007']))->toBe('8');
});

it('ignores a number past JavaScript\'s safe integer range on every column', function () {
    expect(CodeSequence::next('LR', ['99999999999999999999', '2']))->toBe('3')
        ->and(CodeSequence::next('PRJ', ['PRJ-99999999999999999999']))->toBe('1')
        ->and(CodeSequence::next('LR', ['9007199254740991']))->toBe('9007199254740992');
});

it('is what every module mint forwards to', function () {
    expect(PayrollData::nextCode('PR', ['1', ' 7 ', null]))->toBe('8')
        ->and(PortalLabels::nextCode('LR', ['1', ' 7 ', null, '99999999999999999999']))->toBe('8')
        ->and(EmployeeFields::nextEmployeeCode(['1', ' 7 ', null]))->toBe('8')
        // …and each one also reads its column's legacy prefixed shape.
        ->and(EmployeeFields::nextEmployeeCode(['COP-00007']))->toBe('8');
});

it('keeps the prefix and the padding on the two Project Management columns', function () {
    // The exception to bare-number ids. ProjectsData forwards to
    // WorkRules, which forwards to CodeSequence::nextPrefixed.
    expect(ProjectsData::nextCode('PRJ', ['PRJ-007']))->toBe('PRJ-008')
        ->and(ProjectsData::nextCode('PRJ', []))->toBe('PRJ-001')
        ->and(WorkRules::nextCode('TSK', ['TSK-014']))->toBe('TSK-015')
        // Three digits is the pad; a fourth digit simply widens it rather than
        // truncating, so the sequence does not stop at 999.
        ->and(WorkRules::nextCode('TSK', ['TSK-999']))->toBe('TSK-1000');
});

it('counts BestWorkflows and EMS legacy employee codes without changing their stored spelling', function (array $codes, string $next) {
    $original = $codes;
    expect(EmployeeFields::nextEmployeeCode($codes))->toBe($next)
        ->and($codes)->toBe($original);
})->with([
    'BestWorkflows upgrade' => [['BWF-00001', 'BWF-00008'], '9'],
    'mixed legacy and new codes' => [['BWF-00008', 'COP-00012', '15'], '16'],
    'case and surrounding whitespace' => [[' bwf-00042 ', null, 'COP-00007'], '43'],
    'malformed and unsafe suffixes' => [['BWF-no', 'BWF-001-2', 'BWF-99999999999999999999', '3'], '4'],
]);

it('reads the same shapes whichever spelling mints the next one', function () {
    // One scan under both, so a bare number already in a project column still
    // counts and the next code clears it rather than colliding with it.
    expect(CodeSequence::nextPrefixed('PRJ', ['7']))->toBe('PRJ-008')
        ->and(CodeSequence::nextPrefixed('PRJ', [' prj-7 ', '3']))->toBe('PRJ-008')
        ->and(CodeSequence::nextPrefixed('PRJ', ['PRJ-x', '', null]))->toBe('PRJ-001')
        ->and(CodeSequence::nextPrefixed('PRJ', ['PRJ-99999999999999999999']))->toBe('PRJ-001')
        // Same number, two spellings — the only difference between the mints.
        ->and(CodeSequence::next('PRJ', ['PRJ-007']))->toBe('8');
});
