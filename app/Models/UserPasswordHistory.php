<?php

declare(strict_types=1);

namespace App\Models;

/**
 * Retains replaced credential hashes privately so an account cannot reuse a previous password.
 *
 * @property int $user_id
 * @property string $password_hash
 */
class UserPasswordHistory extends BaseModel
{
    public const UPDATED_AT = null;

    /** @var list<string> */
    protected $hidden = ['password_hash'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['created_at' => 'immutable_datetime'];
    }
}
