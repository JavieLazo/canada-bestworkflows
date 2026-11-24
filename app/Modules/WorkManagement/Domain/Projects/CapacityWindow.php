<?php

declare(strict_types=1);

namespace App\Modules\WorkManagement\Domain\Projects;

use App\Modules\WorkManagement\Domain\Tasks\TaskSchedule;
use App\Support\LocalClock;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

final class CapacityWindow
{
    public static function validateWeek(string $week): void
    {
        if (! TaskSchedule::isDateKey($week) || $week < '2000-01-03' || $week > '2099-12-31'
            || CarbonImmutable::parse($week)->dayOfWeek !== CarbonImmutable::MONDAY) {
            throw ValidationException::withMessages(['week' => 'Choose a Monday between 2000 and 2099.']);
        }
    }

    public static function from(string $from): string
    {
        try {
            self::validateWeek($from);
        } catch (ValidationException) {
            return CarbonImmutable::parse(LocalClock::todayKey())->startOfWeek(CarbonImmutable::MONDAY)->format('Y-m-d');
        }

        return min($from, CarbonImmutable::parse('2099-12-31')->startOfWeek(CarbonImmutable::MONDAY)->subWeeks(3)->format('Y-m-d'));
    }

    /** @return list<string> */
    public static function weeks(string $from): array
    {
        $start = CarbonImmutable::parse(self::from($from));

        return array_map(static fn (int $week): string => $start->addWeeks($week)->format('Y-m-d'), range(0, 3));
    }

    public static function move(string $from, int $direction): string
    {
        $next = CarbonImmutable::parse(self::from($from))->addWeeks(max(-1, min(1, $direction)) * 4)->format('Y-m-d');

        return self::from(max('2000-01-03', $next));
    }
}
