<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A bearer credential for one kiosk device.
 *
 * `token_hash` is SHA-256 of the secret the phone holds. The secret itself
 * is never stored. `ability` is `admin` (register faces) or `kiosk` (sync
 * punches after the administrator has signed out).
 *
 * @property string $id
 * @property int $user_id
 * @property string $device_id
 * @property string $ability
 * @property string $token_hash
 * @property CarbonImmutable|null $last_used_at
 * @property CarbonImmutable|null $revoked_at
 * @property CarbonImmutable $created_at
 * @property-read User|null $user
 */
class EdtrMobileToken extends BaseModel
{
    public const string ABILITY_ADMIN = 'admin';

    public const string ABILITY_KIOSK = 'kiosk';

    public const UPDATED_AT = null;

    /** @var string */
    protected $table = 'edtr_mobile_tokens';

    /** @var list<string> */
    protected $fillable = [
        'user_id',
        'device_id',
        'ability',
        'token_hash',
    ];

    /** @var list<string> */
    protected $hidden = [
        'token_hash',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'last_used_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
