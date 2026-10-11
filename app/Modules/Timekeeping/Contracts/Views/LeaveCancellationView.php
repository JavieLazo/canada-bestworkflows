<?php

declare(strict_types=1);

namespace App\Modules\Timekeeping\Contracts\Views;

use App\Modules\IdentityAccess\Contracts\AccountLabel;
use App\Platform\ReadModels\Record;
use Carbon\CarbonImmutable;

/**
 * Cancellation evidence is readable without exposing persistence relationships.
 *
 * @property-read string $id
 * @property-read CarbonImmutable $date
 * @property-read int $cancelled_by
 * @property-read CarbonImmutable $cancelled_at
 * @property-read string $reason
 * @property-read AccountLabel|null $canceller
 */
final readonly class LeaveCancellationView extends Record {}
