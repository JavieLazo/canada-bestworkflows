<?php

declare(strict_types=1);

namespace App\Modules\Timekeeping\Contracts\Views;

use App\Platform\ReadModels\Record;
use Carbon\CarbonImmutable;

/**
 * A leave type's rule as Leave Settings reads it, in the version in force
 * today.
 *
 * @property-read string $id
 * @property-read string $scope  the leave type's token
 * @property-read CarbonImmutable $effective_from  the day the rule took this shape
 * @property-read array<string, mixed> $params
 * @property-read string $summary  what the rule says, in one line
 */
final readonly class LeaveRuleView extends Record {}
