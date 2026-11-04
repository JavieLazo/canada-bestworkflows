<?php

declare(strict_types=1);

namespace App\Modules\Timekeeping\Contracts\Views;

use App\Platform\ReadModels\Record;
use Carbon\CarbonImmutable;

/**
 * One version of the minutes-to-days conversion table, as the settings
 * page and the ledger read it.
 *
 * @property-read string $id
 * @property-read CarbonImmutable $effective_from
 * @property-read float $hours_per_day
 * @property-read int $decimals
 * @property-read string $rounding
 * @property-read array{minutes: array<int|string, string>, hours: array<int|string, string>} $rows
 * @property-read string|null $reference
 * @property-read string $status  DRAFT | APPROVED
 * @property-read int|null $created_by
 * @property-read string|null $created_by_name
 * @property-read int|null $approved_by
 * @property-read string|null $approved_by_name
 * @property-read CarbonImmutable|null $approved_at
 * @property-read CarbonImmutable $created_at
 */
final readonly class LeaveConversionTableView extends Record {}
