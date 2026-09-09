<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One (role, module) grant. Unique on `(role_id, module)`.
 *
 * `module` is a `ModuleCode` — the twelve-value enum in `src/lib/hris.ts`
 * (EDTR, ROSTER, LEAVES, PROJECTS, PAYROLL, EMPLOYEES, RECORDS, USERS, AUDIT,
 * PORTAL, TASKS, PROFILE), whose ORDER is load-bearing for sidebar order and
 * landing precedence. It is stored as a varchar rather than a MySQL ENUM,
 * which sorts by declaration ordinal and needs an ALTER TABLE to extend.
 *
 * The column is cast as a plain string here rather than to a backed enum: at
 * the time of writing `app/Enums/ModuleCode.php` does not exist and is owned by
 * another concern. When it lands, change the cast to `ModuleCode::class` — the
 * stored values are already identical to the enum's backing strings.
 *
 * This is one of only two tables with NEITHER created_at NOR updated_at.
 *
 * @property string $id
 * @property string $role_id
 * @property string $module
 * @property-read Role|null $role
 */
class RoleModuleGrant extends BaseModel
{
    /** No created_at and no updated_at on this table. */
    public $timestamps = false;

    /** @var string */
    protected $table = 'role_module_grants';

    /** @var list<string> */
    protected $fillable = [
        'role_id',
        'module',
    ];

    /** @return BelongsTo<Role, $this> */
    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class, 'role_id');
    }
}
