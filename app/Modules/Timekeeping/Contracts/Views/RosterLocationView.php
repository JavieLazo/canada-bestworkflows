<?php

declare(strict_types=1);

namespace App\Modules\Timekeeping\Contracts\Views;

use App\Platform\ReadModels\Record;
use Carbon\CarbonImmutable;

/**
 * Detached roster_locations data — one weekday's place for one employee —
 * plus the site's name, which the owner adds from OrganizationDirectory so
 * no reader has to know the site model. No persistence, no lazy relations.
 *
 * @property-read string $id
 * @property-read string $employee_id
 * @property-read int $weekday 0 Sunday … 6 Saturday
 * @property-read string $kind ONSITE | WFH
 * @property-read string|null $site_id
 * @property-read string|null $site_name Null on a WFH row, or when the site no longer resolves
 * @property-read CarbonImmutable|null $effective_from  null = since before history was kept
 * @property-read string|null $change_id
 * @property-read CarbonImmutable $created_at
 * @property-read CarbonImmutable|null $updated_at
 */
final readonly class RosterLocationView extends Record {}
