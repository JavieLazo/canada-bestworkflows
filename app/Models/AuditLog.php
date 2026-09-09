<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;

/**
 * Append-only audit trail.
 *
 * `actor_id` carries NO foreign key and `actor_name` is denormalised, both on
 * purpose: the trail has to stay readable after the account that wrote it is
 * gone. That is why no `belongsTo` is declared here — adding one would be a
 * constraint the reference deliberately does not have.
 *
 * Both actor columns come from the session and neither is mass-assignable;
 * `App\Support\Audit` sets them on the model directly.
 *
 * `action` is one of the 43 literals in `src/lib/audit.ts` plus the computed
 * forms; `entity`/`entity_id` name the row it happened to, and `details` is the
 * human sentence the UI renders.
 *
 * @property string $id
 * @property int|null $actor_id
 * @property string $actor_name
 * @property string $action
 * @property string|null $entity
 * @property string|null $entity_id
 * @property string|null $details
 * @property CarbonImmutable $created_at
 */
class AuditLog extends BaseModel
{
    /** Append-only: created_at is filled on insert, updated_at never written. */
    public const UPDATED_AT = null;

    /** @var string */
    protected $table = 'audit_logs';

    /** @var list<string> */
    protected $fillable = [
        'action',
        'entity',
        'entity_id',
        'details',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'created_at' => 'immutable_datetime',
        ];
    }
}
