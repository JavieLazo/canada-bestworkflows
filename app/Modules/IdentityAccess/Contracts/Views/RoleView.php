<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Contracts\Views;

use App\Platform\ReadModels\Record;
use Carbon\CarbonImmutable;

/**
 * Detached Role data, with no persistence or lazy relationships.
 *
 * @property-read string $id
 * @property-read string $name
 * @property-read bool $all_data
 * @property-read bool $is_system
 * @property-read CarbonImmutable $created_at
 * @property-read CarbonImmutable|null $updated_at
 */
final readonly class RoleView extends Record {}
