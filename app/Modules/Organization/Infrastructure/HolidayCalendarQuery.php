<?php

declare(strict_types=1);

namespace App\Modules\Organization\Infrastructure;

use App\Domain\Attendance\HolidaySetting;
use App\Models\Holiday;
use App\Modules\Organization\Contracts\HolidayCalendar;

/** Returns domain values instead of leaking a holiday query builder. */
final class HolidayCalendarQuery implements HolidayCalendar
{
    public function all(): array
    {
        return HolidaySetting::listFromArrays(Holiday::query()->get()->map->toArray()->all());
    }
}
