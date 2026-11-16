<?php

declare(strict_types=1);

namespace App\Modules\WorkManagement\Application;

use App\Modules\WorkManagement\Domain\Tasks\TaskSchedule;
use Carbon\CarbonImmutable;

/** Calendar geometry stays bounded even when a project spans years. */
final readonly class TaskTimeline
{
    public const SCALES = [7 => '1 week', 14 => '2 weeks', 28 => '4 weeks', 84 => '12 weeks', 168 => '24 weeks', 364 => '52 weeks'];

    public string $through;

    private function __construct(public string $from, public int $days, public string $today)
    {
        $this->through = self::day($from)->addDays($days - 1)->format('Y-m-d');
    }

    public static function window(string $from, string $scale, string $today): self
    {
        $days = in_array($scale, array_map('strval', array_keys(self::SCALES)), true) ? (int) $scale : 28;
        $start = TaskSchedule::isDateKey($from) ? self::day($from) : self::day($today)->startOfWeek();

        return self::bounded($start, $days, $today);
    }

    public function shifted(int $direction): self
    {
        return self::bounded(self::day($this->from)->addDays(($direction < 0 ? -1 : 1) * $this->days), $this->days, $this->today);
    }

    public function around(string $date): self
    {
        return TaskSchedule::isDateKey($date)
            ? self::bounded(self::day($date)->startOfWeek()->subWeek(), $this->days, $this->today)
            : $this;
    }

    public function zoomed(int $direction, string $anchor = ''): self
    {
        $scales = array_keys(self::SCALES);
        $index = array_search($this->days, $scales, true);
        $days = $direction === 0 ? 28 : $scales[max(0, min(count($scales) - 1, ($index === false ? 2 : $index) + ($direction > 0 ? -1 : 1)))];
        if ($days === $this->days) {
            return $this;
        }
        $middle = TaskSchedule::isDateKey($anchor) ? self::day($anchor) : self::day($this->from)->addDays(intdiv($this->days, 2));

        return self::bounded($middle->subDays(intdiv($days, 2))->startOfWeek(), $days, $this->today);
    }

    /** @return list<array{label: string, span: int}> */
    public function periods(): array
    {
        $groups = [];
        foreach ($this->calendar() as $index => $day) {
            $key = $this->days >= 168 ? substr($day['key'], 0, 7) : (string) intdiv($index, 7);
            if (! isset($groups[$key])) {
                $groups[$key] = ['label' => self::day($day['key'])->format($this->days >= 168 ? 'M Y' : 'M j'), 'span' => 0];
            }
            $groups[$key]['span']++;
        }

        return array_values($groups);
    }

    public function label(): string
    {
        return self::day($this->from)->format('M j').' – '.self::day($this->through)->format('M j, Y');
    }

    public function todayOffset(): int
    {
        return (int) self::day($this->from)->diffInDays(self::day($this->today), false);
    }

    /** @return list<array{key: string, day: string, label: string, weekend: bool, today: bool}> */
    public function calendar(): array
    {
        $days = [];
        $start = self::day($this->from);
        for ($i = 0; $i < $this->days; $i++) {
            $date = $start->addDays($i);
            $days[] = ['key' => $date->format('Y-m-d'), 'day' => $date->format('j'), 'label' => $date->format('D, M j'), 'weekend' => $date->isWeekend(), 'today' => $date->format('Y-m-d') === $this->today];
        }

        return $days;
    }

    /** @return array{kind: string, offset: int, span: int, before: bool, after: bool, focus: string|null} */
    public function position(?string $start, ?string $due): array
    {
        $empty = ['kind' => 'none', 'offset' => 0, 'span' => 0, 'before' => false, 'after' => false, 'focus' => null];
        if ($start === null && $due === null) {
            return $empty;
        }
        if (($start !== null && ! TaskSchedule::isDateKey($start)) || ($due !== null && ! TaskSchedule::isDateKey($due)) || ($start !== null && $due !== null && $start > $due)) {
            return array_replace($empty, ['kind' => 'invalid']);
        }
        $first = $start ?? $due;
        $last = $due ?? $start;
        if ($last < $this->from) {
            return array_replace($empty, ['kind' => 'before', 'focus' => $first]);
        }
        if ($first > $this->through) {
            return array_replace($empty, ['kind' => 'after', 'focus' => $first]);
        }
        $left = max($first, $this->from);
        $right = min($last, $this->through);

        return ['kind' => $start !== null && $due !== null ? 'range' : ($start !== null ? 'start' : 'due'),
            'offset' => (int) self::day($this->from)->diffInDays(self::day($left)),
            'span' => (int) self::day($left)->diffInDays(self::day($right)) + 1,
            'before' => $first < $this->from, 'after' => $last > $this->through, 'focus' => $first];
    }

    private static function bounded(CarbonImmutable $start, int $days, string $today): self
    {
        $minimum = self::day('0001-01-01');
        $maximum = self::day('9999-12-31')->subDays($days - 1);

        return new self($start->max($minimum)->min($maximum)->format('Y-m-d'), $days, $today);
    }

    private static function day(string $date): CarbonImmutable
    {
        return new CarbonImmutable($date.' 00:00:00', 'UTC');
    }
}
