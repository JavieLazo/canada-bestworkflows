<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;

/**
 * A named set of leave policy rules (LeavePolicy `params`) on Leave Credits
 * settings, copied onto an employee's policy when HR prepares one. Copy
 * only: changing a preset changes no employee's policy.
 *
 * @property string $id
 * @property string $name
 * @property array<string, mixed> $params
 * @property int|null $created_by
 * @property string|null $created_by_name
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable|null $updated_at
 */
class LeavePolicyPreset extends BaseModel
{
    protected $table = 'leave_policy_presets';

    protected $fillable = ['name', 'params'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'params' => 'array',
            'created_by' => 'integer',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
