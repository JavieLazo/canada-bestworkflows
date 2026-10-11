<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;

/**
 * One account's choices about what Community and Chat show about them.
 *
 * Only people who changed something have a row here. Anyone without one gets
 * the defaults: job title shown, birthday and work anniversary hidden.
 *
 * `user_id` is always the session's own account, so it is not mass-assignable.
 *
 * @property string $id
 * @property int $user_id
 * @property bool $show_position
 * @property bool $show_birthday
 * @property bool $show_anniversary
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable|null $updated_at
 */
class SocialPreference extends BaseModel
{
    /** @var string */
    protected $table = 'social_preferences';

    /** @var list<string> */
    protected $fillable = [
        'show_position',
        'show_birthday',
        'show_anniversary',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'show_position' => 'boolean',
            'show_birthday' => 'boolean',
            'show_anniversary' => 'boolean',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
