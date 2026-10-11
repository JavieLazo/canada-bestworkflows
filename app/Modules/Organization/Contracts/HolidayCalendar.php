<?php

declare(strict_types=1);

namespace App\Modules\Organization\Contracts;

use App\Domain\Attendance\HolidaySetting;

/** Organization calendar facts shared by attendance and pay calculations. */
interface HolidayCalendar
{
    /** @return list<HolidaySetting> */
    public function all(): array;
}
