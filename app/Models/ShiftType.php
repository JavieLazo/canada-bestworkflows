<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;

/**
 * A shift pattern that can be assigned to an employee (Shifting Settings).
 *
 * Times are `HH:MM:SS` strings for the same reason as on `Shift`, and
 * `work_days` is the same 0-6 JSON list. The defaults differ from `Shift`'s on
 * purpose: a pattern defaults to a 60-minute break that must be punched, while
 * an employee's own shift row defaults to 0 and not punched.
 *
 * `mode` is a `ShiftMode` (FIXED | FLEXIBLE) — FIXED clocks against
 * start/end, FLEXIBLE only owes `required_hours`. Cast as a plain string until
 * `app/Enums/ShiftMode.php` exists.
 *
 * `code` and `name` are BOTH unique: never write this table with `upsert()`,
 * whose `$uniqueBy` MySQL ignores.
 *
 * @property string $id
 * @property string $code
 * @property string $name
 * @property string $mode
 * @property list<int> $work_days
 * @property string|null $start_time
 * @property string|null $end_time
 * @property int $grace_minutes
 * @property float|null $required_hours
 * @property string|null $break_start
 * @property string|null $break_end
 * @property int $break_minutes
 * @property bool $requires_break_punch
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable|null $updated_at
 */
class ShiftType extends BaseModel
{
    /** @var string */
    protected $table = 'shift_types';

    /** @var list<string> */
    protected $fillable = [
        'code',
        'name',
        'mode',
        'work_days',
        'start_time',
        'end_time',
        'grace_minutes',
        'required_hours',
        'break_start',
        'break_end',
        'break_minutes',
        'requires_break_punch',
    ];

    /**
     * MySQL cannot carry a literal default on a JSON column.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'work_days' => '[1,2,3,4,5]',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'work_days' => 'array',
            'grace_minutes' => 'integer',
            'required_hours' => 'float',
            'break_minutes' => 'integer',
            'requires_break_punch' => 'boolean',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
