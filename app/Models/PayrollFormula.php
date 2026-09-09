<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;

/**
 * A payroll formula and the numbers it is computed from. Unique on `code` AND
 * on `key`.
 *
 * The SHAPE of each calculation lives in code, keyed by `key` (OT, SIL, NSD,
 * REST_DAY); everything adjustable is `params` — a
 * `[{ key, label, value }]` array. `expression` is DISPLAY TEXT ONLY: payroll
 * must never evaluate a string typed by a user. Port that restriction as
 * written — nothing may parse this column.
 *
 * `params` is the one JSON column the source has. Its `[]` default lives in
 * `$attributes` because MySQL before 8.0.13 and MariaDB before 10.2.1 reject a
 * literal default on a JSON column.
 *
 * Two uniques: never write this table with `upsert()`.
 *
 * @property string $id
 * @property string $code
 * @property string $key
 * @property string $name
 * @property string $expression
 * @property list<array{key: string, label: string, value: float}> $params
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable|null $updated_at
 */
class PayrollFormula extends BaseModel
{
    /** @var string */
    protected $table = 'payroll_formulas';

    /** @var list<string> */
    protected $fillable = [
        'code',
        'key',
        'name',
        'expression',
        'params',
    ];

    /**
     * MySQL cannot carry a literal default on a JSON column.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'params' => '[]',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'params' => 'array',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
