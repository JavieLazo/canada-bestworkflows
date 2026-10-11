<?php

declare(strict_types=1);

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Modules\IdentityAccess\Contracts\AccountLabel;
use App\Modules\Workforce\Contracts\EmployeeNames;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
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
#[Fillable(['email', 'password', 'role_id', 'employee_id', 'is_active'])]
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
     * Names belong to the linked employee. Ignore legacy account-name inputs so
     * older integrations cannot create a second personal record.
     *
     * @return Attribute<string, never>
     */
    protected function name(): Attribute
    {
        return Attribute::make(
            get: function (): string {
                $employeeId = $this->employee_id;
                $names = $employeeId === null ? [] : app(EmployeeNames::class)->labels([$employeeId]);

                return $names[$employeeId ?? ''] ?? AccountLabel::unlinked($this->getKey());
            },
            set: static fn (mixed $value): array => [],
        );
    }

    /** @return Attribute<string, never> */
    protected function fullName(): Attribute
    {
        return Attribute::make(
            get: fn (): string => $this->name,
            set: static fn (mixed $value): array => [],
        );
    }

    /** The compatibility column accepts no personal data while awaiting its drop. */
    protected static function booted(): void
    {
        static::creating(function (self $user): void {
            if (Schema::hasColumn('users', 'name')) {
                $attributes = $user->getAttributes();
                $attributes['name'] = 'Account';
                $user->setRawAttributes($attributes);
            }
        });
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
