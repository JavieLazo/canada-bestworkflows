<?php

declare(strict_types=1);

use App\Livewire\Projects\Support\OverviewCharts;
use App\Modules\WorkManagement\Application\OverviewPeriod;

function weekPeriod(): OverviewPeriod
{
    return OverviewPeriod::resolve('7d', '', '', '2026-11-13');
}

it('sums a count, colors its change by whether up is good, and labels both periods', function () {
    $chart = OverviewCharts::trend(weekPeriod(), [1, 2, 3, 0, 0, 0, 4], [2, 2, 2, 2, 0, 0, 0], 'count', true);
    expect([$chart['value'], $chart['text']])->toBe([10, '10'])
        ->and($chart['delta'])->toBe(['arrow' => 'arrow-up', 'text' => '25%', 'tone' => 'good', 'sentence' => 'Up 25% from 8 in the previous 7 days.'])
        ->and($chart['points'][0]['rows'])->toBe([
            ['label' => 'Sat, Nov 7', 'value' => '1', 'key' => 'line'],
            ['label' => 'Sat, Oct 31', 'value' => '2', 'key' => 'dash'],
        ])
        ->and([$chart['points'][6]['y'], $chart['points'][3]['y'], $chart['points'][6]['x']])->toBe([12.0, 92.0, 100.0])
        ->and($chart['area'])->toStartWith('0,100 0,');

    expect(OverviewCharts::trend(weekPeriod(), [1, 2, 3, 0, 0, 0, 4], [2, 2, 2, 2, 0, 0, 0], 'count', null)['delta']['tone'])->toBe('flat')
        ->and(OverviewCharts::trend(weekPeriod(), [101, 0, 0, 0, 0, 0, 0], [100, 0, 0, 0, 0, 0, 0], 'count', true)['delta']['tone'])->toBe('flat')
        ->and(OverviewCharts::trend(weekPeriod(), [3, 0, 0, 0, 0, 0, 0], [0, 0, 0, 0, 0, 0, 0], 'count', true)['delta'])->toBeNull();
});

it('reads open tasks at the end of the period and calls a growing backlog bad news', function () {
    $chart = OverviewCharts::trend(weekPeriod(), [5, 6, 7, 8, 9, 10, 12], [4, 4, 4, 4, 4, 4, 10], 'stock', false);
    expect($chart['value'])->toBe(12)
        ->and($chart['delta'])->toBe(['arrow' => 'arrow-up', 'text' => '20%', 'tone' => 'bad', 'sentence' => 'Up 20% from 10 on Nov 6.'])
        ->and($chart['points'][6]['rows'][0]['label'])->toBe('Nov 13');
});

it('compares a share in points and breaks its line where there is nothing to measure yet', function () {
    $chart = OverviewCharts::trend(weekPeriod(), [null, null, 50, 60, 70, 80, 82], [70, 72, 74, 75, 76, 77, 77], 'rate', true);
    expect([$chart['value'], $chart['text']])->toBe([82, '82%'])
        ->and($chart['delta'])->toBe(['arrow' => 'arrow-up', 'text' => '5 pts', 'tone' => 'good', 'sentence' => 'Up 5 points from 77% in the previous 7 days.'])
        ->and($chart['lines'])->toHaveCount(1)
        ->and($chart['area'])->toBeNull()
        ->and($chart['points'][0]['rows'][0])->toBe(['label' => 'Through Nov 7', 'value' => 'No data', 'key' => 'line']);
});
