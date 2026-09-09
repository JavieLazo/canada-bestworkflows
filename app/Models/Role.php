<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An access-control role: a name, the `all_data` switch, and a set of module
 * grants.
 *
 * `all_data` is the whole of the row-scoping model — an account whose role has
 * it sees every employee's records, and one without it sees only its own
 * linked employee. `is_system` marks the seeded roles that must not be deleted.
 *
 * @property string $id
 * @property string $name
 * @property bool $all_data
 * @property bool $is_system
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Collection<int, RoleModuleGrant> $grants
 * @property-read Collection<int, User> $accounts
 */
class Role extends BaseModel
{
    /** @var string */
    protected $table = 'roles';

    /** @var list<string> */
    protected $fillable = [
        'name',
        'all_data',
        'is_system',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'all_data' => 'boolean',
            'is_system' => 'boolean',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /** @return HasMany<RoleModuleGrant, $this> */
    public function grants(): HasMany
    {
        return $this->hasMany(RoleModuleGrant::class, 'role_id');
    }

    /**
     * `UserAccount[]` in the source. The account model is the starter kit's
     * `User`, which owns the `user_accounts` table.
     *
     * @return HasMany<User, $this>
     */
    public function accounts(): HasMany
    {
        return $this->hasMany(User::class, 'role_id');
    }
}
