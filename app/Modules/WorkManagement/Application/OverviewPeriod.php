<?php

declare(strict_types=1);

namespace App\Modules\WorkManagement\Application;

use App\Support\LocalClock;
use Carbon\CarbonImmutable;

/**
 * The date range the overview's trend cards read, split into the buckets each
 * line draws, plus the same buckets one period earlier for the dashed line.
 * Every date is a local calendar day, and both ends of a bucket are included.
 *
 * Days and weeks compare with the stretch right before. Months compare with
 * the same months one period earlier, so "Nov 1 to 13" always sits against
 * another "Nov 1 to 13" and a short month never looks like a drop.
 */
final readonly class OverviewPeriod
{
    public const DEFAULT = '30d';

    public const PRESETS = ['7d' => 'Last 7 days', '30d' => 'Last 30 days', '12w' => 'Last 12 weeks', '12m' => 'Last 12 months'];

    private const PREVIOUS = ['7d' => 'Previous 7 days', '30d' => 'Previous 30 days', '12w' => 'Previous 12 weeks', '12m' => 'Previous 12 months'];

    public const MAX_DAYS = 366;

    /**
     * @param  'day'|'week'|'month'  $unit
     * @param  non-empty-list<array{from: string, to: string}>  $buckets
     * @param  non-empty-list<array{from: string, to: string}>  $previous
     */
    private function __construct(
        public string $key,
        public string $label,
        public string $previousLabel,
        public string $unit,
        public array $buckets,
        public array $previous,
        public string $today,
    ) {}

    /** A preset, a valid custom range, or the default when the input is neither. */
    public static function resolve(string $range, string $from, string $to, string $today): self
    {
        if ($range === 'custom' && self::customError($from, $to, $today) === null) {
            return self::custom($from, $to, $today);
        }
        $end = self::day($today);

        return match ($range) {
            '7d' => self::byDays('7d', $end->subDays(6), $end, 1, $today),
            '12w' => self::byDays('12w', $end->subDays(83), $end, 7, $today),
            '12m' => self::byMonths('12m', $end->startOfMonth()->subMonths(11), $end, $today),
            default => self::byDays('30d', $end->subDays(29), $end, 1, $today),
        };
    }

    /** Why a typed range can't be used, in the words the picker shows, or null when it can. */
    public static function customError(string $from, string $to, string $today): ?string
    {
        if (! self::isDate($from) || ! self::isDate($to)) {
            return 'Pick both dates.';
        }
        if ($from > $to) {
            return 'The first date has to come before the second.';
        }
        if ($to > $today) {
            return 'Pick dates up to today.';
        }
        if (self::day($from)->diffInDays(self::day($to)) + 1 > self::MAX_DAYS) {
            return 'Pick a range of '.self::MAX_DAYS.' days or fewer.';
        }

        return null;
    }

    /** Days for a short range, weeks for a medium one, months beyond that. */
    private static function custom(string $from, string $to, string $today): self
    {
        [$start, $end] = [self::day($from), self::day($to)];
        $days = (int) $start->diffInDays($end) + 1;

        return match (true) {
            $days <= 62 => self::byDays('custom', $start, $end, 1, $today),
            $days <= 182 => self::byDays('custom', $start, $end, 7, $today),
            default => self::byMonths('custom', $start, $end, $today),
        };
    }

    /** Buckets of $size days counted back from the end, so the last bucket is always whole. */
    private static function byDays(string $key, CarbonImmutable $start, CarbonImmutable $end, int $size, string $today): self
    {
        $buckets = [];
        for ($to = $end; $to >= $start; $to = $to->subDays($size)) {
            $from = $to->subDays($size - 1);
            $buckets[] = ['from' => ($from < $start ? $start : $from)->format('Y-m-d'), 'to' => $to->format('Y-m-d')];
        }
        $buckets = array_reverse($buckets);
        if ($buckets === []) {
            throw new \LogicException('A period covers at least one day.');
        }
        $span = (int) $start->diffInDays($end) + 1;
        $previous = array_map(static fn (array $bucket): array => [
            'from' => self::day($bucket['from'])->subDays($span)->format('Y-m-d'),
            'to' => self::day($bucket['to'])->subDays($span)->format('Y-m-d'),
        ], $buckets);

        return new self($key, self::PRESETS[$key] ?? self::rangeText($start, $end, $today), self::PREVIOUS[$key] ?? 'Previous '.$span.' days', $size === 1 ? 'day' : 'week', $buckets, $previous, $today);
    }

    /** Calendar months, the first and last trimmed to the range. */
    private static function byMonths(string $key, CarbonImmutable $start, CarbonImmutable $end, string $today): self
    {
        $buckets = [];
        for ($month = $start->startOfMonth(); $month <= $end; $month = $month->addMonthNoOverflow()) {
            $from = $month < $start ? $start : $month;
            $to = $month->endOfMonth()->startOfDay();
            $buckets[] = ['from' => $from->format('Y-m-d'), 'to' => ($to > $end ? $end : $to)->format('Y-m-d')];
        }
        if ($buckets === []) {
            throw new \LogicException('A period covers at least one day.');
        }
        $count = count($buckets);
        $previous = array_map(static fn (array $bucket): array => [
            'from' => self::day($bucket['from'])->subMonthsNoOverflow($count)->format('Y-m-d'),
            'to' => self::day($bucket['to'])->subMonthsNoOverflow($count)->format('Y-m-d'),
        ], $buckets);

        return new self($key, self::PRESETS[$key] ?? self::rangeText($start, $end, $today), self::PREVIOUS[$key] ?? 'Previous '.$count.' months', 'month', $buckets, $previous, $today);
    }

    /**
     * How a bucket reads in a hover card: "Wed, Oct 9", "Oct 3 to 9", "Oct 2026" or a trimmed "Nov 1 to 13, 2026".
     *
     * @param  array{from: string, to: string}  $bucket
     */
    public function bucketLabel(array $bucket): string
    {
        [$from, $to] = [self::day($bucket['from']), self::day($bucket['to'])];
        if ($this->unit === 'day') {
            return $from->format('D, M j').$this->year($from, false);
        }
        if ($this->unit === 'month' && $from->day === 1 && $to->isSameDay($to->endOfMonth())) {
            return $from->format('M Y');
        }

        return self::rangeText($from, $to, $this->today, $this->unit === 'month');
    }

    /**
     * The day a stock or a running total is read at: "Oct 9", with the year when it isn't this one.
     *
     * @param  array{from: string, to: string}  $bucket
     */
    public function endLabel(array $bucket): string
    {
        $to = self::day($bucket['to']);

        return $to->format('M j').$this->year($to, $this->unit === 'month');
    }

    /**
     * The UTC instants a bucket covers, as the strings timestamps are stored
     * in, end exclusive.
     *
     * @param  array{from: string, to: string}  $bucket
     * @return array{string, string}
     */
    public static function instants(array $bucket): array
    {
        return [
            self::day($bucket['from'])->utc()->format('Y-m-d H:i:s'),
            self::day($bucket['to'])->addDay()->utc()->format('Y-m-d H:i:s'),
        ];
    }

    private static function rangeText(CarbonImmutable $from, CarbonImmutable $to, string $today, bool $forceYear = false): string
    {
        $year = $forceYear || $to->format('Y') !== substr($today, 0, 4) ? ', '.$to->format('Y') : '';
        if ($from->isSameDay($to)) {
            return $from->format('M j').$year;
        }

        return $from->format('M j').' to '.($from->isSameMonth($to) ? $to->format('j') : $to->format('M j')).$year;
    }

    private function year(CarbonImmutable $day, bool $force): string
    {
        return $force || $day->format('Y') !== substr($this->today, 0, 4) ? ', '.$day->format('Y') : '';
    }

    private static function day(string $value): CarbonImmutable
    {
        return CarbonImmutable::parse($value, LocalClock::zone())->startOfDay();
    }

    /** A real calendar day in the stored spelling, so "2026-02-30" is refused rather than rolled over. */
    private static function isDate(string $value): bool
    {
        $day = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $day !== false && $day->format('Y-m-d') === $value;
    }
}
