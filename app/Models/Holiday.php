<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;

/**
 * A paid non-working day, and how payroll should treat it. Unique on `code`
 * AND on `(name, date)`.
 *
 * `schedule` is a `HolidaySchedule`: ONE_TIME falls on that date only, ANNUAL
 * repeats every year — and `holidayOn()` (src/lib/dtr.ts:71-77) matches an
 * ANNUAL holiday on MONTH AND DAY only, comparing `date.slice(5)`. `type` is a
 * `HolidayType` (REGULAR | SPECIAL_NON_WORKING). Both are stored as varchars
 * and cast as plain strings until `app/Enums/HolidaySchedule.php` and
 * `app/Enums/HolidayType.php` exist.
 *
 * `percent` is the percent of the daily rate paid for the day — DECIMAL(5,2)
 * with a default of 100, and the API accepts up to 999.
 *
 * Two uniques: never write this table with `upsert()`, which on MySQL ignores
 * its `$uniqueBy` and would rewrite an existing holiday's code on a
 * `(name, date)` collision.
 *
 * @property string $id
 * @property string $code
 * @property string $name
 * @property CarbonImmutable $date
 * @property string $schedule
 * @property string $type
 * @property float $percent
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable|null $updated_at
 */
class Holiday extends BaseModel
{
    /** @var string */
    protected $table = 'holidays';

    /** @var list<string> */
    protected $fillable = [
        'code',
        'name',
        'date',
        'schedule',
        'type',
        'percent',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'date' => 'immutable_date:Y-m-d',
            'percent' => 'float',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
