<?php

declare(strict_types=1);

namespace App\Livewire\Projects\Support;

use App\Modules\WorkManagement\Application\OverviewPeriod;

/**
 * What a trend card draws: its number, its change against the previous
 * period, both lines, and the rows each hover card shows. Counts sum over the
 * period; open tasks and the on-time share are read at the end of it.
 */
final class OverviewCharts
{
    /** A change smaller than this, in percent (or points for a share), reads as no change. */
    private const FLAT = 2;

    /**
     * @param  list<int|null>  $current
     * @param  list<int|null>  $previous
     * @param  'count'|'stock'|'rate'  $kind
     * @param  bool|null  $upIsGood  null when neither direction is good news on its own
     * @return array{value: int|null, text: string, delta: array{arrow: string, text: string, tone: string, sentence: string}|null, lines: list<string>, previousLines: list<string>, area: string|null, points: list<array{x: float, y: float|null, py: float|null, rows: list<array{label: string, value: string, key: string}>}>}
     */
    public static function trend(OverviewPeriod $period, array $current, array $previous, string $kind, ?bool $upIsGood): array
    {
        [$value, $before] = $kind === 'count'
            ? [array_sum($current), array_sum($previous)]
            : [$current[array_key_last($current)] ?? null, $previous[array_key_last($previous)] ?? null];
        $peak = $kind === 'rate' ? 100 : max(1, ...array_map('intval', [...$current, ...$previous]));
        $count = count($current);
        $x = static fn (int $index): float => $count === 1 ? 50.0 : round($index * 100 / ($count - 1), 2);
        $y = static fn (?int $amount): ?float => $amount === null ? null : round(92 - $amount / $peak * 80, 2);
        $points = [];
        foreach ($current as $index => $amount) {
            $rows = [['label' => self::rowLabel($period, $period->buckets[$index], $kind), 'value' => self::format($amount, $kind), 'key' => 'line']];
            $rows[] = ['label' => self::rowLabel($period, $period->previous[$index], $kind), 'value' => self::format($previous[$index] ?? null, $kind), 'key' => 'dash'];
            $points[] = ['x' => $x($index), 'y' => $y($amount), 'py' => $y($previous[$index] ?? null), 'rows' => $rows];
        }
        $lines = self::segments($points, 'y');

        return [
            'value' => $value,
            'text' => self::format($value, $kind),
            'delta' => self::delta($period, $kind, $value, $before, $upIsGood),
            'lines' => $lines,
            'previousLines' => self::segments($points, 'py'),
            // A share has no area under it worth filling.
            'area' => $kind === 'rate' || count($lines) !== 1 ? null : '0,100 '.$lines[0].' 100,100',
            'points' => $points,
        ];
    }

    /** @return array{arrow: string, text: string, tone: string, sentence: string}|null */
    private static function delta(OverviewPeriod $period, string $kind, ?int $value, ?int $before, ?bool $upIsGood): ?array
    {
        if ($value === null || $before === null || ($kind !== 'rate' && $before === 0)) {
            return null;
        }
        $change = $kind === 'rate' ? $value - $before : (int) round(($value - $before) / $before * 100);
        $flat = abs($kind === 'rate' ? $value - $before : ($value - $before) / $before * 100) < self::FLAT;
        $tone = $flat || $upIsGood === null || $value === $before ? 'flat' : (($value > $before) === $upIsGood ? 'good' : 'bad');
        $amount = number_format(abs($change)).($kind === 'rate' ? ' pts' : '%');
        $was = self::format($before, $kind);
        $against = $kind === 'stock' ? 'on '.$period->endLabel($period->previous[array_key_last($period->previous)]) : 'in the '.lcfirst($period->previousLabel);
        $sentence = match (true) {
            $value === $before => 'Same as the '.($kind === 'stock' ? 'start of the period' : lcfirst($period->previousLabel)).', '.$was.'.',
            $kind === 'rate' => ($value > $before ? 'Up ' : 'Down ').number_format(abs($change)).' points from '.$was.' '.$against.'.',
            default => ($value > $before ? 'Up ' : 'Down ').$amount.' from '.$was.' '.$against.'.',
        };

        return ['arrow' => $value > $before ? 'arrow-up' : ($value < $before ? 'arrow-down' : 'minus'), 'text' => $amount, 'tone' => $tone, 'sentence' => $sentence];
    }

    /** @param array{from: string, to: string} $bucket */
    private static function rowLabel(OverviewPeriod $period, array $bucket, string $kind): string
    {
        return match ($kind) {
            'stock' => $period->endLabel($bucket),
            'rate' => 'Through '.$period->endLabel($bucket),
            default => $period->bucketLabel($bucket),
        };
    }

    private static function format(?int $value, string $kind): string
    {
        return match (true) {
            $value === null => 'No data',
            $kind === 'rate' => $value.'%',
            default => number_format($value),
        };
    }

    /**
     * SVG point lists, broken where a value is missing.
     *
     * @param  list<array{x: float, y: float|null, py: float|null, rows: list<array{label: string, value: string, key: string}>}>  $points
     * @param  'y'|'py'  $axis
     * @return list<string>
     */
    private static function segments(array $points, string $axis): array
    {
        $segments = [];
        $open = [];
        foreach ($points as $point) {
            if ($point[$axis] === null) {
                $segments[] = $open;
                $open = [];

                continue;
            }
            $open[] = $point['x'].','.$point[$axis];
        }
        $segments[] = $open;

        return array_values(array_map(static fn (array $segment): string => implode(' ', $segment), array_filter($segments, static fn (array $segment): bool => $segment !== [])));
    }
}
