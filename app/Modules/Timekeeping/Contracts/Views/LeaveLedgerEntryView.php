<?php

declare(strict_types=1);

namespace App\Modules\Timekeeping\Contracts\Views;

use App\Platform\ReadModels\Record;
use Carbon\CarbonImmutable;

/**
 * Detached credit history, paginated before projection. Actor identities and
 * writable persistence never reach the ledger's display rows.
 *
 * @property-read string $id
 * @property-read string $request_type
 * @property-read string $label
 * @property-read string $kind
 * @property-read float $days
 * @property-read string|null $note
 * @property-read int|null $source_year
 * @property-read CarbonImmutable $created_at
 * @property-read CarbonImmutable $recorded_at
 */
final readonly class LeaveLedgerEntryView extends Record {}
