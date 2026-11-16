<?php

declare(strict_types=1);

namespace App\Modules\WorkManagement\Contracts\Views;

use App\Platform\ReadModels\Record;
use Carbon\CarbonImmutable;

/**
 * @property-read string $id
 * @property-read string $project_id
 * @property-read string $currency
 * @property-read bool $can_change_currency
 * @property-read string|null $opening_on
 * @property-read int|null $opening_minor
 * @property-read string|null $opening_reference
 * @property-read string|null $opening_notes
 * @property-read int $opening_revision
 * @property-read list<array<string, mixed>>|null $opening_history
 * @property-read CarbonImmutable $created_at
 * @property-read CarbonImmutable|null $updated_at
 */
final readonly class FinanceSettingsView extends Record {}
