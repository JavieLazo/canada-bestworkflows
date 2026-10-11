<?php

declare(strict_types=1);

namespace App\Platform\ReadModels;

use Carbon\CarbonImmutable;

/**
 * Detached AuditLog data, with no persistence or lazy relationships.
 *
 * @property-read string $id
 * @property-read int|null $actor_id
 * @property-read string $actor_name
 * @property-read string $action
 * @property-read string|null $entity
 * @property-read string|null $entity_id
 * @property-read string|null $details
 * @property-read CarbonImmutable $created_at
 */
final readonly class AuditLogView extends Record {}
