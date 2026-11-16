<?php

declare(strict_types=1);

use App\Modules\WorkManagement\Application\TaskTimeline;

it('bounds the calendar window and falls back from invalid public range input', function () {
    $window = TaskTimeline::window('not-a-date', '999999', '2026-11-01');
    expect($window->days)->toBe(28)->and(count($window->calendar()))->toBe(28)
        ->and($window->from)->toBe('2026-10-26')->and($window->through)->toBe('2026-11-22')
        ->and($window->shifted(1)->from)->toBe('2026-11-23');
    $last = TaskTimeline::window('9999-12-31', '84', '2026-11-01');
    expect($last->through)->toBe('9999-12-31')->and($last->shifted(1)->from)->toBe($last->from);
    $first = TaskTimeline::window('0001-01-01', '14', '2026-11-01');
    expect($first->shifted(-1)->from)->toBe('0001-01-01');
});

it('clips real date spans and keeps one-sided dates as explicit markers', function () {
    $window = TaskTimeline::window('2028-02-27', '14', '2028-02-29');
    expect($window->position('2028-02-28', '2028-03-01'))->toMatchArray(['kind' => 'range', 'offset' => 1, 'span' => 3, 'before' => false, 'after' => false]);
    expect($window->position(null, '2028-02-29'))->toMatchArray(['kind' => 'due', 'offset' => 2, 'span' => 1]);
    expect($window->position('2028-02-29', null))->toMatchArray(['kind' => 'start', 'offset' => 2, 'span' => 1]);
    expect($window->position('2028-01-01', '2028-03-30'))->toMatchArray(['kind' => 'range', 'offset' => 0, 'span' => 14, 'before' => true, 'after' => true]);
    expect($window->position(null, null)['kind'])->toBe('none');
    expect($window->position('2028-02-30', null)['kind'])->toBe('invalid');
    expect($window->position('2028-03-02', '2028-03-01')['kind'])->toBe('invalid');
    expect($window->position('2027-01-01', '2027-12-31')['kind'])->toBe('before');
    expect($window->position('2029-01-01', null)['kind'])->toBe('after');
    expect($window->todayOffset())->toBe(2);
});

it('zooms the same calendar around an anchor and bounds the longest window', function () {
    $window = TaskTimeline::window('2026-11-02', '28', '2026-11-03');
    $out = $window->zoomed(-1, '2026-11-18');
    expect($out->days)->toBe(84)->and($out->from <= '2026-11-18' && $out->through >= '2026-11-18')->toBeTrue()
        ->and($out->zoomed(1)->days)->toBe(28);
    $year = TaskTimeline::window('9999-12-31', '364', '2026-11-03');
    expect(count($year->calendar()))->toBe(364)->and($year->through)->toBe('9999-12-31')
        ->and(array_sum(array_column($year->periods(), 'span')))->toBe(364)
        ->and($year->zoomed(-1))->toBe($year)
        ->and($year->zoomed(0)->days)->toBe(28);
});
