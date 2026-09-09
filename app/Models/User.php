<?php

declare(strict_types=1);

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;
use Laravel\Fortify\TwoFactorAuthenticatable;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property Carbon|null $email_verified_at
 * @property string|null $password
 * @property string|null $role_id
 * @property string|null $employee_id
 * @property bool $is_active
 * @property Carbon|null $last_login_at
 * @property string $full_name
 * @property-read Role|null $role
 * @property-read EmployeeProfile|null $employee
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'email', 'password', 'role_id', 'employee_id', 'is_active'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable implements PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, PasskeyAuthenticatable, TwoFactorAuthenticatable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'last_login_at' => 'datetime',
        ];
    }

    /**
     * The account's role (`UserAccount.role`, prisma/schema.prisma). Nullable:
     * an account can exist unassigned; Access treats that as "Unassigned".
     *
     * @return BelongsTo<Role, $this>
     */
    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class, 'role_id');
    }

    /**
     * The linked employee record (`UserAccount.employee`). Nullable: an
     * account without one owns no rows anywhere (Access::rowVisibility()).
     *
     * @return BelongsTo<EmployeeProfile, $this>
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(EmployeeProfile::class, 'employee_id');
    }

    /**
     * `UserAccount.full_name` maps onto the existing `users.name` — one
     * column, exposed under both names so neither can drift (see the
     * extend_users_table migration, divergence 2).
     *
     * @return Attribute<string, string>
     */
    protected function fullName(): Attribute
    {
        return Attribute::make(
            get: fn (): string => (string) $this->name,
            set: fn (?string $value): array => ['name' => (string) $value],
        );
    }

    /**
     * Get the user's initials
     */
    public function initials(): string
    {
        $initials = Str::initials($this->name, true);

        return Str::length($initials) > 1
            ? Str::substr($initials, 0, 1).Str::substr($initials, -1)
            : $initials;
    }
}
